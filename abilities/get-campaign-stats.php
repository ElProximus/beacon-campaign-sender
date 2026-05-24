<?php
/**
 * Ability: beacon-campaign-sender/get-campaign-stats
 *
 * Get detailed statistics for a specific campaign including email
 * open/click rates and push delivery metrics.
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
			'beacon-campaign-sender/get-campaign-stats',
			array(
				'label'               => __( 'Get Campaign Stats', 'beacon-campaign-sender' ),
				'description'         => 'Get detailed statistics for a specific campaign including email open/click rates and push delivery metrics.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'campaign_id' => array(
							'type'        => 'integer',
							'description' => 'Campaign ID to get statistics for.',
						),
					),
					'required'             => array( 'campaign_id' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'email_open_rate'    => array(
							'type'        => array( 'number', 'null' ),
							'description' => 'Unique email open rate.',
						),
						'email_click_rate'   => array(
							'type'        => array( 'number', 'null' ),
							'description' => 'Unique email click rate.',
						),
						'email_unsubscribes' => array(
							'type'        => array( 'integer', 'null' ),
							'description' => 'Email unsubscribe count.',
						),
						'push_delivered'     => array(
							'type'        => 'integer',
							'description' => 'Total push notifications delivered.',
						),
						'push_tap_rate'      => array(
							'type'        => array( 'number', 'null' ),
							'description' => 'Push notification tap rate.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_get_campaign_stats',
				'permission_callback' => function () {
					return current_user_can( 'view_bcsend_logs' );
				},

				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
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
 * Get detailed statistics for a campaign.
 *
 * @param array $input {
 *     @type int $campaign_id Required campaign ID.
 * }
 * @return array|WP_Error Campaign statistics or WP_Error.
 */
function bcsend_ability_get_campaign_stats( $input = array() ) {
	global $wpdb;

	$campaign_id = isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0;

	if ( empty( $campaign_id ) ) {
		return new WP_Error( 'missing_campaign_id', 'The campaign_id parameter is required.' );
	}

	$table    = $wpdb->prefix . 'bcsend_campaigns';
	$campaign = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $campaign_id )
	);

	if ( empty( $campaign ) ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
	}

	$stats = array(
		'email_open_rate'    => null,
		'email_click_rate'   => null,
		'email_unsubscribes' => null,
		'push_delivered'     => 0,
		'push_tap_rate'      => null,
	);

	// Fetch email stats from Brevo.
	if ( ! empty( $campaign->brevo_campaign_id ) ) {
		$brevo       = new Bcsend_Brevo_API();
		$brevo_stats = $brevo->get_campaign_stats( (int) $campaign->brevo_campaign_id );

		if ( ! is_wp_error( $brevo_stats ) && is_array( $brevo_stats ) ) {
			$extracted = Bcsend_Brevo_API::extract_campaign_stats( $brevo_stats );

			$stats['email_open_rate']    = $extracted['open_rate'];
			$stats['email_click_rate']   = $extracted['click_rate'];
			$stats['email_unsubscribes'] = $extracted['unsubscriptions'];
		}
	}

	// Read push delivery stats from logs.
	$log_table = $wpdb->prefix . 'bcsend_logs';

	$push_logs = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT payload FROM {$log_table}
			WHERE type = 'push_batch'
				AND payload LIKE %s",
			'%"campaign_id":' . $campaign_id . '%'
		)
	);

	$total_push_sent = 0;

	foreach ( $push_logs as $log ) {
		$ctx = json_decode( $log->payload, true );
		if ( is_array( $ctx ) && isset( $ctx['sent'] ) ) {
			$total_push_sent += (int) $ctx['sent'];
		}
	}

	$stats['push_delivered'] = $total_push_sent;

	Bcsend_Logger::log(
		'abilities',
		'get_campaign_stats succeeded',
		wp_json_encode( array( 'campaign_id' => $campaign_id ) )
	);

	return $stats;
}
