<?php
/**
 * Scheduler for Beacon Campaign Sender.
 *
 * Manages campaign scheduling, push notification batching, and recurring
 * maintenance jobs. Uses Action Scheduler when available, falls back to
 * WordPress native cron.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Scheduler
 *
 * Static methods for scheduling and handling async jobs.
 *
 * @since 1.0.0
 */
class Bcsend_Scheduler {

	/**
	 * Action Scheduler group identifier.
	 *
	 * @var string
	 */
	const GROUP = 'beacon-campaign-sender';

	/**
	 * Check whether a datetime string already contains a timezone indicator
	 * (Z, +HH:MM, -HH:MM). When present, strtotime() handles UTC conversion
	 * itself, so a tz_offset must NOT be applied on top.
	 *
	 * @since 2.5.0
	 *
	 * @param string $datetime_string ISO 8601 or similar datetime string.
	 * @return bool True if the string carries timezone information.
	 */
	public static function has_timezone_indicator( $datetime_string ) {
		return (bool) preg_match( '/[Zz]$|[+-]\d{2}:?\d{2}$/', trim( $datetime_string ) );
	}

	/**
	 * Initialize action hooks for all scheduled events.
	 *
	 * Called from the main plugin's init_hooks method.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'bcsend_campaign', array( __CLASS__, 'handle_send_campaign' ) );
		add_action( 'bcsend_push_batch', array( __CLASS__, 'handle_push_batch' ), 10, 5 );
		add_action( 'bcsend_sync_segments', array( __CLASS__, 'handle_sync_segments' ) );
		add_action( 'bcsend_cleanup_logs', array( __CLASS__, 'handle_cleanup_logs' ) );
		add_action( Bcsend_Subscriber_Ingest::RETRY_HOOK, array( 'Bcsend_Subscriber_Ingest', 'retry_pending' ) );

		// Standalone push notification hooks.
		add_action( 'bcsend_standalone_push', array( __CLASS__, 'handle_standalone_push' ) );
		add_action( 'bcsend_standalone_push_batch', array( __CLASS__, 'handle_standalone_push_batch' ), 10, 6 );
	}

	/**
	 * Schedule a campaign to be sent at a specific time.
	 *
	 * Uses Action Scheduler if available, otherwise falls back to wp_cron.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $timestamp   Unix timestamp for when to send.
	 *
	 * @return void
	 */
	public static function schedule_campaign( $campaign_id, $timestamp ) {
		$campaign_id = (int) $campaign_id;
		$timestamp   = (int) $timestamp;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				$timestamp,
				'bcsend_campaign',
				array( 'campaign_id' => $campaign_id ),
				self::GROUP
			);

			Bcsend_Logger::log(
				'scheduler',
				'Campaign scheduled',
				wp_json_encode(
					array(
						'action'      => 'campaign_scheduled',
						'campaign_id' => $campaign_id,
						'timestamp'   => $timestamp,
						'via'         => 'action_scheduler',
					)
				)
			);
		} else {
			wp_schedule_single_event(
				$timestamp,
				'bcsend_campaign',
				array( $campaign_id )
			);

			Bcsend_Logger::log(
				'scheduler',
				'Campaign scheduled',
				wp_json_encode(
					array(
						'action'      => 'campaign_scheduled',
						'campaign_id' => $campaign_id,
						'timestamp'   => $timestamp,
						'via'         => 'wp_cron',
					)
				)
			);
		}
	}

	/**
	 * Schedule a push notification batch for immediate async processing.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $campaign_id  Campaign ID.
	 * @param int    $batch_number Batch sequence number.
	 * @param array  $tokens       Array of push tokens for this batch.
	 * @param string $title        Notification title.
	 * @param string $message      Notification message body.
	 *
	 * @return void
	 */
	public static function schedule_push_batch( $campaign_id, $batch_number, $tokens, $title, $message ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Bcsend_Logger::log(
				'scheduler',
				'Action Scheduler not available for push batch scheduling.',
				wp_json_encode(
					array(
						'warning'     => 'Action Scheduler not available for push batch scheduling.',
						'campaign_id' => $campaign_id,
						'batch'       => $batch_number,
					)
				),
				'error'
			);
			return;
		}

		as_schedule_single_action(
			time(),
			'bcsend_push_batch',
			array(
				'campaign_id' => (int) $campaign_id,
				'batch'       => (int) $batch_number,
				'tokens'      => $tokens,
				'title'       => $title,
				'message'     => $message,
			),
			self::GROUP
		);

		Bcsend_Logger::log(
			'scheduler',
			'Push batch scheduled',
			wp_json_encode(
				array(
					'action'      => 'push_batch_scheduled',
					'campaign_id' => $campaign_id,
					'batch'       => $batch_number,
					'token_count' => count( $tokens ),
				)
			)
		);
	}

	/**
	 * Schedule recurring maintenance jobs.
	 *
	 * Sets up daily segment sync and log cleanup if not already scheduled.
	 * Uses Action Scheduler when available, wp_cron otherwise.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function schedule_recurring_jobs() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			// Segment sync.
			if ( false === as_next_scheduled_action( 'bcsend_sync_segments', array(), self::GROUP ) ) {
				as_schedule_recurring_action(
					strtotime( 'tomorrow 3:00am' ),
					DAY_IN_SECONDS,
					'bcsend_sync_segments',
					array(),
					self::GROUP
				);

				Bcsend_Logger::log(
					'scheduler',
					'Recurring segment sync scheduled',
					wp_json_encode(
						array(
							'action' => 'recurring_sync_segments_scheduled',
							'via'    => 'action_scheduler',
						)
					)
				);
			}

			// Log cleanup.
			if ( false === as_next_scheduled_action( 'bcsend_cleanup_logs', array(), self::GROUP ) ) {
				as_schedule_recurring_action(
					strtotime( 'tomorrow 4:00am' ),
					DAY_IN_SECONDS,
					'bcsend_cleanup_logs',
					array(),
					self::GROUP
				);

				Bcsend_Logger::log(
					'scheduler',
					'Recurring log cleanup scheduled',
					wp_json_encode(
						array(
							'action' => 'recurring_cleanup_logs_scheduled',
							'via'    => 'action_scheduler',
						)
					)
				);
			}

			if ( false === as_next_scheduled_action( Bcsend_Subscriber_Ingest::RETRY_HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action(
					time() + ( 5 * MINUTE_IN_SECONDS ),
					5 * MINUTE_IN_SECONDS,
					Bcsend_Subscriber_Ingest::RETRY_HOOK,
					array(),
					self::GROUP
				);

				Bcsend_Logger::log(
					'scheduler',
					'Recurring subscriber retry scheduled',
					wp_json_encode(
						array(
							'action' => 'recurring_subscriber_retry_scheduled',
							'via'    => 'action_scheduler',
						)
					)
				);
			}
		} else {
			// Segment sync.
			if ( ! wp_next_scheduled( 'bcsend_sync_segments' ) ) {
				wp_schedule_event(
					strtotime( 'tomorrow 3:00am' ),
					'daily',
					'bcsend_sync_segments'
				);

				Bcsend_Logger::log(
					'scheduler',
					'Recurring segment sync scheduled',
					wp_json_encode(
						array(
							'action' => 'recurring_sync_segments_scheduled',
							'via'    => 'wp_cron',
						)
					)
				);
			}

			// Log cleanup.
			if ( ! wp_next_scheduled( 'bcsend_cleanup_logs' ) ) {
				wp_schedule_event(
					strtotime( 'tomorrow 4:00am' ),
					'daily',
					'bcsend_cleanup_logs'
				);

				Bcsend_Logger::log(
					'scheduler',
					'Recurring log cleanup scheduled',
					wp_json_encode(
						array(
							'action' => 'recurring_cleanup_logs_scheduled',
							'via'    => 'wp_cron',
						)
					)
				);
			}

			if ( ! wp_next_scheduled( Bcsend_Subscriber_Ingest::RETRY_HOOK ) ) {
				wp_schedule_event(
					time() + ( 5 * MINUTE_IN_SECONDS ),
					'five_minutes',
					Bcsend_Subscriber_Ingest::RETRY_HOOK
				);

				Bcsend_Logger::log(
					'scheduler',
					'Recurring subscriber retry scheduled',
					wp_json_encode(
						array(
							'action' => 'recurring_subscriber_retry_scheduled',
							'via'    => 'wp_cron',
						)
					)
				);
			}
		}
	}

	/**
	 * Unschedule all pending jobs for a campaign.
	 *
	 * Removes the campaign send action and any pending push batch jobs.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return void
	 */
	public static function unschedule_campaign( $campaign_id ) {
		$campaign_id = (int) $campaign_id;

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Unschedule the main send action.
			as_unschedule_all_actions(
				'bcsend_campaign',
				array( 'campaign_id' => $campaign_id ),
				self::GROUP
			);

			// Unschedule all push batch actions for this campaign.
			// Action Scheduler does not support partial arg matching, so we
			// query the store directly.
			if ( class_exists( 'ActionScheduler' ) ) {
				$store   = ActionScheduler::store();
				$actions = $store->query_actions(
					array(
						'hook'   => 'bcsend_push_batch',
						'group'  => self::GROUP,
						'status' => ActionScheduler_Store::STATUS_PENDING,
					)
				);

				foreach ( $actions as $action_id ) {
					$action = $store->fetch_action( $action_id );
					$args   = $action->get_args();

					if ( isset( $args['campaign_id'] ) && (int) $args['campaign_id'] === $campaign_id ) {
						$store->cancel_action( $action_id );
					}
				}
			}

			Bcsend_Logger::log(
				'scheduler',
				'Campaign unscheduled',
				wp_json_encode(
					array(
						'action'      => 'campaign_unscheduled',
						'campaign_id' => $campaign_id,
						'via'         => 'action_scheduler',
					)
				)
			);
		} else {
			wp_clear_scheduled_hook( 'bcsend_campaign', array( $campaign_id ) );

			Bcsend_Logger::log(
				'scheduler',
				'Campaign unscheduled',
				wp_json_encode(
					array(
						'action'      => 'campaign_unscheduled',
						'campaign_id' => $campaign_id,
						'via'         => 'wp_cron',
					)
				)
			);
		}
	}

	/**
	 * Handle the campaign send action.
	 *
	 * Callback for the bcsend_campaign action.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return void
	 */
	public static function handle_send_campaign( $campaign_id ) {
		$campaign_id = (int) $campaign_id;

		Bcsend_Logger::log(
			'scheduler',
			'Handling campaign send',
			wp_json_encode(
				array(
					'action'      => 'handle_send_campaign',
					'campaign_id' => $campaign_id,
				)
			)
		);

		$result = Bcsend_Campaign_Sender::send( $campaign_id );

		if ( is_wp_error( $result ) ) {
			Bcsend_Logger::log(
				'scheduler',
				'Campaign send FAILED',
				wp_json_encode(
					array(
						'action'      => 'send_failed',
						'campaign_id' => $campaign_id,
						'error'       => $result->get_error_message(),
					)
				),
				'error'
			);
		} else {
			Bcsend_Logger::log(
				'scheduler',
				'Campaign send completed',
				wp_json_encode(
					array(
						'action'      => 'send_completed',
						'campaign_id' => $campaign_id,
						'result'      => $result,
					)
				)
			);
		}
	}

	/**
	 * Handle a push notification batch action.
	 *
	 * Sends a batch of push notifications and checks whether all batches
	 * for the campaign are complete.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $campaign_id  Campaign ID.
	 * @param int    $batch_number Batch sequence number.
	 * @param array  $tokens       Array of push tokens.
	 * @param string $title        Notification title.
	 * @param string $message      Notification message body.
	 *
	 * @return void
	 */
	public static function handle_push_batch( $campaign_id, $batch_number, $tokens, $title, $message ) {
		$campaign_id  = (int) $campaign_id;
		$batch_number = (int) $batch_number;

		Bcsend_Logger::log(
			'scheduler',
			'Handling push batch',
			wp_json_encode(
				array(
					'action'      => 'handle_push_batch_start',
					'campaign_id' => $campaign_id,
					'batch'       => $batch_number,
					'token_count' => count( $tokens ),
				)
			)
		);

		$push_service = new Bcsend_Push_Service();
		$result       = $push_service->send_batch( $tokens, $title, $message );

		$sent            = is_array( $result ) && isset( $result['sent'] ) ? (int) $result['sent'] : 0;
		$failed          = is_array( $result ) && isset( $result['failed'] ) ? (int) $result['failed'] : 0;
		$invalid_cleaned = is_array( $result ) && isset( $result['invalid_cleaned'] ) ? (int) $result['invalid_cleaned'] : 0;

		Bcsend_Logger::log(
			'push_batch',
			'Push batch processed',
			wp_json_encode(
				array(
					'campaign_id'     => $campaign_id,
					'batch'           => $batch_number,
					'total'           => count( $tokens ),
					'sent'            => $sent,
					'failed'          => $failed,
					'invalid_cleaned' => $invalid_cleaned,
				)
			)
		);

		// Check if this was the last batch.
		if ( ! self::has_pending_batches( $campaign_id ) ) {
			Bcsend_Logger::log(
				'scheduler',
				'Last push batch completed',
				wp_json_encode(
					array(
						'action'      => 'last_push_batch_completed',
						'campaign_id' => $campaign_id,
					)
				)
			);

			Bcsend_Campaign_Sender::complete_push_batches( $campaign_id );
		}
	}

	/**
	 * Handle the segment sync recurring action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_sync_segments() {
		Bcsend_Logger::log(
			'scheduler',
			'Segment sync started',
			wp_json_encode(
				array(
					'action' => 'sync_segments_start',
				)
			)
		);

		Bcsend_Segment_Engine::sync_all();

		Bcsend_Logger::log(
			'scheduler',
			'Segment sync completed',
			wp_json_encode(
				array(
					'action' => 'sync_segments_complete',
				)
			)
		);
	}

	/**
	 * Handle the log cleanup recurring action.
	 *
	 * Deletes logs older than the configured retention period.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_cleanup_logs() {
		$settings       = get_option( 'bcsend_settings', array() );
		$retention_days = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;
		$retention_days = max( 1, $retention_days );

		Bcsend_Logger::log(
			'scheduler',
			'Log cleanup started',
			wp_json_encode(
				array(
					'action'         => 'cleanup_logs_start',
					'retention_days' => $retention_days,
				)
			)
		);

		Bcsend_Logger::delete_old_logs( $retention_days );
		Bcsend_Email_Log::delete_old( $retention_days );

		// Expire overdue standalone push notifications.
		Bcsend_Push_Manager::expire_overdue();

		Bcsend_Logger::log(
			'scheduler',
			'Log cleanup completed',
			wp_json_encode(
				array(
					'action' => 'cleanup_logs_complete',
				)
			)
		);
	}

	/**
	 * Handle a scheduled standalone push notification send.
	 *
	 * @since 2.2.0
	 *
	 * @param int $push_id Push notification ID.
	 *
	 * @return void
	 */
	public static function handle_standalone_push( $push_id ) {
		$push_id = (int) $push_id;

		Bcsend_Logger::log(
			'scheduler',
			'Standalone push triggered',
			wp_json_encode( array( 'push_id' => $push_id ) )
		);

		$result = Bcsend_Push_Manager::send( $push_id );

		if ( is_wp_error( $result ) ) {
			Bcsend_Logger::log(
				'scheduler',
				'Standalone push failed: ' . $result->get_error_message(),
				wp_json_encode( array( 'push_id' => $push_id ) ),
				'error'
			);
		}
	}

	/**
	 * Handle a standalone push notification batch.
	 *
	 * @since 2.2.0
	 *
	 * @param int    $push_id  Push notification ID.
	 * @param int    $batch    Batch number.
	 * @param array  $tokens   Device tokens.
	 * @param string $title    Push title.
	 * @param string $message  Push message.
	 * @param string $link_url Deep link URL.
	 *
	 * @return void
	 */
	public static function handle_standalone_push_batch( $push_id, $batch, $tokens, $title, $message, $link_url = '' ) {
		Bcsend_Push_Manager::handle_batch( (int) $push_id, (int) $batch, $tokens, $title, $message, $link_url );
	}

	/**
	 * Check whether a campaign has any pending push batch jobs.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return bool True if pending batch jobs exist.
	 */
	public static function has_pending_batches( $campaign_id ) {
		$campaign_id = (int) $campaign_id;

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return false;
		}

		$store   = ActionScheduler::store();
		$actions = $store->query_actions(
			array(
				'hook'     => 'bcsend_push_batch',
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			)
		);

		if ( empty( $actions ) ) {
			return false;
		}

		// Check for any pending batch action matching this campaign.
		$pending = $store->query_actions(
			array(
				'hook'   => 'bcsend_push_batch',
				'group'  => self::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		foreach ( $pending as $action_id ) {
			$action = $store->fetch_action( $action_id );
			$args   = $action->get_args();

			if ( isset( $args['campaign_id'] ) && (int) $args['campaign_id'] === $campaign_id ) {
				return true;
			}
		}

		return false;
	}
}
