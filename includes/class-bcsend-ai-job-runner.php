<?php
/**
 * Best-effort background executor for AI generation jobs.
 *
 * Dispatch strategy: an immediate non-blocking loopback kick (fastest path on
 * most hosts) plus an Action Scheduler / WP-Cron backstop. The two paths race
 * safely - the atomic claim in Bcsend_Ai_Jobs guarantees a job runs at most
 * once. WordPress hosting cannot guarantee a background process survives, so
 * liveness is tracked with leases rather than fabricated heartbeats: once the
 * provider request is sent the job either completes, fails definitively, or
 * is swept to "uncertain" when the lease expires - never retried silently.
 *
 * Provider strategy seam: execute() resolves the provider per job, so a
 * provider-native background mode (e.g. OpenAI Responses background jobs)
 * can later replace the local blocking call for that provider only.
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Ai_Job_Runner
 */
class Bcsend_Ai_Job_Runner {

	/**
	 * Scheduler hook for the cron/Action Scheduler backstop.
	 */
	const HOOK = 'bcsend_run_ai_job';

	/**
	 * Provider HTTP timeout for background workers, in seconds.
	 *
	 * No browser is waiting on a background job, so the worker can afford a
	 * far longer window than the interactive 120-second default. The job
	 * lease derives from the same filter and extends with it automatically.
	 */
	const BACKGROUND_TIMEOUT = 300;

	/**
	 * Register runner hooks.
	 *
	 * The kick endpoint is intentionally available to unauthenticated
	 * requests: the loopback carries no user session. Authority comes from
	 * the unguessable per-job runner token, compared with hash_equals().
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'wp_ajax_bcsend_ai_job_run', array( $this, 'handle_kick' ) );
		add_action( 'wp_ajax_nopriv_bcsend_ai_job_run', array( $this, 'handle_kick' ) );
	}

	/**
	 * Dispatch a queued job: schedule the backstop, then kick a loopback.
	 *
	 * @param object $job Job row.
	 * @return void
	 */
	public static function dispatch( $job ) {
		$job_id = (int) $job->id;

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $job_id ), Bcsend_Scheduler::GROUP );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::HOOK, array( $job_id ), Bcsend_Scheduler::GROUP );
		} else {
			wp_schedule_single_event( time(), self::HOOK, array( $job_id ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}

		self::kick( $job );
	}

	/**
	 * Fire a non-blocking loopback request that runs the job immediately.
	 *
	 * @param object $job Job row.
	 * @return void
	 */
	public static function kick( $job ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action'       => 'bcsend_ai_job_run',
					'job_id'       => (int) $job->id,
					'runner_token' => $job->runner_token,
				),
			)
		);
	}

	/**
	 * AJAX: loopback kick entry point (token-authenticated, no user session).
	 *
	 * @return void
	 */
	public function handle_kick() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Loopback requests carry no user session; authority is the per-job runner token below.
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$token  = isset( $_POST['runner_token'] ) ? sanitize_text_field( wp_unslash( $_POST['runner_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$job = $job_id ? Bcsend_Ai_Jobs::get( $job_id ) : null;

		if ( ! $job || empty( $token ) || ! hash_equals( (string) $job->runner_token, $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		$this->run( $job );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Scheduler entry point (Action Scheduler or WP-Cron backstop).
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function run_scheduled( $job_id ) {
		$job = Bcsend_Ai_Jobs::get( absint( $job_id ) );

		if ( $job ) {
			$this->run( $job );
		}
	}

	/**
	 * Filter callback: background workers use the long provider timeout.
	 *
	 * @return int
	 */
	public static function background_timeout() {
		return self::BACKGROUND_TIMEOUT;
	}

	/**
	 * Claim and execute a job. Safe to call from racing dispatch paths.
	 *
	 * @param object $job Job row.
	 * @return void
	 */
	public function run( $job ) {
		if ( 'queued' !== $job->status ) {
			return;
		}

		$lock = Bcsend_Ai_Jobs::claim( (int) $job->id, $job->runner_token );

		if ( false === $lock ) {
			return;
		}

		// This job must never observe a previous job's generation metadata
		// (the meta lives in a process-global static; Action Scheduler runs
		// many jobs per PHP process).
		Bcsend_AI_Service::reset_generation_meta();

		// Best-effort worker protections: a browser disconnect must not stop
		// us, and PHP's own limit is lifted where the host permits. The web
		// server or host can still terminate the process - the lease model
		// in Bcsend_Ai_Jobs covers that case.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- Best effort only; failure is expected on locked-down hosts.
		}

		// Raise the provider timeout for this worker; the lease computed in
		// mark_submitted() reads the same filter and extends to match. OpenAI
		// requests additionally switch to provider-native background mode
		// (submit + short status polls instead of one long HTTP request).
		add_filter( 'bcsend_ai_request_timeout', array( __CLASS__, 'background_timeout' ) );
		add_filter( 'bcsend_openai_background', '__return_true' );

		// Restore the interactive request profile when this worker's job ends,
		// so a foreground request later in the same process is unaffected.
		$previous_user_id = get_current_user_id();

		$restore_filters = static function () use ( $previous_user_id ) {
			remove_filter( 'bcsend_ai_request_timeout', array( __CLASS__, 'background_timeout' ) );
			remove_filter( 'bcsend_openai_background', '__return_true' );
			// The job ran as its owner; everything after it in this process
			// (other Action Scheduler tasks, other plugins' hooks) must not
			// inherit that identity.
			wp_set_current_user( $previous_user_id );
		};

		wp_set_current_user( (int) $job->user_id );

		$settings = Bcsend_Settings::get_settings();
		$provider = Bcsend_AI_Service::get_provider( $settings );
		$model    = isset( $settings[ $provider . '_model' ] ) ? (string) $settings[ $provider . '_model' ] : '';

		// The job's recorded provider/model is an immutable snapshot: if the
		// admin changed AI settings while this job was queued, running it now
		// would use a model the user never asked for. Fail cleanly instead.
		if ( ( '' !== (string) $job->provider && $provider !== $job->provider )
			|| ( '' !== (string) $job->requested_model && $model !== $job->requested_model ) ) {
			$restore_filters();
			Bcsend_Ai_Jobs::fail(
				(int) $job->id,
				$lock,
				'settings_changed',
				__( 'The AI provider or model setting changed after this job was queued. Please generate again.', 'beacon-campaign-sender' )
			);
			return;
		}

		// mark_submitted() only succeeds from the dispatching state under our
		// lock. If it fails - most importantly because the user cancelled the
		// job between claim and here - the provider must NOT be called, or a
		// foreground retry could produce a second billed request.
		if ( ! Bcsend_Ai_Jobs::mark_submitted( (int) $job->id, $lock, $provider, $model ) ) {
			$restore_filters();
			Bcsend_Logger::log( 'ai', 'AI job ' . $job->id . ' aborted before submission (cancelled or state changed).' );
			return;
		}

		// Worker-side listeners for provider events during execution:
		//  - a provider response ID (OpenAI background submit) is persisted to
		//    the job row IMMEDIATELY, so a killed worker never loses the
		//    pointer to a paid result;
		//  - lease extensions fire when the provider interaction legitimately
		//    restarts the clock (refusal fallback second call, each
		//    successful background status poll).
		// These listeners are scoped to THIS job only. Action Scheduler runs
		// several actions per PHP request, so they must be removed the moment
		// the job finishes - otherwise a later job's provider events would
		// write onto this job's row.
		$job_id = (int) $job->id;

		$on_response_id = static function ( $response_id ) use ( $job_id ) {
			Bcsend_Ai_Jobs::record_provider_response( $job_id, $response_id );
		};
		$on_lease_extend = static function () use ( $job_id, $lock ) {
			Bcsend_Ai_Jobs::extend_lease( $job_id, $lock, Bcsend_Ai_Jobs::lease_seconds() );
		};

		add_action( 'bcsend_ai_provider_response_submitted', $on_response_id );
		add_action( 'bcsend_ai_lease_extend', $on_lease_extend );

		$detach_listeners = static function () use ( $on_response_id, $on_lease_extend, $restore_filters ) {
			remove_action( 'bcsend_ai_provider_response_submitted', $on_response_id );
			remove_action( 'bcsend_ai_lease_extend', $on_lease_extend );
			$restore_filters();
		};

		try {
			$result = $this->execute( $job );
		} catch ( Throwable $e ) {
			$detach_listeners();
			Bcsend_Logger::log( 'ai', 'AI job ' . $job->id . ' crashed: ' . $e->getMessage(), '', 'error' );
			Bcsend_Ai_Jobs::fail( (int) $job->id, $lock, 'exception', $e->getMessage() );
			return;
		}

		$detach_listeners();

		// Metadata the provider client recorded during generation: the model
		// that actually answered (differs from requested on a refusal
		// fallback), and the provider-side response ID for background runs.
		// An errored result has no claim to any of it - recording from the
		// static on failure is exactly how a stale predecessor's response ID
		// could be stamped onto this row.
		$meta = is_wp_error( $result ) ? array() : Bcsend_AI_Service::get_last_generation_meta();

		if ( ! empty( $meta['provider_response_id'] ) ) {
			Bcsend_Ai_Jobs::record_provider_response( (int) $job->id, $meta['provider_response_id'] );
		}

		$effective_model = ! empty( $meta['effective_model'] ) ? (string) $meta['effective_model'] : $model;
		$fallback_used   = ! empty( $meta['fallback_used'] );

		if ( is_wp_error( $result ) ) {
			Bcsend_Logger::log( 'ai', 'AI job ' . $job->id . ' (' . $job->job_type . ') failed: ' . $result->get_error_message(), '', 'error' );

			// A transport timeout is ambiguous - the provider may still have
			// completed and billed the request - so the job becomes uncertain
			// (never auto-retried) rather than failed.
			if ( 'ai_timeout_ambiguous' === $result->get_error_code() ) {
				Bcsend_Ai_Jobs::mark_uncertain( (int) $job->id, $lock, 'ai_timeout_ambiguous', $result->get_error_message() );
				return;
			}

			Bcsend_Ai_Jobs::fail( (int) $job->id, $lock, (string) $result->get_error_code(), $result->get_error_message() );
			return;
		}

		Bcsend_Ai_Jobs::complete( (int) $job->id, $lock, $result, $effective_model, $fallback_used );
		Bcsend_Logger::log( 'ai', 'AI job ' . $job->id . ' (' . $job->job_type . ') completed by ' . $effective_model . ( $fallback_used ? ' (refusal fallback)' : '' ) . '.' );
	}

	/**
	 * Execute the generation for a job from its stored input snapshot.
	 *
	 * Returns the same payload shape the legacy synchronous AJAX handlers
	 * produced, so the composer can apply results through one code path.
	 *
	 * @param object $job Job row.
	 * @return array|WP_Error
	 */
	private function execute( $job ) {
		$input = json_decode( (string) $job->input_json, true );

		if ( ! is_array( $input ) ) {
			return new WP_Error( 'bad_input', __( 'The stored job input could not be read.', 'beacon-campaign-sender' ) );
		}

		switch ( $job->job_type ) {
			case 'campaign':
				$result = bcsend_ability_generate_campaign_content( $input );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'content'  => wp_json_encode( $result['content'] ),
					'provider' => $result['provider'],
				);

			case 'html':
				$result = Bcsend_AI_Service::regenerate_html_from_request(
					isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0,
					isset( $input['plain_text'] ) ? $input['plain_text'] : ''
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'html_content' => $result['html_content'],
					'provider'     => $result['provider'],
				);

			case 'push':
				$result = Bcsend_AI_Service::regenerate_push_from_request(
					isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0,
					isset( $input['context_text'] ) ? $input['context_text'] : '',
					isset( $input['prompt'] ) ? $input['prompt'] : ''
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'push_title'   => $result['push_title'],
					'push_message' => $result['push_message'],
					'provider'     => $result['provider'],
				);

			case 'social':
				$result = Bcsend_AI_Service::regenerate_social_from_request(
					isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0,
					isset( $input['context_text'] ) ? $input['context_text'] : '',
					isset( $input['social_platforms'] ) ? (array) $input['social_platforms'] : array(),
					isset( $input['prompt'] ) ? $input['prompt'] : '',
					isset( $input['social_post_mode'] ) ? $input['social_post_mode'] : ''
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'social'   => isset( $result['social'] ) ? $result['social'] : array(),
					'provider' => $result['provider'],
				);
		}

		return new WP_Error( 'unknown_job_type', __( 'Unknown generation job type.', 'beacon-campaign-sender' ) );
	}
}
