<?php
/**
 * AJAX endpoints for background AI generation jobs.
 *
 * Contract with the composer:
 *  - enqueue returns fast with a public job token (never runs the model).
 *  - status is a lightweight poll; it may re-dispatch an unclaimed job but
 *    never executes the model call itself. If background execution appears
 *    unavailable it says so, and the composer offers an explicit
 *    run-in-foreground fallback (the legacy synchronous endpoints).
 *  - Poll responses never include API keys or full prompts.
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler calls guard(), which runs check_ajax_referer() before any input is read; the sniff cannot trace through the shared helper.
// phpcs:disable WordPress.WP.Capabilities.Unknown -- edit_bcsend_campaigns is this plugin's custom capability, registered in Bcsend_Activator.

/**
 * Class Bcsend_Ajax_Ai_Jobs
 */
class Bcsend_Ajax_Ai_Jobs {

	/**
	 * Age in seconds after which a still-queued job is re-dispatched by polls.
	 */
	const REDISPATCH_AFTER = 15;

	/**
	 * Age in seconds after which a still-queued job is reported as
	 * "background processing unavailable" so the UI can offer foreground.
	 */
	const BACKGROUND_UNAVAILABLE_AFTER = 45;

	/**
	 * Register AJAX endpoints.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_bcsend_ai_job_enqueue', array( $this, 'ajax_enqueue' ) );
		add_action( 'wp_ajax_bcsend_ai_job_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_bcsend_ai_job_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_bcsend_ai_job_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_bcsend_ai_job_dismiss', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * AJAX: mark a completed job's result as delivered (or declined) so it is
	 * not offered again on future composer loads.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		$this->guard();

		$job = $this->load_job_from_request();

		Bcsend_Ai_Jobs::supersede( (int) $job->id );
		wp_send_json_success( array( 'status' => 'superseded' ) );
	}

	/**
	 * Verify nonce + capability for all job endpoints.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}
	}

	/**
	 * Check the current user may see a job.
	 *
	 * @param object $job Job row.
	 * @return bool
	 */
	private function user_owns_job( $job ) {
		return get_current_user_id() === (int) $job->user_id || current_user_can( 'manage_options' );
	}

	/**
	 * AJAX: create a job and dispatch the background worker.
	 *
	 * @return void
	 */
	public function ajax_enqueue() {
		$this->guard();

		$job_type = isset( $_POST['job_type'] ) ? sanitize_key( wp_unslash( $_POST['job_type'] ) ) : '';

		if ( ! in_array( $job_type, array( 'campaign', 'html', 'push', 'social' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown generation type.', 'beacon-campaign-sender' ) ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		// Every generation type requires a saved campaign: jobs must belong to
		// a stable campaign ID, never float on the user (a floating job could
		// resurface in an unrelated new composer session). The composer
		// auto-saves a draft before enqueueing, so users never see this.
		if ( $campaign_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Save the campaign draft before generating.', 'beacon-campaign-sender' ) ) );
		}

		// The job must belong to a real, still-generatable campaign. Without
		// this, a stale tab or hand-crafted request runs a real, billed
		// generation for a result that can never be applied - and occupies
		// the one-active-job slot for an ID that may be created later. The
		// worker re-checks for three job types; this fails all four types
		// fast, before any job row or provider spend exists.
		$campaign = Bcsend_AI_Service::get_draft_campaign( $campaign_id );

		if ( is_wp_error( $campaign ) ) {
			wp_send_json_error( array( 'message' => $campaign->get_error_message() ) );
		}

		$input = $this->collect_input( $job_type, $campaign_id );

		if ( is_wp_error( $input ) ) {
			wp_send_json_error( array( 'message' => $input->get_error_message() ) );
		}

		// Atomic duplicate lockout: the check-then-insert below must not race
		// against a second tab or a double submit, so it runs under a MySQL
		// named lock scoped to this campaign + generation type.
		global $wpdb;
		$mutex_name = 'bcsend_enqueue_' . $campaign_id . '_' . $job_type;
		$mutex_held = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $mutex_name ) );

		if ( 1 !== $mutex_held ) {
			wp_send_json_error( array( 'message' => __( 'Another generation request is being processed. Please try again.', 'beacon-campaign-sender' ) ) );
		}

		$release_mutex = static function () use ( $wpdb, $mutex_name ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_name ) );
		};

		// One active job per campaign/type.
		$existing = Bcsend_Ai_Jobs::find_active( $campaign_id, $job_type, get_current_user_id() );

		// The liveness sweeps normally run from the owner's status polls. If
		// the owner walked away after a worker died, this row would block the
		// campaign for every other author forever — so run the same
		// billing-safe sweeps on the blocker before honoring it. A healthy
		// job is untouched (its lease/heartbeat proves it is alive) and still
		// blocks below, as it should.
		if ( $existing ) {
			if ( 'openai' === $existing->provider && ! empty( $existing->provider_job_id ) ) {
				Bcsend_Ai_Job_Runner::recover_provider_result( $existing );
			} else {
				Bcsend_Ai_Jobs::sweep_uncertain( (int) $existing->id );
			}

			if ( Bcsend_Ai_Jobs::requeue_stale_dispatch( (int) $existing->id ) ) {
				$this->throttled_dispatch( Bcsend_Ai_Jobs::get( (int) $existing->id ) );
			}

			$existing = Bcsend_Ai_Jobs::find_active( $campaign_id, $job_type, get_current_user_id() );
		}

		if ( $existing ) {
			$release_mutex();

			// Another author's running job blocks a duplicate without
			// exposing its token or contents.
			if ( ! $this->user_owns_job( $existing ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Another user already has a generation running for this campaign. Please try again once it finishes.', 'beacon-campaign-sender' ),
					)
				);
			}

			// Returning the owner's existing job lets a second tab resume it.
			// If this tab's inputs differ from what that job was started with,
			// the composer must offer the result for review, never auto-apply.
			wp_send_json_success(
				array(
					'job'           => $existing->public_token,
					'status'        => $existing->status,
					'existing'      => true,
					'input_matches' => hash_equals( (string) $existing->input_hash, hash( 'sha256', (string) wp_json_encode( $input ) ) ),
				)
			);
		}

		Bcsend_Ai_Jobs::purge_old();

		$settings = Bcsend_Settings::get_settings();
		$provider = Bcsend_AI_Service::get_provider( $settings );

		$job = Bcsend_Ai_Jobs::create(
			array(
				'campaign_id'     => $campaign_id,
				'user_id'         => get_current_user_id(),
				'job_type'        => $job_type,
				'provider'        => $provider,
				'requested_model' => isset( $settings[ $provider . '_model' ] ) ? $settings[ $provider . '_model' ] : '',
				'input'           => $input,
			)
		);

		$release_mutex();

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		Bcsend_Ai_Job_Runner::dispatch( $job );
		Bcsend_Logger::log( 'ai', 'AI job ' . $job->id . ' (' . $job_type . ') enqueued for campaign ' . $campaign_id . '.' );

		wp_send_json_success(
			array(
				'job'    => $job->public_token,
				'status' => $job->status,
			)
		);
	}

	/**
	 * AJAX: poll a job's status.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->guard();

		$job = $this->load_job_from_request();

		// Liveness sweeps. An expired lease on a submitted job means the
		// outcome is unknown (possibly billed) - never blindly retry. But an
		// OpenAI background job carries a provider-side response ID, and
		// fetching it is a plain GET with no duplicate-generation or billing
		// risk, so try to collect the finished result before giving up.
		if ( 'openai' === $job->provider && ! empty( $job->provider_job_id ) ) {
			Bcsend_Ai_Job_Runner::recover_provider_result( $job );
		} else {
			Bcsend_Ai_Jobs::sweep_uncertain( (int) $job->id );
		}

		if ( Bcsend_Ai_Jobs::requeue_stale_dispatch( (int) $job->id ) ) {
			$job = Bcsend_Ai_Jobs::get( (int) $job->id );
			$this->throttled_dispatch( $job );
		}

		$job = Bcsend_Ai_Jobs::get( (int) $job->id );
		$age = $this->job_age( $job );

		// A job still queued after the grace period means the loopback kick
		// didn't take; re-dispatch (atomic claim makes repeats harmless, and
		// the throttle keeps it to one attempt per interval across all tabs).
		if ( 'queued' === $job->status && $age > self::REDISPATCH_AFTER ) {
			$this->throttled_dispatch( $job );
		}

		$response = array(
			'status'                 => $job->status,
			'job_type'               => $job->job_type,
			'campaign_id'            => (int) $job->campaign_id,
			'provider'               => $job->provider,
			'model'                  => '' !== $job->effective_model ? $job->effective_model : $job->requested_model,
			'elapsed'                => $age,
			'background_unavailable' => ( 'queued' === $job->status && $age > self::BACKGROUND_UNAVAILABLE_AFTER ),
		);

		if ( 'completed' === $job->status ) {
			$result                    = json_decode( (string) $job->result_json, true );
			$response['result']        = is_array( $result ) ? $result : array();
			$response['fallback_used'] = (bool) $job->fallback_used;
		} elseif ( 'failed' === $job->status ) {
			$response['error_code']    = $job->error_code;
			$response['error_message'] = $job->error_message;
		} elseif ( 'uncertain' === $job->status ) {
			$response['error_code']       = $job->error_code;
			$response['error_message']    = $job->error_message;
			$response['recovery_pending'] = Bcsend_Ai_Jobs::is_openai_recovery_pending( $job );
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: cancel a job that has not been submitted to the provider yet.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->guard();

		$job = $this->load_job_from_request();

		if ( Bcsend_Ai_Jobs::cancel( (int) $job->id ) ) {
			wp_send_json_success( array( 'status' => 'cancelled' ) );
		}

		wp_send_json_error(
			array(
				'message' => __( 'This job was already sent to the AI provider and can no longer be cancelled.', 'beacon-campaign-sender' ),
			)
		);
	}

	/**
	 * AJAX: list resumable jobs for a campaign so the composer can re-attach
	 * after a page reload or browser restart.
	 *
	 * @return void
	 */
	public function ajax_resume() {
		$this->guard();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$jobs        = Bcsend_Ai_Jobs::find_resumable( $campaign_id, get_current_user_id() );
		$out         = array();

		foreach ( $jobs as $job ) {
			if ( ! $this->user_owns_job( $job ) ) {
				continue;
			}
			if ( 'openai' === $job->provider && ! empty( $job->provider_job_id ) && in_array( $job->status, array( 'submitted', 'uncertain' ), true ) ) {
				Bcsend_Ai_Job_Runner::recover_provider_result( $job );
				$job = Bcsend_Ai_Jobs::get( (int) $job->id );
			}

			$out[] = array(
				'job'              => $job->public_token,
				'job_type'         => $job->job_type,
				'status'           => $job->status,
				'elapsed'          => $this->job_age( $job ),
				'recovery_pending' => Bcsend_Ai_Jobs::is_openai_recovery_pending( $job ),
			);
		}

		wp_send_json_success( array( 'jobs' => $out ) );
	}

	/**
	 * Load a job from the request token, enforcing ownership.
	 *
	 * @return object Job row (request is terminated on failure).
	 */
	private function load_job_from_request() {
		$token = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job   = ! empty( $token ) ? Bcsend_Ai_Jobs::get_by_token( $token ) : null;

		if ( ! $job || ! $this->user_owns_job( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'Generation job not found.', 'beacon-campaign-sender' ) ) );
		}

		return $job;
	}

	/**
	 * Dispatch a worker for a job, at most once per redispatch interval.
	 *
	 * Status polls arrive every 2-5 seconds from every open tab; without a
	 * throttle, a job stuck in 'queued' because the site's background
	 * machinery is struggling accrues a new scheduled action plus loopback
	 * request per poll - a self-inflicted request storm exactly when the
	 * host is least able to absorb it. The transient self-expires, so a
	 * genuinely stuck job still gets a fresh dispatch attempt each interval.
	 *
	 * @param object $job Job row.
	 * @return void
	 */
	private function throttled_dispatch( $job ) {
		$key = 'bcsend_redispatch_' . (int) $job->id;

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, self::REDISPATCH_AFTER );
		Bcsend_Ai_Job_Runner::dispatch( $job );
	}

	/**
	 * Age of a job in seconds.
	 *
	 * @param object $job Job row.
	 * @return int
	 */
	private function job_age( $job ) {
		$created = strtotime( $job->created_at . ' UTC' );

		return max( 0, time() - (int) $created );
	}

	/**
	 * Sanitize and validate the input snapshot for a job type.
	 *
	 * Mirrors the sanitization of the legacy synchronous handlers so the
	 * runner receives exactly what those handlers would have passed on.
	 *
	 * @param string $job_type    Job type.
	 * @param int    $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	private function collect_input( $job_type, $campaign_id ) {
		if ( 'campaign' === $job_type ) {
			$prompt      = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
			$has_content = ! empty( $_POST['product_ids'] ) || ! empty( $_POST['image_urls'] ) || ! empty( $_POST['post_ids'] );

			if ( empty( $prompt ) && ! $has_content ) {
				return new WP_Error( 'empty_prompt', __( 'Enter a prompt or select content to include.', 'beacon-campaign-sender' ) );
			}

			if ( empty( $prompt ) && $has_content ) {
				$prompt = 'Create a marketing email campaign featuring the selected content.';
			}

			$image_urls = array();
			if ( ! empty( $_POST['image_urls'] ) && is_array( $_POST['image_urls'] ) ) {
				$image_urls_raw = array_map( 'esc_url_raw', wp_unslash( $_POST['image_urls'] ) );
				$image_urls     = array_values( array_filter( $image_urls_raw ) );
			}

			return array(
				'prompt'           => $prompt,
				'product_ids'      => isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array(),
				'image_urls'       => $image_urls,
				'post_ids'         => isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array(),
				'template_id'      => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
				'current_html'     => isset( $_POST['current_html'] ) ? bcsend_kses_email( wp_unslash( $_POST['current_html'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- bcsend_kses_email() is the plugin's email-HTML sanitizer.
				'channels'         => isset( $_POST['channels'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['channels'] ) ) : array( 'email', 'push' ),
				'social_platforms' => isset( $_POST['social_platforms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['social_platforms'] ) ) : array(),
				'social_post_mode' => isset( $_POST['social_post_mode'] ) ? sanitize_key( wp_unslash( $_POST['social_post_mode'] ) ) : '',
			);
		}

		if ( 'html' === $job_type ) {
			return array(
				'campaign_id' => $campaign_id,
				'plain_text'  => isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '',
			);
		}

		if ( 'push' === $job_type ) {
			return array(
				'campaign_id'  => $campaign_id,
				'context_text' => isset( $_POST['context_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_text'] ) ) : '',
				'prompt'       => isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '',
			);
		}

		return array(
			'campaign_id'      => $campaign_id,
			'context_text'     => isset( $_POST['context_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_text'] ) ) : '',
			'prompt'           => isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '',
			'social_platforms' => isset( $_POST['social_platforms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['social_platforms'] ) ) : array(),
			'social_post_mode' => isset( $_POST['social_post_mode'] ) ? sanitize_key( wp_unslash( $_POST['social_post_mode'] ) ) : '',
		);
	}
}
