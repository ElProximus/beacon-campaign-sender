<?php
/**
 * Ability: beacon-campaign-sender/schedule-campaign
 *
 * Schedule an existing draft or approved campaign for delivery.
 * Freezes the send configuration and creates the Brevo campaign if needed.
 *
 * @package Bcsend_Plugin
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_init',
	function () {
		$settings = get_option( 'bcsend_settings', array() );
		if ( empty( $settings['abilities_bridge_enabled'] ) ) {
			return;
		}

		wp_register_ability(
			'beacon-campaign-sender/schedule-campaign',
			array(
				'label'               => __( 'Schedule Campaign', 'beacon-campaign-sender' ),
				'description'         => 'Schedule an existing draft or approved campaign for delivery at a specific time. Freezes the send configuration and creates the Brevo campaign if needed.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'campaign_id'         => array(
							'type'        => 'integer',
							'description' => 'Campaign ID to schedule.',
						),
						'scheduled_at'        => array(
							'type'        => 'string',
							'description' => 'ISO 8601 datetime for when to send the campaign.',
						),
						'tz_offset'           => array(
							'type'        => 'integer',
							'description' => 'Timezone offset in minutes (matches JS Date.getTimezoneOffset). Only required when scheduled_at is a bare local datetime with no timezone indicator. Omit when scheduled_at already includes a UTC suffix (Z) or offset (+/-HH:MM) — the server detects the indicator and skips conversion automatically.',
						),
						'social_scheduled_at' => array(
							'type'        => 'string',
							'description' => 'Optional dedicated social scheduled datetime.',
						),
					),
					'required'             => array( 'campaign_id', 'scheduled_at' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'campaign_id'  => array( 'type' => 'integer' ),
						'status'       => array( 'type' => 'string' ),
						'scheduled_at' => array( 'type' => 'string' ),
					),
				),

				'execute_callback'    => 'bcsend_ability_schedule_campaign',
				'permission_callback' => function () {
					return current_user_can( 'operate_bcsend_campaigns' );
				},

				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'ai_enabled'  => true,
				),
			)
		);
	}
);

/**
 * Schedule a campaign for delivery.
 *
 * Freezes the send configuration snapshot and creates the Brevo
 * campaign if one does not yet exist.
 *
 * @param array $input {
 *     @type int    $campaign_id  Required campaign ID.
 *     @type string $scheduled_at Required ISO 8601 datetime.
 * }
 * @return array|WP_Error Schedule result or WP_Error.
 */
function bcsend_ability_schedule_campaign( $input = array() ) {
	global $wpdb;

	$campaign_id         = isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0;
	$scheduled_at        = isset( $input['scheduled_at'] ) ? sanitize_text_field( $input['scheduled_at'] ) : '';
	$tz_offset           = isset( $input['tz_offset'] ) ? (int) $input['tz_offset'] : null;
	$social_scheduled_at = isset( $input['social_scheduled_at'] ) ? sanitize_text_field( $input['social_scheduled_at'] ) : '';

	if ( empty( $campaign_id ) || empty( $scheduled_at ) ) {
		return new WP_Error( 'missing_params', 'Both campaign_id and scheduled_at are required.' );
	}

	$table    = $wpdb->prefix . 'bcsend_campaigns';
	$campaign = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $campaign_id )
	);

	if ( empty( $campaign ) ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
	}

	$allowed_statuses = array( 'draft', 'approved' );

	if ( ! in_array( $campaign->status, $allowed_statuses, true ) ) {
		return new WP_Error(
			'invalid_status',
			sprintf( 'Campaign status is "%s"; must be draft or approved to schedule.', $campaign->status )
		);
	}

	if ( ! empty( $campaign->send_email ) && empty( $campaign->segment_id ) ) {
		return new WP_Error(
			'missing_segment',
			'Campaign must have a target segment before scheduling. Assign a segment first.'
		);
	}

	if ( ! empty( $campaign->send_social ) ) {
		$social_validation = Bcsend_Social_Workflow::validate_campaign_rows( $campaign, true );
		if ( ! empty( $social_validation['errors'] ) ) {
			return new WP_Error(
				'invalid_social_payload',
				implode( ' ', $social_validation['errors'] )
			);
		}
	}

	// Parse the scheduled time. The browser sends local time — convert to UTC
	// using the browser's timezone offset if provided.
	// JS Date.getTimezoneOffset() returns minutes *ahead* of UTC as positive,
	// e.g. UTC-5 (Eastern) returns 300. To convert local→UTC: add the offset.
	//
	// If the input already carries a timezone indicator (Z, +HH:MM, -HH:MM),
	// strtotime() handles the conversion itself — skip the offset to avoid
	// double-converting.
	$timestamp = strtotime( $scheduled_at );

	if ( false === $timestamp ) {
		return new WP_Error( 'invalid_scheduled_at', 'Could not parse the scheduled time.' );
	}

	if ( null !== $tz_offset && ! Bcsend_Scheduler::has_timezone_indicator( $scheduled_at ) ) {
		$timestamp += $tz_offset * 60;
	}

	if ( $timestamp < ( time() - 120 ) ) {
		return new WP_Error( 'invalid_scheduled_at', 'scheduled_at must be a valid future datetime.' );
	}

	// Freeze send configuration snapshot.
	$settings = get_option( 'bcsend_settings', array() );
	$snapshot = array(
		'html_content'       => $campaign->html_content,
		'plain_text'         => $campaign->plain_text,
		'sender_name'        => isset( $settings['brevo_sender_name'] ) ? $settings['brevo_sender_name'] : '',
		'sender_email'       => isset( $settings['brevo_sender_email'] ) ? $settings['brevo_sender_email'] : '',
		'brevo_sender_name'  => isset( $settings['brevo_sender_name'] ) ? $settings['brevo_sender_name'] : '',
		'brevo_sender_email' => isset( $settings['brevo_sender_email'] ) ? $settings['brevo_sender_email'] : '',
		'push_mode'          => isset( $settings['push_mode'] ) ? $settings['push_mode'] : 'auto',
		'frozen_at'          => current_time( 'mysql', true ),
	);

	// Validate/sync the Brevo list now, but defer campaign create/update until send time.
	$brevo_campaign_id = ! empty( $campaign->brevo_campaign_id ) ? (int) $campaign->brevo_campaign_id : 0;

	if ( ! empty( $campaign->send_email ) && ! empty( $campaign->segment_id ) ) {
		$brevo = new Bcsend_Brevo_API();

		if ( $brevo->is_configured() ) {
			$segment = Bcsend_Segment_Engine::get_segment( $campaign->segment_id );

			// Auto-sync the segment to Brevo if it has no list yet.
			if ( $segment && empty( $segment->brevo_list_id ) ) {
				$sync_result = Bcsend_Segment_Engine::sync_to_brevo( $segment->id );

				if ( ! is_wp_error( $sync_result ) ) {
					// Reload segment to get the new brevo_list_id.
					$segment = Bcsend_Segment_Engine::get_segment( $campaign->segment_id );
				}
			}

			$list_ids = array();

			if ( $segment && ! empty( $segment->brevo_list_id ) ) {
				$list_ids[] = (int) $segment->brevo_list_id;
			}

			if ( empty( $list_ids ) ) {
				return new WP_Error( 'no_brevo_list', 'Segment has no Brevo contact list. Sync the segment first from the Audiences page.' );
			}
		}
	}

	// Update campaign record.
	$update_data   = array(
		'status'               => 'scheduled',
		'scheduled_at'         => gmdate( 'Y-m-d H:i:s', $timestamp ),
		'send_config_snapshot' => wp_json_encode( $snapshot ),
	);
	$update_format = array( '%s', '%s', '%s' );

	if ( empty( $campaign->approved_at ) ) {
		$update_data['approved_at'] = current_time( 'mysql', true );
		$update_format[]            = '%s';
	}

	if ( ! empty( $social_scheduled_at ) ) {
		$update_data['social_scheduled_at'] = $social_scheduled_at;
		$update_format[]                    = '%s';
	}

	if ( $brevo_campaign_id > 0 ) {
		$update_data['brevo_campaign_id'] = $brevo_campaign_id;
		$update_format[]                  = '%d';
	}

	$wpdb->update(
		$table,
		$update_data,
		array( 'id' => $campaign_id ),
		$update_format,
		array( '%d' )
	);

	if ( ! empty( $social_scheduled_at ) ) {
		$social_table = $wpdb->prefix . 'bcsend_social_posts';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$social_table} SET scheduled_for = %s WHERE campaign_id = %d",
				$social_scheduled_at,
				$campaign_id
			)
		);
	}

	// Schedule the send job.
	Bcsend_Scheduler::schedule_campaign( $campaign_id, $timestamp );

	Bcsend_Logger::log(
		'abilities',
		'schedule_campaign succeeded',
		wp_json_encode(
			array(
				'campaign_id'  => $campaign_id,
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			)
		)
	);

	return array(
		'campaign_id'  => $campaign_id,
		'status'       => 'scheduled',
		'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
	);
}
