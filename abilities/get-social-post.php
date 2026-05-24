<?php
/**
 * Ability: beacon-campaign-sender/get-social-post
 *
 * Retrieve a single social post row.
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
			'beacon-campaign-sender/get-social-post',
			array(
				'label'               => __( 'Get Social Post', 'beacon-campaign-sender' ),
				'description'         => 'Retrieve a single Beacon Campaign Sender social post row by ID.',
				'category'            => 'beacon-campaign-sender',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer' ),
						'campaign_id'    => array( 'type' => 'integer' ),
						'zernio_post_id' => array( 'type' => 'string' ),
						'platform'       => array( 'type' => 'string' ),
						'account_id'     => array( 'type' => 'string' ),
						'content'        => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
						'scheduled_for'  => array( 'type' => 'string' ),
						'published_at'   => array( 'type' => 'string' ),
						'last_error'     => array( 'type' => 'string' ),
						'created_at'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'bcsend_ability_get_social_post',
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
 * Get a social post row by ID.
 *
 * @param array $input Input args.
 * @return array|WP_Error
 */
function bcsend_ability_get_social_post( $input = array() ) {
	global $wpdb;

	$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
	if ( empty( $id ) ) {
		return new WP_Error( 'missing_id', 'The id parameter is required.' );
	}

	$table = $wpdb->prefix . 'bcsend_social_posts';
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

	if ( ! $row ) {
		return new WP_Error( 'social_post_not_found', 'Social post not found.' );
	}

	if ( ! empty( $row['campaign_id'] ) ) {
		Bcsend_Social_Sender::refresh_campaign_statuses( (int) $row['campaign_id'] );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	return $row;
}
