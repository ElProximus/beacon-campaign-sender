<?php
/**
 * Ability: beacon-campaign-sender/sync-segments
 *
 * Sync all audience segments, refreshing contact counts from
 * their external sources (Brevo, WooCommerce, BuddyBoss).
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
			'beacon-campaign-sender/sync-segments',
			array(
				'label'               => __( 'Sync Segments', 'beacon-campaign-sender' ),
				'description'         => 'Sync all audience segments, refreshing contact counts from their external sources (Brevo, WooCommerce, BuddyBoss).',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'synced_count' => array(
							'type'        => 'integer',
							'description' => 'Number of segments synced.',
						),
						'message'      => array( 'type' => 'string' ),
					),
				),

				'execute_callback'    => 'bcsend_ability_sync_segments',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
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
 * Sync all audience segments.
 *
 * Delegates to Bcsend_Segment_Engine::sync_all() which syncs smart
 * segments to Brevo and updates contact counts.
 *
 * @param array $input Unused (no parameters).
 * @return array Sync result.
 */
function bcsend_ability_sync_segments( $input = array() ) {
	$segments = Bcsend_Segment_Engine::get_segments( 'smart' );
	$count    = count( $segments );

	Bcsend_Segment_Engine::sync_all();

	return array(
		'synced_count' => $count,
		'message'      => sprintf( '%d segment(s) synced.', $count ),
	);
}
