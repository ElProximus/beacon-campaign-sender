<?php
/**
 * Campaign Sender for Beacon Campaign Sender.
 *
 * Orchestrates the multi-channel delivery of campaigns: email via Brevo
 * and push notifications via the BuddyBoss push service. Tracks independent
 * status for each channel and determines the final campaign outcome.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Campaign_Sender
 *
 * Static methods for sending, completing, and cancelling campaigns.
 *
 * @since 1.0.0
 */
class Bcsend_Campaign_Sender {

	/**
	 * Send a campaign through all configured channels.
	 *
	 * Executes email delivery via Brevo and push notification delivery
	 * via the BuddyBoss push service. Each channel is tracked independently
	 * through email_status and push_status fields.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return array|WP_Error Result array or WP_Error on critical failure.
	 */
	public static function send( $campaign_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'bcsend_campaigns';
		$campaign = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				(int) $campaign_id
			)
		);

		if ( empty( $campaign ) ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Campaign not found.',
				wp_json_encode(
					array(
						'error'       => 'Campaign not found.',
						'campaign_id' => $campaign_id,
					)
				),
				'error'
			);
			return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
		}

		$allowed_statuses = array( 'scheduled', 'queued' );

		if ( ! in_array( $campaign->status, $allowed_statuses, true ) ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Invalid campaign status for sending.',
				wp_json_encode(
					array(
						'error'       => 'Invalid campaign status for sending.',
						'campaign_id' => $campaign_id,
						'status'      => $campaign->status,
					)
				),
				'error'
			);
			return new WP_Error(
				'invalid_campaign_status',
				sprintf( 'Campaign status is "%s"; must be scheduled or queued.', $campaign->status )
			);
		}

		// Mark campaign as sending, set queued_at if not already set.
		$update_fields = array( 'status' => 'sending' );
		$update_format = array( '%s' );

		if ( empty( $campaign->queued_at ) ) {
			$update_fields['queued_at'] = current_time( 'mysql', true );
			$update_format[]            = '%s';
		}

		$wpdb->update( $table, $update_fields, array( 'id' => $campaign_id ), $update_format, array( '%d' ) );

		Bcsend_Logger::log(
			'campaign_send',
			'Campaign send started',
			wp_json_encode(
				array(
					'action'      => 'send_started',
					'campaign_id' => $campaign_id,
				)
			)
		);

		// ---- Step 1: Send email via Brevo ---- //
		if ( ! isset( $campaign->send_email ) || ! empty( $campaign->send_email ) ) {
			self::send_email( $campaign_id, $campaign );
		} else {
			$wpdb->update(
				$table,
				array( 'email_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// ---- Step 2: Send push notifications (if enabled) ---- //
		if ( ! empty( $campaign->send_push ) ) {
			self::send_push( $campaign_id, $campaign );
		} else {
			$wpdb->update(
				$table,
				array( 'push_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// ---- Step 3: Send social posts (if enabled) ---- //
		if ( ! empty( $campaign->send_social ) ) {
			self::send_social( $campaign_id, $campaign );
		} else {
			$wpdb->update(
				$table,
				array( 'social_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// ---- Step 4: Determine final status ---- //
		self::finalize_campaign_status( $campaign_id );

		// Reload and return final state.
		$final = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				(int) $campaign_id
			)
		);

		Bcsend_Logger::log(
			'campaign_send',
			'Campaign send completed',
			wp_json_encode(
				array(
					'action'        => 'send_completed',
					'campaign_id'   => $campaign_id,
					'status'        => $final->status,
					'email_status'  => $final->email_status,
					'push_status'   => $final->push_status,
					'social_status' => $final->social_status,
				)
			)
		);

		return array(
			'campaign_id'   => $campaign_id,
			'status'        => $final->status,
			'email_status'  => $final->email_status,
			'push_status'   => $final->push_status,
			'social_status' => $final->social_status,
		);
	}

	/**
	 * Send the email portion of a campaign via Brevo.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param object $campaign    Campaign row object.
	 *
	 * @return void
	 */
	private static function send_email( $campaign_id, $campaign ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';

		$wpdb->update(
			$table,
			array( 'email_status' => 'sending' ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		$brevo = new Bcsend_Brevo_API();

		if ( ! $brevo->is_configured() ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Email skipped: Brevo API not configured.',
				wp_json_encode(
					array(
						'campaign_id' => $campaign_id,
						'email'       => 'skipped',
						'reason'      => 'Brevo API not configured.',
					)
				)
			);

			$wpdb->update(
				$table,
				array( 'email_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		$brevo_campaign_id = ! empty( $campaign->brevo_campaign_id ) ? (int) $campaign->brevo_campaign_id : 0;

		// Create the Brevo campaign if one does not yet exist.
		if ( empty( $brevo_campaign_id ) ) {
			$snapshot = ! empty( $campaign->send_config_snapshot )
				? json_decode( $campaign->send_config_snapshot, true )
				: array();

			$segment = ! empty( $campaign->segment_id )
				? Bcsend_Segment_Engine::get_segment( $campaign->segment_id )
				: null;

			$list_ids = array();
			if ( $segment && ! empty( $segment->brevo_list_id ) ) {
				$list_ids[] = (int) $segment->brevo_list_id;
			}

			$create_data = array(
				'name'        => ! empty( $campaign->name ) ? $campaign->name : 'Campaign ' . $campaign_id,
				'subject'     => ! empty( $campaign->subject ) ? $campaign->subject : '',
				'htmlContent' => isset( $snapshot['html_content'] ) && '' !== $snapshot['html_content']
					? $snapshot['html_content']
					: ( ! empty( $campaign->html_content ) ? $campaign->html_content : '' ),
				'recipients'  => array( 'listIds' => $list_ids ),
				'replyTo'     => ! empty( $campaign->reply_to ) ? $campaign->reply_to : '',
			);

			$create_response = $brevo->create_campaign( $create_data );

			if ( is_wp_error( $create_response ) ) {
				Bcsend_Logger::log(
					'campaign_send',
					'Email failed: Failed to create Brevo campaign: ',
					wp_json_encode(
						array(
							'campaign_id' => $campaign_id,
							'email'       => 'failed',
							'reason'      => 'Failed to create Brevo campaign: ' . $create_response->get_error_message(),
						)
					)
				);

				$wpdb->update(
					$table,
					array(
						'email_status'  => 'failed',
						'attempt_count' => (int) $campaign->attempt_count + 1,
						'last_error'    => $create_response->get_error_message(),
					),
					array( 'id' => $campaign_id ),
					array( '%s', '%d', '%s' ),
					array( '%d' )
				);
				return;
			}

			$brevo_campaign_id = isset( $create_response['id'] ) ? (int) $create_response['id'] : 0;

			$wpdb->update(
				$table,
				array( 'brevo_campaign_id' => $brevo_campaign_id ),
				array( 'id' => $campaign_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		// Send the campaign.
		$send_response = $brevo->send_campaign( $brevo_campaign_id );

		if ( is_wp_error( $send_response ) ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Email failed: unknown reason',
				wp_json_encode(
					array(
						'campaign_id'       => $campaign_id,
						'brevo_campaign_id' => $brevo_campaign_id,
						'email'             => 'failed',
						'reason'            => $send_response->get_error_message(),
					)
				)
			);

			$wpdb->update(
				$table,
				array(
					'email_status'  => 'failed',
					'attempt_count' => (int) $campaign->attempt_count + 1,
					'last_error'    => $send_response->get_error_message(),
				),
				array( 'id' => $campaign_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return;
		}

		Bcsend_Logger::log(
			'campaign_send',
			'Email sent via Brevo',
			wp_json_encode(
				array(
					'campaign_id'       => $campaign_id,
					'brevo_campaign_id' => $brevo_campaign_id,
					'email'             => 'sent',
				)
			)
		);

		$wpdb->update(
			$table,
			array( 'email_status' => 'sent' ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Send the push notification portion of a campaign.
	 *
	 * Uses Action Scheduler for batched delivery when available,
	 * falls back to synchronous sending.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param object $campaign    Campaign row object.
	 *
	 * @return void
	 */
	private static function send_push( $campaign_id, $campaign ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';

		$wpdb->update(
			$table,
			array( 'push_status' => 'sending' ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		$push_service = new Bcsend_Push_Service();

		if ( ! $push_service->is_configured() ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Push skipped: Push service not configured.',
				wp_json_encode(
					array(
						'campaign_id' => $campaign_id,
						'push'        => 'skipped',
						'reason'      => 'Push service not configured.',
					)
				)
			);

			$wpdb->update(
				$table,
				array( 'push_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		// Resolve recipients using the same target model as the standalone
		// push screen. Decoupled from Brevo segments because push delivery
		// relies on local WP user IDs (→ BuddyBoss device tokens), not
		// remote contact lists.
		$target_type = ! empty( $campaign->push_target_type ) ? (string) $campaign->push_target_type : 'all_users';
		$target_data = array();
		if ( ! empty( $campaign->push_target_data ) ) {
			$decoded     = json_decode( $campaign->push_target_data, true );
			$target_data = is_array( $decoded ) ? $decoded : array();
		}

		$user_ids = Bcsend_Push_Manager::resolve_recipients( $target_type, $target_data );

		if ( empty( $user_ids ) ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Push skipped: No user IDs found for segment.',
				wp_json_encode(
					array(
						'campaign_id' => $campaign_id,
						'push'        => 'skipped',
						'reason'      => 'No user IDs found for segment.',
					)
				)
			);

			$wpdb->update(
				$table,
				array( 'push_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		$tokens = $push_service->get_tokens_for_users( $user_ids );

		if ( empty( $tokens ) ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Push skipped: No push tokens found for segment users.',
				wp_json_encode(
					array(
						'campaign_id' => $campaign_id,
						'push'        => 'skipped',
						'reason'      => 'No push tokens found for segment users.',
					)
				)
			);

			$wpdb->update(
				$table,
				array( 'push_status' => 'skipped' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		$push_title   = ! empty( $campaign->push_title ) ? $campaign->push_title : '';
		$push_message = ! empty( $campaign->push_message ) ? $campaign->push_message : '';
		$batch_size   = 500;
		$batches      = array_chunk( $tokens, $batch_size );

		Bcsend_Logger::log(
			'campaign_send',
			'Push dispatching batches',
			wp_json_encode(
				array(
					'campaign_id'  => $campaign_id,
					'push'         => 'dispatching_batches',
					'total_tokens' => count( $tokens ),
					'batch_count'  => count( $batches ),
				)
			)
		);

		// Prefer Action Scheduler for batched async delivery.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			foreach ( $batches as $index => $batch_tokens ) {
				Bcsend_Scheduler::schedule_push_batch(
					$campaign_id,
					$index + 1,
					$batch_tokens,
					$push_title,
					$push_message
				);
			}

			// Push status remains 'sending' until all batch jobs complete.
			Bcsend_Logger::log(
				'campaign_send',
				'Push batches scheduled',
				wp_json_encode(
					array(
						'campaign_id' => $campaign_id,
						'push'        => 'batches_scheduled',
						'batch_count' => count( $batches ),
					)
				)
			);
		} else {
			// Synchronous fallback: send all batches immediately.
			$total_sent   = 0;
			$total_failed = 0;

			foreach ( $batches as $index => $batch_tokens ) {
				$result = $push_service->send_batch( $batch_tokens, $push_title, $push_message );

				if ( is_array( $result ) ) {
					$total_sent   += isset( $result['sent'] ) ? (int) $result['sent'] : 0;
					$total_failed += isset( $result['failed'] ) ? (int) $result['failed'] : 0;
				}

				Bcsend_Logger::log(
					'push_batch',
					'Push batch processed',
					wp_json_encode(
						array(
							'campaign_id' => $campaign_id,
							'batch'       => $index + 1,
							'total'       => count( $batch_tokens ),
							'sent'        => isset( $result['sent'] ) ? $result['sent'] : 0,
							'failed'      => isset( $result['failed'] ) ? $result['failed'] : 0,
						)
					)
				);
			}

			$push_final_status = ( $total_failed > 0 && 0 === $total_sent ) ? 'failed' : 'sent';

			$wpdb->update(
				$table,
				array( 'push_status' => $push_final_status ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);

			Bcsend_Logger::log(
				'campaign_send',
				'Campaign send event',
				wp_json_encode(
					array(
						'campaign_id'  => $campaign_id,
						'push'         => $push_final_status,
						'total_sent'   => $total_sent,
						'total_failed' => $total_failed,
					)
				)
			);
		}
	}

	/**
	 * Complete push batch processing after all batches finish.
	 *
	 * Called by the scheduler after the last push batch job completes.
	 * Aggregates batch results and updates push_status, then finalizes
	 * the overall campaign status.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return void
	 */
	public static function complete_push_batches( $campaign_id ) {
		global $wpdb;

		// Check if there are still pending batch jobs.
		if ( Bcsend_Scheduler::has_pending_batches( $campaign_id ) ) {
			Bcsend_Logger::log(
				'campaign_send',
				'Checking push batch completion',
				wp_json_encode(
					array(
						'campaign_id' => $campaign_id,
						'action'      => 'complete_push_batches',
						'status'      => 'pending_batches_remain',
					)
				)
			);
			return;
		}

		$table = $wpdb->prefix . 'bcsend_campaigns';

		// Determine push outcome from logs. If any batch logged a failure
		// and none succeeded, mark as failed. Otherwise mark as sent.
		$log_table = $wpdb->prefix . 'bcsend_logs';

		$batch_logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT payload FROM {$log_table}
				WHERE type = 'push_batch'
					AND payload LIKE %s
				ORDER BY id ASC",
				'%"campaign_id":' . (int) $campaign_id . '%'
			)
		);

		$total_sent   = 0;
		$total_failed = 0;

		foreach ( $batch_logs as $log ) {
			$ctx = json_decode( $log->payload, true );
			if ( is_array( $ctx ) ) {
				$total_sent   += isset( $ctx['sent'] ) ? (int) $ctx['sent'] : 0;
				$total_failed += isset( $ctx['failed'] ) ? (int) $ctx['failed'] : 0;
			}
		}

		$push_status = ( $total_failed > 0 && 0 === $total_sent ) ? 'failed' : 'sent';

		$wpdb->update(
			$table,
			array( 'push_status' => $push_status ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log(
			'campaign_send',
			'Push batches completed',
			wp_json_encode(
				array(
					'campaign_id'  => $campaign_id,
					'action'       => 'push_batches_completed',
					'push_status'  => $push_status,
					'total_sent'   => $total_sent,
					'total_failed' => $total_failed,
				)
			)
		);

		self::finalize_campaign_status( $campaign_id );
	}

	/**
	 * Determine and set the final campaign status based on channel statuses.
	 *
	 * Rules:
	 * - If either channel is still 'sending', do not change overall status.
	 * - If either channel failed, set status to 'failed'.
	 * - If both channels are 'sent' or 'skipped', set status to 'sent' with sent_at.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return void
	 */
	private static function finalize_campaign_status( $campaign_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'bcsend_campaigns';
		$campaign = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				(int) $campaign_id
			)
		);

		if ( empty( $campaign ) ) {
			return;
		}

		$email_status  = ! empty( $campaign->email_status ) ? $campaign->email_status : '';
		$push_status   = ! empty( $campaign->push_status ) ? $campaign->push_status : '';
		$social_status = ! empty( $campaign->social_status ) ? $campaign->social_status : '';

		$terminal_success = array( 'sent', 'skipped', 'published', 'scheduled', 'partial' );
		$email_done       = in_array( $email_status, $terminal_success, true );
		$push_done        = in_array( $push_status, $terminal_success, true );
		$social_done      = in_array( $social_status, $terminal_success, true );
		$email_failed     = 'failed' === $email_status;
		$push_failed      = 'failed' === $push_status;
		$social_failed    = 'failed' === $social_status;

		if ( $email_failed || $push_failed || $social_failed ) {
			$wpdb->update(
				$table,
				array( 'status' => 'failed' ),
				array( 'id' => $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);

			Bcsend_Logger::log(
				'campaign_send',
				'Campaign finalized as failed',
				wp_json_encode(
					array(
						'campaign_id'   => $campaign_id,
						'action'        => 'finalize',
						'status'        => 'failed',
						'email_status'  => $email_status,
						'push_status'   => $push_status,
						'social_status' => $social_status,
					)
				)
			);
			return;
		}

		if ( $email_done && $push_done && $social_done ) {
			$wpdb->update(
				$table,
				array(
					'status'  => 'sent',
					'sent_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $campaign_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			Bcsend_Logger::log(
				'campaign_send',
				'Campaign finalized as sent',
				wp_json_encode(
					array(
						'campaign_id'   => $campaign_id,
						'action'        => 'finalize',
						'status'        => 'sent',
						'email_status'  => $email_status,
						'push_status'   => $push_status,
						'social_status' => $social_status,
					)
				)
			);
			return;
		}

		// At least one channel is still 'sending' (async push batches).
		Bcsend_Logger::log(
			'campaign_send',
			'Campaign still sending',
			wp_json_encode(
				array(
					'campaign_id'   => $campaign_id,
					'action'        => 'finalize',
					'status'        => 'still_sending',
					'email_status'  => $email_status,
					'push_status'   => $push_status,
					'social_status' => $social_status,
				)
			)
		);
	}

	/**
	 * Send the social portion of a campaign via Zernio.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param object $campaign    Campaign row object.
	 * @return void
	 */
	private static function send_social( $campaign_id, $campaign ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';

		$wpdb->update(
			$table,
			array( 'social_status' => 'sending' ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		$result = Bcsend_Social_Sender::send_for_campaign( $campaign_id );

		$final_status = 'skipped';
		if ( ! empty( $result['failed'] ) && empty( $result['sent'] ) ) {
			$final_status = 'failed';
		} elseif ( ! empty( $result['partial'] ) || ( ! empty( $result['failed'] ) && ! empty( $result['sent'] ) ) ) {
			$final_status = 'partial';
		} elseif ( ! empty( $result['sent'] ) ) {
			$final_status = 'sent';
		}

		$wpdb->update(
			$table,
			array( 'social_status' => $final_status ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log(
			'campaign_send',
			'Social send completed',
			wp_json_encode(
				array(
					'campaign_id'   => $campaign_id,
					'social_status' => $final_status,
					'result'        => $result,
				)
			)
		);
	}

	/**
	 * Cancel a campaign.
	 *
	 * Only campaigns in draft, approved, or scheduled status can be cancelled.
	 * Also unschedules any pending Action Scheduler jobs.
	 *
	 * @since 1.0.0
	 *
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function cancel( $campaign_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'bcsend_campaigns';
		$campaign = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				(int) $campaign_id
			)
		);

		if ( empty( $campaign ) ) {
			return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
		}

		$cancellable = array( 'draft', 'approved', 'scheduled' );

		if ( ! in_array( $campaign->status, $cancellable, true ) ) {
			return new WP_Error(
				'campaign_not_cancellable',
				sprintf( 'Campaign status is "%s"; cannot cancel.', $campaign->status )
			);
		}

		$wpdb->update(
			$table,
			array( 'status' => 'cancelled' ),
			array( 'id' => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Unschedule any pending jobs.
		Bcsend_Scheduler::unschedule_campaign( $campaign_id );

		Bcsend_Logger::log(
			'campaign_send',
			'Campaign cancelled',
			wp_json_encode(
				array(
					'action'      => 'cancelled',
					'campaign_id' => $campaign_id,
				)
			)
		);

		return true;
	}
}
