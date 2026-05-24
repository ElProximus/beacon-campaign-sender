<?php
/**
 * Ability: beacon-campaign-sender/get-dashboard
 *
 * Get dashboard summary data including campaign/segment/template
 * counts, recent campaigns, and recent errors.
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
			'beacon-campaign-sender/get-dashboard',
			array(
				'label'               => __( 'Get Dashboard', 'beacon-campaign-sender' ),
				'description'         => 'Get dashboard summary data including campaign/segment/template counts, recent campaigns, and recent errors.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_campaigns'        => array( 'type' => 'integer' ),
						'draft_campaigns'        => array( 'type' => 'integer' ),
						'sent_campaigns'         => array( 'type' => 'integer' ),
						'total_segments'         => array( 'type' => 'integer' ),
						'total_templates'        => array( 'type' => 'integer' ),
						'social_posts_total'     => array( 'type' => 'integer' ),
						'social_posts_published' => array( 'type' => 'integer' ),
						'social_posts_scheduled' => array( 'type' => 'integer' ),
						'social_posts_failed'    => array( 'type' => 'integer' ),
						'recent_campaigns'       => array(
							'type'        => 'array',
							'description' => 'Last 5 campaigns.',
						),
						'recent_errors'          => array(
							'type'        => 'array',
							'description' => 'Last 5 error log entries.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_get_dashboard',
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
 * Get dashboard summary data.
 *
 * @param array $input Unused (no parameters).
 * @return array Dashboard summary.
 */
function bcsend_ability_get_dashboard( $input = array() ) {
	global $wpdb;

	$campaigns_table = $wpdb->prefix . 'bcsend_campaigns';
	$segments_table  = $wpdb->prefix . 'bcsend_segments';
	$templates_table = $wpdb->prefix . 'bcsend_templates';
	$logs_table      = $wpdb->prefix . 'bcsend_logs';
	$social_table    = $wpdb->prefix . 'bcsend_social_posts';

	$total_campaigns        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$campaigns_table}" );
	$draft_campaigns        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE status = %s", 'draft' ) );
	$sent_campaigns         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE status = %s", 'sent' ) );
	$total_segments         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$segments_table}" );
	$total_templates        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$templates_table}" );
	$social_posts_total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$social_table}" );
	$social_posts_published = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$social_table} WHERE status IN (%s, %s)", 'published', 'sent' ) );
	$social_posts_scheduled = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$social_table} WHERE status = %s", 'scheduled' ) );
	$social_posts_failed    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$social_table} WHERE status IN (%s, %s)", 'failed', 'partial' ) );

	$recent_campaigns = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, status, scheduled_at, sent_at FROM {$campaigns_table} ORDER BY created_at DESC LIMIT %d",
			5
		),
		ARRAY_A
	);

	$recent_errors = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, type, message, created_at FROM {$logs_table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
			'error',
			5
		),
		ARRAY_A
	);

	return array(
		'total_campaigns'        => $total_campaigns,
		'draft_campaigns'        => $draft_campaigns,
		'sent_campaigns'         => $sent_campaigns,
		'total_segments'         => $total_segments,
		'total_templates'        => $total_templates,
		'social_posts_total'     => $social_posts_total,
		'social_posts_published' => $social_posts_published,
		'social_posts_scheduled' => $social_posts_scheduled,
		'social_posts_failed'    => $social_posts_failed,
		'recent_campaigns'       => $recent_campaigns ? $recent_campaigns : array(),
		'recent_errors'          => $recent_errors ? $recent_errors : array(),
	);
}
