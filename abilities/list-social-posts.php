<?php
/**
 * Ability: beacon-campaign-sender/list-social-posts
 *
 * List social posts tracked by Beacon Campaign Sender.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
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
			'beacon-campaign-sender/list-social-posts',
			array(
				'label'               => __( 'List Social Posts', 'beacon-campaign-sender' ),
				'description'         => 'List social posts tracked by Beacon Campaign Sender with optional filters.',
				'category'            => 'beacon-campaign-sender',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'campaign_id' => array( 'type' => 'integer' ),
						'status'      => array( 'type' => 'string' ),
						'limit'       => array( 'type' => 'integer' ),
						'offset'      => array(
							'type'        => 'integer',
							'description' => 'Number of rows to skip for pagination.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => 'bcsend_ability_list_social_posts',
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
 * List social posts.
 *
 * @param array $input Filters.
 * @return array
 */
function bcsend_ability_list_social_posts( $input = array() ) {
	global $wpdb;

	$table       = $wpdb->prefix . 'bcsend_social_posts';
	$campaign_id = isset( $input['campaign_id'] ) ? absint( $input['campaign_id'] ) : 0;
	$status      = isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : '';
	$limit       = isset( $input['limit'] ) ? max( 1, absint( $input['limit'] ) ) : 20;
	$offset      = isset( $input['offset'] ) ? max( 0, absint( $input['offset'] ) ) : 0;

	$where  = array( '1=1' );
	$params = array();

	if ( $campaign_id > 0 ) {
		$where[]  = 'campaign_id = %d';
		$params[] = $campaign_id;
	}

	if ( ! empty( $status ) ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}

	$params[] = $limit;
	$params[] = $offset;

	$query = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
	$posts = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

	return array(
		'posts' => $posts ? $posts : array(),
	);
}
