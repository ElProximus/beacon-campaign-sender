<?php
/**
 * Ability: beacon-campaign-sender/list-segments
 *
 * List all audience segments with contact counts and sync status.
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
			'beacon-campaign-sender/list-segments',
			array(
				'label'               => __( 'List Segments', 'beacon-campaign-sender' ),
				'description'         => 'List all audience segments with contact counts and sync status.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'            => array(
								'type'        => 'integer',
								'description' => 'Segment ID.',
							),
							'name'          => array(
								'type'        => 'string',
								'description' => 'Segment name.',
							),
							'type'          => array(
								'type'        => 'string',
								'description' => 'Segment type (brevo_list, wc_customers, buddyboss_members, manual).',
							),
							'contact_count' => array(
								'type'        => 'integer',
								'description' => 'Number of contacts in this segment.',
							),
							'last_synced'   => array(
								'type'        => array( 'string', 'null' ),
								'description' => 'Last sync timestamp.',
							),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_list_segments',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
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
 * List all audience segments.
 *
 * @param array $input Unused (no parameters).
 * @return array Array of segment summary objects.
 */
function bcsend_ability_list_segments( $input = array() ) {
	// Auto-sync Brevo lists into local segments (at most once per 5 minutes).
	$transient_key = 'bcsend_brevo_list_sync';
	if ( false === get_transient( $transient_key ) ) {
		Bcsend_Segment_Engine::sync_from_brevo();
		set_transient( $transient_key, 1, 5 * MINUTE_IN_SECONDS );
	}

	$segments = Bcsend_Segment_Engine::get_segments();
	$result   = array();

	foreach ( $segments as $segment ) {
		$result[] = array(
			'id'            => (int) $segment->id,
			'name'          => $segment->name,
			'type'          => $segment->type,
			'contact_count' => isset( $segment->contact_count ) ? (int) $segment->contact_count : 0,
			'last_synced'   => isset( $segment->last_synced ) ? $segment->last_synced : null,
		);
	}

	return $result;
}
