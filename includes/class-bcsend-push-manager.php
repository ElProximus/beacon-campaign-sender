<?php
/**
 * Push Notification Manager for Beacon Campaign Sender.
 *
 * Handles creation, scheduling, sending, batch processing,
 * and status management for standalone push notifications.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Push_Manager
 *
 * @since 2.2.0
 */
class Bcsend_Push_Manager {

	/**
	 * Items per page for list queries.
	 */
	const PER_PAGE = 20;

	/**
	 * Tokens per Action Scheduler batch.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Token count at or below which send() dispatches inline in the current
	 * request instead of queueing into Action Scheduler. Avoids AS claim-slot
	 * deadlocks and cron drain delays for typical small sends.
	 */
	const SYNC_DISPATCH_THRESHOLD = 50;

	/**
	 * Hours after scheduled time before a push expires.
	 */
	const EXPIRY_HOURS = 3;

	/**
	 * Push notifications table name (without prefix).
	 */
	const TABLE_PUSH = 'bcsend_push_notifications';

	/**
	 * Push history table name (without prefix).
	 */
	const TABLE_HISTORY = 'bcsend_push_history';

	// =========================================================================
	// Create & Submit
	// =========================================================================

	/**
	 * Create a push notification and either send immediately or schedule.
	 *
	 * @param array $args {
	 *     @type string $title        Required. Max 50 chars.
	 *     @type string $message      Required. Max 400 chars.
	 *     @type string $link_url     Optional deep link URL.
	 *     @type string $target_type  all_users|by_role|specific_users.
	 *     @type string $target_data  JSON string (role names or user IDs).
	 *     @type bool   $is_scheduled Whether to schedule for later.
	 *     @type string $scheduled_at ISO datetime (required if scheduled).
	 *     @type int    $tz_offset    Browser timezone offset in minutes.
	 * }
	 * @return int|WP_Error Push ID on success.
	 */
	public static function create( $args ) {
		global $wpdb;

		$title        = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		$message      = isset( $args['message'] ) ? sanitize_textarea_field( $args['message'] ) : '';
		$link_url     = isset( $args['link_url'] ) ? esc_url_raw( $args['link_url'] ) : '';
		$target_type  = isset( $args['target_type'] ) ? sanitize_text_field( $args['target_type'] ) : 'all_users';
		$target_data  = isset( $args['target_data'] ) ? $args['target_data'] : null;
		$is_scheduled = ! empty( $args['is_scheduled'] );
		$scheduled_at = isset( $args['scheduled_at'] ) ? sanitize_text_field( $args['scheduled_at'] ) : '';
		$tz_offset    = isset( $args['tz_offset'] ) ? (int) $args['tz_offset'] : null;

		// Validation.
		if ( empty( $message ) ) {
			return new WP_Error( 'missing_message', 'Push notification message is required.' );
		}

		if ( mb_strlen( $title ) > 50 ) {
			return new WP_Error( 'title_too_long', 'Title must be 50 characters or fewer.' );
		}

		if ( mb_strlen( $message ) > 400 ) {
			return new WP_Error( 'message_too_long', 'Message must be 400 characters or fewer.' );
		}

		$allowed_types = array( 'all_users', 'by_role', 'specific_users' );
		if ( ! in_array( $target_type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_target_type', 'Invalid target type.' );
		}

		// Validate and normalize target_data.
		if ( is_string( $target_data ) && ! empty( $target_data ) ) {
			$decoded = json_decode( $target_data, true );
			if ( null === $decoded ) {
				return new WP_Error( 'invalid_target_data', 'Target data must be valid JSON.' );
			}
			$target_data = wp_json_encode( $decoded );
		} elseif ( is_array( $target_data ) ) {
			$target_data = wp_json_encode( $target_data );
		} else {
			$target_data = null;
		}

		// Schedule handling.
		$status     = 'pending';
		$sched_utc  = null;
		$expires_at = null;
		$timestamp  = 0;

		if ( $is_scheduled && ! empty( $scheduled_at ) ) {
			$timestamp = strtotime( $scheduled_at );

			if ( false === $timestamp ) {
				return new WP_Error( 'invalid_scheduled_at', 'Could not parse scheduled time.' );
			}

			if ( null !== $tz_offset && ! Bcsend_Scheduler::has_timezone_indicator( $scheduled_at ) ) {
				$timestamp += $tz_offset * 60;
			}

			if ( $timestamp < ( time() - 120 ) ) {
				return new WP_Error( 'past_schedule', 'Scheduled time must be in the future.' );
			}

			$status     = 'scheduled';
			$sched_utc  = gmdate( 'Y-m-d H:i:s', $timestamp );
			$expires_at = gmdate( 'Y-m-d H:i:s', $timestamp + ( self::EXPIRY_HOURS * HOUR_IN_SECONDS ) );
		}

		$table = $wpdb->prefix . self::TABLE_PUSH;

		$inserted = $wpdb->insert(
			$table,
			array(
				'title'        => $title,
				'message'      => $message,
				'link_url'     => $link_url,
				'status'       => $status,
				'is_scheduled' => $is_scheduled ? 1 : 0,
				'scheduled_at' => $sched_utc,
				'expires_at'   => $expires_at,
				'target_type'  => $target_type,
				'target_data'  => $target_data,
				'sent_by'      => get_current_user_id(),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'insert_failed', 'Failed to create push notification.' );
		}

		$push_id = (int) $wpdb->insert_id;

		Bcsend_Logger::log( 'push', sprintf( 'Push notification created (ID %d)', $push_id ) );

		if ( 'scheduled' === $status ) {
			// Schedule via Action Scheduler.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					$timestamp,
					'bcsend_standalone_push',
					array( 'push_id' => $push_id ),
					'beacon-campaign-sender'
				);
			} else {
				wp_schedule_single_event( $timestamp, 'bcsend_standalone_push', array( $push_id ) );
			}
		} else {
			// Send immediately.
			$send_result = self::send( $push_id );
			if ( is_wp_error( $send_result ) ) {
				return $send_result;
			}
		}

		return $push_id;
	}

	// =========================================================================
	// Send (dispatch batches)
	// =========================================================================

	/**
	 * Resolve recipients and dispatch push batches.
	 *
	 * @param int $push_id Push notification ID.
	 * @return true|WP_Error
	 */
	public static function send( $push_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_PUSH;
		$push  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $push_id ) );

		if ( ! $push ) {
			return new WP_Error( 'not_found', 'Push notification not found.' );
		}

		if ( ! in_array( $push->status, array( 'pending', 'scheduled' ), true ) ) {
			return new WP_Error( 'invalid_status', sprintf( 'Push status is "%s"; must be pending or scheduled.', $push->status ) );
		}

		// Update to processing.
		$wpdb->update( $table, array( 'status' => 'processing' ), array( 'id' => $push_id ), array( '%s' ), array( '%d' ) );

		// Resolve recipient user IDs.
		$target_data = ! empty( $push->target_data ) ? json_decode( $push->target_data, true ) : array();
		$user_ids    = self::resolve_recipients( $push->target_type, $target_data );

		if ( empty( $user_ids ) ) {
			$wpdb->update( $table, array( 'status' => 'failed' ), array( 'id' => $push_id ), array( '%s' ), array( '%d' ) );
			Bcsend_Logger::log( 'push', sprintf( 'Push %d failed: no recipients found.', $push_id ), '', 'error' );
			return new WP_Error( 'no_recipients', 'No recipients found for this target.' );
		}

		// Get device tokens.
		$push_service = new Bcsend_Push_Service();
		$tokens       = $push_service->get_tokens_for_users( $user_ids );

		if ( empty( $tokens ) ) {
			$wpdb->update( $table, array( 'status' => 'failed' ), array( 'id' => $push_id ), array( '%s' ), array( '%d' ) );
			Bcsend_Logger::log( 'push', sprintf( 'Push %d failed: no device tokens found.', $push_id ), '', 'error' );
			return new WP_Error( 'no_tokens', 'No device tokens found for recipients.' );
		}

		// Store total and update to sending.
		$wpdb->update(
			$table,
			array(
				'status'       => 'sending',
				'total_tokens' => count( $tokens ),
			),
			array( 'id' => $push_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		// Dispatch batches.
		$batches = array_chunk( $tokens, self::BATCH_SIZE );

		Bcsend_Logger::log(
			'push',
			sprintf( 'Push %d: dispatching %d batches (%d tokens)', $push_id, count( $batches ), count( $tokens ) )
		);

		$dispatch_inline = count( $tokens ) <= self::SYNC_DISPATCH_THRESHOLD || ! function_exists( 'as_schedule_single_action' );

		if ( $dispatch_inline ) {
			// Inline dispatch: cheap enough to complete in-request; avoids the
			// AS single-claim-slot deadlock that can strand small sends for
			// hours when an unrelated action hangs.
			@set_time_limit( 120 );
			foreach ( $batches as $index => $batch_tokens ) {
				self::handle_batch( $push_id, $index + 1, $batch_tokens, $push->title, $push->message, $push->link_url );
			}
			self::finalize( $push_id );
		} else {
			foreach ( $batches as $index => $batch_tokens ) {
				as_schedule_single_action(
					time(),
					'bcsend_standalone_push_batch',
					array(
						'push_id'  => $push_id,
						'batch'    => $index + 1,
						'tokens'   => $batch_tokens,
						'title'    => $push->title,
						'message'  => $push->message,
						'link_url' => $push->link_url,
					),
					'beacon-campaign-sender'
				);
			}
		}

		return true;
	}

	// =========================================================================
	// Batch handler
	// =========================================================================

	/**
	 * Process a single batch of tokens, recording per-device history.
	 *
	 * @param int    $push_id      Push notification ID.
	 * @param int    $batch_number Batch number.
	 * @param array  $tokens       Array of token objects/strings.
	 * @param string $title        Push title.
	 * @param string $message      Push message.
	 * @param string $link_url     Optional deep link URL.
	 */
	public static function handle_batch( $push_id, $batch_number, $tokens, $title, $message, $link_url = '' ) {
		global $wpdb;

		$push_service  = new Bcsend_Push_Service();
		$history_table = $wpdb->prefix . self::TABLE_HISTORY;
		$sent          = 0;
		$failed        = 0;

		// Normalize tokens to usable format.
		$normalized = array();
		foreach ( $tokens as $t ) {
			if ( is_object( $t ) ) {
				$token    = isset( $t->device_token ) ? $t->device_token : ( isset( $t->token ) ? $t->token : '' );
				$user_id  = isset( $t->user_id ) ? (int) $t->user_id : 0;
				$platform = isset( $t->platform ) ? $t->platform : '';
			} elseif ( is_array( $t ) ) {
				$token    = isset( $t['device_token'] ) ? $t['device_token'] : ( isset( $t['token'] ) ? $t['token'] : '' );
				$user_id  = isset( $t['user_id'] ) ? (int) $t['user_id'] : 0;
				$platform = isset( $t['platform'] ) ? $t['platform'] : '';
			} else {
				$token    = (string) $t;
				$user_id  = 0;
				$platform = '';
			}

			if ( ! empty( $token ) ) {
				$normalized[] = array(
					'token'    => $token,
					'user_id'  => $user_id,
					'platform' => $platform,
				);
			}
		}

		foreach ( $normalized as $device ) {
			$result = $push_service->send_single(
				$device['token'],
				$title,
				$message,
				$device['user_id'],
				$link_url,
				true // return_details
			);

			$success       = is_array( $result ) && 'success' === $result['status'];
			$error_message = is_array( $result ) ? $result['error_message'] : '';
			$fcm_response  = is_array( $result ) ? $result['fcm_response'] : '';

			if ( $success ) {
				++$sent;
			} else {
				++$failed;
				if ( ! is_array( $result ) && is_wp_error( $result ) ) {
					$error_message = $result->get_error_message();
				}
			}

			// Record per-device history.
			$wpdb->insert(
				$history_table,
				array(
					'push_id'           => $push_id,
					'user_id'           => $device['user_id'],
					'device_token_hash' => hash( 'sha256', $device['token'] ),
					'platform'          => $device['platform'],
					'status'            => $success ? 1 : 0,
					'error_message'     => $error_message,
					'fcm_response'      => $fcm_response,
					'created_at'        => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		Bcsend_Logger::log(
			'push',
			sprintf( 'Push %d batch %d: %d sent, %d failed', $push_id, $batch_number, $sent, $failed )
		);

		// Check if this was the last batch.
		if ( ! self::has_pending_batches( $push_id ) ) {
			self::finalize( $push_id );
		}
	}

	// =========================================================================
	// Finalize
	// =========================================================================

	/**
	 * Aggregate delivery results and set final push status.
	 *
	 * @param int $push_id Push notification ID.
	 */
	public static function finalize( $push_id ) {
		global $wpdb;

		$table         = $wpdb->prefix . self::TABLE_PUSH;
		$history_table = $wpdb->prefix . self::TABLE_HISTORY;

		$sent_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE push_id = %d AND status = 1", $push_id )
		);

		$failed_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE push_id = %d AND status = 0", $push_id )
		);

		$final_status = ( $sent_count > 0 ) ? 'sent' : 'failed';

		$wpdb->update(
			$table,
			array(
				'status'       => $final_status,
				'sent_count'   => $sent_count,
				'failed_count' => $failed_count,
				'sent_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $push_id ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log(
			'push',
			sprintf( 'Push %d finalized: %s (%d sent, %d failed)', $push_id, $final_status, $sent_count, $failed_count )
		);
	}

	// =========================================================================
	// Read operations
	// =========================================================================

	/**
	 * Get a single push notification with computed fields.
	 *
	 * @param int $push_id Push ID.
	 * @return object|null
	 */
	public static function get_push( $push_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_PUSH;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $push_id ) );
	}

	/**
	 * Get paginated list of push notifications.
	 *
	 * @param string $status Status filter ('all' or specific status).
	 * @param int    $page   Page number (1-based).
	 * @param int    $per_page Items per page.
	 * @return array {items, total, total_pages}
	 */
	public static function get_pushes( $status = 'all', $page = 1, $per_page = self::PER_PAGE ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_PUSH;
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		if ( 'all' === $status ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					$per_page,
					$offset
				)
			);
		}

		return array(
			'items'       => $items ? $items : array(),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get per-device delivery history for a push notification.
	 *
	 * @param int $push_id  Push ID.
	 * @param int $page     Page number.
	 * @param int $per_page Items per page.
	 * @return array {items, total}
	 */
	public static function get_history( $push_id, $page = 1, $per_page = 50 ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_HISTORY;
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE push_id = %d", $push_id )
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.*, u.display_name, u.user_email
				FROM {$table} h
				LEFT JOIN {$wpdb->users} u ON h.user_id = u.ID
				WHERE h.push_id = %d
				ORDER BY h.created_at DESC
				LIMIT %d OFFSET %d",
				$push_id,
				$per_page,
				$offset
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	// =========================================================================
	// Delete / Cancel
	// =========================================================================

	/**
	 * Delete a push notification and its history.
	 *
	 * @param int $push_id Push ID.
	 * @return true|WP_Error
	 */
	public static function delete( $push_id ) {
		global $wpdb;

		$push = self::get_push( $push_id );
		if ( ! $push ) {
			return new WP_Error( 'not_found', 'Push notification not found.' );
		}

		$deletable = array( 'pending', 'scheduled', 'failed', 'expired', 'cancelled', 'sent' );
		if ( ! in_array( $push->status, $deletable, true ) ) {
			return new WP_Error( 'not_deletable', 'Cannot delete a push that is currently sending.' );
		}

		$wpdb->delete( $wpdb->prefix . self::TABLE_HISTORY, array( 'push_id' => $push_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . self::TABLE_PUSH, array( 'id' => $push_id ), array( '%d' ) );

		Bcsend_Logger::log( 'push', sprintf( 'Push %d deleted.', $push_id ) );

		return true;
	}

	/**
	 * Cancel a scheduled push notification.
	 *
	 * @param int $push_id Push ID.
	 * @return true|WP_Error
	 */
	public static function cancel( $push_id ) {
		global $wpdb;

		$push = self::get_push( $push_id );
		if ( ! $push ) {
			return new WP_Error( 'not_found', 'Push notification not found.' );
		}

		if ( 'scheduled' !== $push->status ) {
			return new WP_Error( 'not_cancellable', 'Only scheduled push notifications can be cancelled.' );
		}

		$wpdb->update(
			$wpdb->prefix . self::TABLE_PUSH,
			array( 'status' => 'cancelled' ),
			array( 'id' => $push_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Unschedule Action Scheduler job.
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( 'bcsend_standalone_push', array( 'push_id' => $push_id ), 'beacon-campaign-sender' );
		}

		Bcsend_Logger::log( 'push', sprintf( 'Push %d cancelled.', $push_id ) );

		return true;
	}

	// =========================================================================
	// Expiry
	// =========================================================================

	/**
	 * Mark overdue scheduled pushes as expired.
	 */
	public static function expire_overdue() {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_PUSH;
		$now_utc = gmdate( 'Y-m-d H:i:s' );

		$expired = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired' WHERE status = 'scheduled' AND expires_at IS NOT NULL AND expires_at < %s",
				$now_utc
			)
		);

		if ( $expired > 0 ) {
			Bcsend_Logger::log( 'push', sprintf( '%d overdue push notifications expired.', $expired ) );
		}
	}

	// =========================================================================
	// Recipient resolution (private helpers)
	// =========================================================================

	/**
	 * Resolve recipient user IDs based on target type.
	 *
	 * @param string     $target_type Target type.
	 * @param array|null $target_data Decoded target data.
	 * @return array User IDs.
	 */
	public static function resolve_recipients( $target_type, $target_data ) {
		switch ( $target_type ) {
			case 'by_role':
				return self::resolve_by_role( is_array( $target_data ) ? $target_data : array() );

			case 'specific_users':
				return self::resolve_specific_users( is_array( $target_data ) ? $target_data : array() );

			case 'all_users':
			default:
				return self::resolve_all_users();
		}
	}

	/**
	 * Get all user IDs that have device tokens.
	 *
	 * @return array
	 */
	private static function resolve_all_users() {
		global $wpdb;

		$device_table = $wpdb->prefix . 'bbapp_user_devices';

		// Check table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $device_table ) );
		if ( ! $exists ) {
			return array();
		}

		$results = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$device_table} WHERE device_token != ''"
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Get user IDs for specific WordPress roles that have device tokens.
	 *
	 * @param array $roles Array of role slugs.
	 * @return array
	 */
	private static function resolve_by_role( $roles ) {
		if ( empty( $roles ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role__in' => array_map( 'sanitize_text_field', $roles ),
				'fields'   => 'ID',
			)
		);

		if ( empty( $users ) ) {
			return array();
		}

		// Filter to only users with device tokens.
		global $wpdb;
		$device_table = $wpdb->prefix . 'bbapp_user_devices';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $device_table ) );
		if ( ! $exists ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $users ), '%d' ) );

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$device_table} WHERE user_id IN ({$placeholders}) AND device_token != ''",
				...$users
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Validate specific user IDs have device tokens.
	 *
	 * @param array $user_ids Array of user IDs.
	 * @return array Filtered user IDs with tokens.
	 */
	private static function resolve_specific_users( $user_ids ) {
		if ( empty( $user_ids ) ) {
			return array();
		}

		$user_ids = array_map( 'intval', $user_ids );

		global $wpdb;
		$device_table = $wpdb->prefix . 'bbapp_user_devices';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $device_table ) );
		if ( ! $exists ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$device_table} WHERE user_id IN ({$placeholders}) AND device_token != ''",
				...$user_ids
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Check if there are still pending Action Scheduler batches for a push.
	 *
	 * @param int $push_id Push ID.
	 * @return bool
	 */
	private static function has_pending_batches( $push_id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => 'bcsend_standalone_push_batch',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( 'push_id' => $push_id ),
				'group'  => 'beacon-campaign-sender',
			)
		);

		return ! empty( $pending );
	}
}
