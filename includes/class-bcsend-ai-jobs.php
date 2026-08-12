<?php
/**
 * Durable ledger for background AI generation jobs.
 *
 * Job lifecycle: queued -> dispatching -> submitted -> completed | failed.
 * Additional states: uncertain (lease expired after the provider request was
 * sent - may still be processing or billed, never auto-retried), cancelled
 * (user cancelled before submission), superseded (replaced by a newer job or
 * result not applied because the campaign changed).
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- This is a live job ledger on a custom table; polls must see the current state, so object caching is deliberately not used.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolated values are the $wpdb->prefix table name and status lists built from esc_sql()'d class constants; all user values go through $wpdb->prepare() placeholders.

/**
 * Class Bcsend_Ai_Jobs
 */
class Bcsend_Ai_Jobs {

	/**
	 * Margin in seconds added to the provider HTTP timeout to form the lease.
	 *
	 * Covers 5xx retry backoff (timeouts themselves are never retried) plus
	 * result-write time, so a legitimately slow response is never declared
	 * uncertain while the request could still return.
	 */
	const LEASE_MARGIN = 90;

	/**
	 * Job rows older than this many days are purged opportunistically.
	 */
	const RETENTION_DAYS = 30;

	/**
	 * States in which a job is considered active (blocks duplicates).
	 *
	 * @var array
	 */
	public static $active_statuses = array( 'queued', 'dispatching', 'submitted' );

	/**
	 * Lease duration for a submitted provider request.
	 *
	 * Derived from the same bcsend_ai_request_timeout filter the provider
	 * clients use, so raising the worker's HTTP timeout automatically extends
	 * the lease and the two can never disagree.
	 *
	 * @return int Seconds.
	 */
	public static function lease_seconds() {
		/** This filter is documented in includes/class-bcsend-anthropic-api.php */
		$timeout = (int) apply_filters( 'bcsend_ai_request_timeout', 120 );

		return $timeout + self::LEASE_MARGIN;
	}

	/**
	 * Get the jobs table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'bcsend_ai_jobs';
	}

	/**
	 * Create a new queued job.
	 *
	 * @param array $args {
	 *     Job arguments.
	 *
	 *     @type int    $campaign_id     Campaign ID (0 while unsaved).
	 *     @type int    $user_id         Requesting user ID.
	 *     @type string $job_type        One of campaign|html|push|social.
	 *     @type string $provider        AI provider slug (anthropic|openai).
	 *     @type string $requested_model Model ID configured at enqueue time.
	 *     @type array  $input           Sanitized input snapshot.
	 * }
	 * @return object|WP_Error Job row object or WP_Error on failure.
	 */
	public static function create( $args ) {
		global $wpdb;

		$input_json = wp_json_encode( isset( $args['input'] ) ? $args['input'] : array() );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'public_token'    => self::generate_token(),
				'runner_token'    => self::generate_token(),
				'campaign_id'     => isset( $args['campaign_id'] ) ? absint( $args['campaign_id'] ) : 0,
				'user_id'         => isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id(),
				'job_type'        => sanitize_key( $args['job_type'] ),
				'provider'        => isset( $args['provider'] ) ? sanitize_key( $args['provider'] ) : '',
				'requested_model' => isset( $args['requested_model'] ) ? sanitize_text_field( $args['requested_model'] ) : '',
				'status'          => 'queued',
				'input_json'      => $input_json,
				'input_hash'      => hash( 'sha256', (string) $input_json ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'bcsend_job_insert_failed', __( 'Could not create the generation job.', 'beacon-campaign-sender' ) );
		}

		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Fetch a job by ID.
	 *
	 * @param int $id Job ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Fetch a job by its public token.
	 *
	 * @param string $token Public token.
	 * @return object|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_token = %s", $token ) );
	}

	/**
	 * Find the newest active job for a campaign/type/user combination.
	 *
	 * For unsaved campaigns (campaign_id 0) jobs are scoped per user so two
	 * authors cannot see each other's work.
	 *
	 * @param int    $campaign_id Campaign ID (may be 0).
	 * @param string $job_type    Job type.
	 * @param int    $user_id     User ID, used when campaign_id is 0.
	 * @return object|null
	 */
	public static function find_active( $campaign_id, $job_type, $user_id ) {
		global $wpdb;

		$table    = self::table();
		$statuses = "'" . implode( "','", array_map( 'esc_sql', self::$active_statuses ) ) . "'";

		if ( $campaign_id > 0 ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE campaign_id = %d AND job_type = %s AND status IN ({$statuses}) ORDER BY id DESC LIMIT 1",
					$campaign_id,
					$job_type
				)
			);
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = 0 AND user_id = %d AND job_type = %s AND status IN ({$statuses}) ORDER BY id DESC LIMIT 1",
				$user_id,
				$job_type
			)
		);
	}

	/**
	 * Find all active or recently-finished-but-unfetched jobs for the composer
	 * to resume watching after a page load.
	 *
	 * @param int $campaign_id Campaign ID (may be 0).
	 * @param int $user_id     User ID.
	 * @return array
	 */
	public static function find_resumable( $campaign_id, $user_id ) {
		global $wpdb;

		$table    = self::table();
		$statuses = "'" . implode( "','", array_map( 'esc_sql', self::$active_statuses ) ) . "'";

		// Undelivered results survive tab closes: completed jobs stay
		// resumable for an hour until the composer delivers (then dismisses)
		// them. The composer offers these for review, never auto-applies.
		$completed_cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// Uncertain jobs are offered for a day - long enough for the honest
		// "may have been billed" warning to be seen and for OpenAI recovery,
		// short enough that the composer stops nagging on every load for the
		// row's full 30-day retention. The row itself outlives the offer.
		$uncertain_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		if ( $campaign_id > 0 ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE campaign_id = %d
					AND ( status IN ({$statuses})
						OR ( status = 'uncertain' AND created_at > %s )
						OR ( status = 'completed' AND completed_at > %s ) )
					ORDER BY id ASC",
					$campaign_id,
					$uncertain_cutoff,
					$completed_cutoff
				)
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE campaign_id = 0 AND user_id = %d
				AND ( status IN ({$statuses})
					OR ( status = 'uncertain' AND created_at > %s )
					OR ( status = 'completed' AND completed_at > %s ) )
				ORDER BY id ASC",
				$user_id,
				$uncertain_cutoff,
				$completed_cutoff
			)
		);
	}

	/**
	 * Atomically claim a queued job for execution.
	 *
	 * @param int    $id           Job ID.
	 * @param string $runner_token Runner token proving dispatch authority.
	 * @return string|false Lock token on success, false if already claimed.
	 */
	public static function claim( $id, $runner_token ) {
		global $wpdb;

		$lock  = self::generate_token();
		$table = self::table();

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'dispatching', lock_token = %s, heartbeat_at = %s
				WHERE id = %d AND runner_token = %s AND status = 'queued'",
				$lock,
				current_time( 'mysql', true ),
				$id,
				$runner_token
			)
		);

		return ( 1 === $claimed ) ? $lock : false;
	}

	/**
	 * Mark a claimed job as submitted to the provider and start its lease.
	 *
	 * Called immediately before the blocking provider request. From this
	 * point the request may be billed, so the job must never silently retry.
	 *
	 * @param int    $id              Job ID.
	 * @param string $lock            Lock token from claim().
	 * @param string $provider        Provider slug.
	 * @param string $effective_model Model actually used.
	 * @return bool
	 */
	public static function mark_submitted( $id, $lock, $provider, $effective_model ) {
		global $wpdb;

		$now   = time();
		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'submitted', provider = %s, effective_model = %s,
					started_at = %s, heartbeat_at = %s, lease_expires_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'dispatching'",
				$provider,
				$effective_model,
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + self::lease_seconds() ),
				$id,
				$lock
			)
		);
	}

	/**
	 * Complete a job with its result payload.
	 *
	 * Accepts completion from submitted, dispatching, or uncertain states —
	 * a worker that outlived its lease may still legitimately finish, and its
	 * result should be preserved rather than discarded.
	 *
	 * @param int    $id              Job ID.
	 * @param string $lock            Lock token.
	 * @param array  $result          Result payload for the composer.
	 * @param string $effective_model Model that produced the result.
	 * @param bool   $fallback_used   Whether a refusal fallback ran.
	 * @return bool
	 */
	public static function complete( $id, $lock, $result, $effective_model = '', $fallback_used = false ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'completed', result_json = %s, effective_model = %s,
					fallback_used = %d, completed_at = %s, heartbeat_at = %s
				WHERE id = %d AND lock_token = %s AND status IN ('dispatching','submitted','uncertain')",
				wp_json_encode( $result ),
				$effective_model,
				$fallback_used ? 1 : 0,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$id,
				$lock
			)
		);
	}

	/**
	 * Fail a job with a definite error.
	 *
	 * @param int    $id      Job ID.
	 * @param string $lock    Lock token.
	 * @param string $code    Machine error code.
	 * @param string $message Human-readable error message.
	 * @return bool
	 */
	public static function fail( $id, $lock, $code, $message ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'failed', error_code = %s, error_message = %s,
					completed_at = %s, heartbeat_at = %s
				WHERE id = %d AND lock_token = %s AND status IN ('dispatching','submitted','uncertain')",
				sanitize_key( $code ),
				$message,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$id,
				$lock
			)
		);
	}

	/**
	 * Extend a submitted job's lease (worker demonstrably still alive).
	 *
	 * Fired when a provider interaction legitimately restarts the clock: a
	 * refusal fallback beginning a second provider call, or each successful
	 * background status poll. Keeps a healthy long-running job from being
	 * swept uncertain while it is genuinely still working.
	 *
	 * @param int    $id      Job ID.
	 * @param string $lock    Lock token.
	 * @param int    $seconds Seconds from now the new lease expires.
	 * @return bool
	 */
	public static function extend_lease( $id, $lock, $seconds ) {
		global $wpdb;

		$now   = time();
		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET lease_expires_at = %s, heartbeat_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'submitted'",
				gmdate( 'Y-m-d H:i:s', $now + absint( $seconds ) ),
				gmdate( 'Y-m-d H:i:s', $now ),
				$id,
				$lock
			)
		);
	}

	/**
	 * Mark a submitted job uncertain from its own worker.
	 *
	 * Used when the provider request timed out at the transport layer: the
	 * outcome (and billing) is unknown, so this is not a failure and must
	 * never be retried automatically.
	 *
	 * @param int    $id      Job ID.
	 * @param string $lock    Lock token.
	 * @param string $code    Machine error code.
	 * @param string $message Human-readable explanation.
	 * @return bool
	 */
	public static function mark_uncertain( $id, $lock, $code, $message ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'uncertain', error_code = %s, error_message = %s, heartbeat_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'submitted'",
				sanitize_key( $code ),
				$message,
				current_time( 'mysql', true ),
				$id,
				$lock
			)
		);
	}

	/**
	 * Complete a job from a recovered provider result (no worker lock).
	 *
	 * Used when a replacement process retrieves a finished provider-side
	 * response for a job whose original worker died. Only applies to jobs
	 * still awaiting an outcome.
	 *
	 * @param int   $id     Job ID.
	 * @param array $result Result payload for the composer.
	 * @return bool
	 */
	public static function complete_recovered( $id, $result ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'completed', result_json = %s, completed_at = %s, heartbeat_at = %s,
					error_code = '', error_message = NULL
				WHERE id = %d AND status IN ('submitted','uncertain')",
				wp_json_encode( $result ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Sweep a job whose lease expired into the uncertain state.
	 *
	 * Only applies to submitted jobs: the provider request was sent and may
	 * have been billed, so this is explicitly NOT a failure and is never
	 * retried automatically.
	 *
	 * @param int $id Job ID.
	 * @return bool True if the job was transitioned.
	 */
	public static function sweep_uncertain( $id ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'uncertain',
					error_code = 'lease_expired',
					error_message = %s
				WHERE id = %d AND status = 'submitted' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				__( 'Beacon lost contact with the background worker after the request was sent. It may still be processing or may have been billed.', 'beacon-campaign-sender' ),
				$id,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Sweep a job stuck in dispatching past its claim heartbeat window.
	 *
	 * Dispatching means the provider request was NOT yet sent, so requeueing
	 * is billing-safe. A fresh runner token invalidates the dead worker's
	 * kick URL.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function requeue_stale_dispatch( $id ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'queued', lock_token = '', runner_token = %s, heartbeat_at = NULL
				WHERE id = %d AND status = 'dispatching' AND heartbeat_at IS NOT NULL AND heartbeat_at < %s",
				self::generate_token(),
				$id,
				gmdate( 'Y-m-d H:i:s', time() - 60 )
			)
		);
	}

	/**
	 * Cancel a job that has not yet been submitted to the provider.
	 *
	 * @param int $id Job ID.
	 * @return bool True if cancelled, false if it was too late to cancel.
	 */
	public static function cancel( $id ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'cancelled', completed_at = %s
				WHERE id = %d AND status IN ('queued','dispatching')",
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Mark a job superseded (replaced or its result not applied).
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function supersede( $id ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'superseded', completed_at = %s
				WHERE id = %d AND status IN ('queued','completed','uncertain')",
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Record the provider-side response ID for a job.
	 *
	 * Stored as soon as a provider-native background submission is accepted,
	 * so an uncertain job still identifies the billable provider request.
	 *
	 * @param int    $id          Job ID.
	 * @param string $response_id Provider response/job ID.
	 * @return void
	 */
	public static function record_provider_response( $id, $response_id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array( 'provider_job_id' => sanitize_text_field( (string) $response_id ) ),
			array( 'id' => absint( $id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark an uncertain job's provider-side result as definitively
	 * unrecoverable, so status polls stop re-fetching a dead response.
	 *
	 * Set when the provider reports the background response failed, or its
	 * payload was unusable - outcomes that can never change. The original
	 * error_message is kept (it still explains the uncertainty to the user).
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public static function mark_recovery_exhausted( $id ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET error_code = 'recovery_exhausted' WHERE id = %d AND status = 'uncertain'",
				$id
			)
		);
	}

	/**
	 * Opportunistically purge terminal jobs older than the retention window.
	 *
	 * @return void
	 */
	public static function purge_old() {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE created_at < %s AND status IN ('completed','failed','cancelled','superseded','uncertain')",
				$cutoff
			)
		);

		// Abandoned non-terminal rows would otherwise survive every purge and
		// (as the active job for their campaign/type) block new generations
		// forever if their owner never polls again. A day past any sign of
		// life, clean them up billing-safely: queued/dispatching jobs never
		// reached the provider so they can simply be deleted; a submitted job
		// whose lease expired that long ago becomes uncertain (same
		// transition its owner's polls would have applied) so a paid OpenAI
		// result is still recoverable until retention removes the row.
		$abandoned_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE created_at < %s AND status IN ('queued','dispatching')",
				$abandoned_cutoff
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'uncertain',
					error_code = 'lease_expired',
					error_message = %s
				WHERE status = 'submitted' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				__( 'Beacon lost contact with the background worker after the request was sent. It may still be processing or may have been billed.', 'beacon-campaign-sender' ),
				$abandoned_cutoff
			)
		);
	}

	/**
	 * Generate an unguessable token.
	 *
	 * @return string 64-character hex token.
	 */
	private static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}
}
