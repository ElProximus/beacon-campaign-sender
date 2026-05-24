<?php
/**
 * Ability: beacon-campaign-sender/get-analytics
 *
 * Get aggregate analytics including total sends, average open/click
 * rates, and push delivery totals over a configurable period.
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
			'beacon-campaign-sender/get-analytics',
			array(
				'label'               => __( 'Get Analytics', 'beacon-campaign-sender' ),
				'description'         => 'Get aggregate analytics including total sends, average open/click rates, and push delivery totals.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'days' => array(
							'type'        => 'integer',
							'description' => 'Number of days to look back. Default 30, max 365.',
							'minimum'     => 1,
							'maximum'     => 365,
						),
					),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_sends'          => array(
							'type'        => 'integer',
							'description' => 'Campaigns sent in period.',
						),
						'average_open_rate'    => array(
							'type'        => 'number',
							'description' => 'Average unique open rate.',
						),
						'average_click_rate'   => array(
							'type'        => 'number',
							'description' => 'Average unique click rate.',
						),
						'total_push_delivered' => array(
							'type'        => 'integer',
							'description' => 'Total push notifications delivered.',
						),
						'period_days'          => array(
							'type'        => 'integer',
							'description' => 'Actual period used.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_get_analytics',
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
 * Get aggregate analytics for a period.
 *
 * @param array $input {
 *     @type int $days Number of days to look back (default 30, max 365).
 * }
 * @return array Analytics summary.
 */
function bcsend_ability_get_analytics( $input = array() ) {
	global $wpdb;

	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	$days = max( 1, min( 365, $days ) );

	$table     = $wpdb->prefix . 'bcsend_campaigns';
	$log_table = $wpdb->prefix . 'bcsend_logs';

	// Count campaigns sent in period.
	$total_sends = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			WHERE status = 'sent'
				AND sent_at >= DATE_SUB( NOW(), INTERVAL %d DAY )",
			$days
		)
	);

	// Get Brevo campaign IDs for sent campaigns in the period.
	$brevo_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT brevo_campaign_id FROM {$table}
			WHERE status = 'sent'
				AND sent_at >= DATE_SUB( NOW(), INTERVAL %d DAY )
				AND brevo_campaign_id IS NOT NULL
				AND brevo_campaign_id > 0",
			$days
		)
	);

	$total_opens  = 0;
	$total_clicks = 0;
	$stat_count   = 0;

	if ( ! empty( $brevo_ids ) ) {
		$brevo = new Bcsend_Brevo_API();

		if ( $brevo->is_configured() ) {
			foreach ( $brevo_ids as $brevo_campaign_id ) {
				$stats = $brevo->get_campaign_stats( (int) $brevo_campaign_id );

				if ( ! is_wp_error( $stats ) && is_array( $stats ) ) {
					$extracted = Bcsend_Brevo_API::extract_campaign_stats( $stats );

					if ( $extracted['open_rate'] !== null ) {
						$total_opens += $extracted['open_rate'];
						++$stat_count;
					}
					if ( $extracted['click_rate'] !== null ) {
						$total_clicks += $extracted['click_rate'];
					}
				}
			}
		}
	}

	$average_open_rate  = $stat_count > 0 ? round( $total_opens / $stat_count, 2 ) : 0;
	$average_click_rate = $stat_count > 0 ? round( $total_clicks / $stat_count, 2 ) : 0;

	// Get total push delivered from logs in period.
	$push_logs = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT payload FROM {$log_table}
			WHERE type = 'push_batch'
				AND created_at >= DATE_SUB( NOW(), INTERVAL %d DAY )",
			$days
		)
	);

	$total_push_delivered = 0;

	foreach ( $push_logs as $log ) {
		$ctx = json_decode( $log->payload, true );
		if ( is_array( $ctx ) && isset( $ctx['sent'] ) ) {
			$total_push_delivered += (int) $ctx['sent'];
		}
	}

	return array(
		'total_sends'          => $total_sends,
		'average_open_rate'    => $average_open_rate,
		'average_click_rate'   => $average_click_rate,
		'total_push_delivered' => $total_push_delivered,
		'period_days'          => $days,
	);
}
