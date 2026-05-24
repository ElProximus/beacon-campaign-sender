<?php
/**
 * Ability: beacon-campaign-sender/delete-campaign
 *
 * Delete a campaign. Cannot delete campaigns that are currently sending.
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
			'beacon-campaign-sender/delete-campaign',
			array(
				'label'               => __( 'Delete Campaign', 'beacon-campaign-sender' ),
				'description'         => 'Delete a campaign by ID. Cannot delete campaigns that are currently sending.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'campaign_id' => array(
							'type'        => 'integer',
							'description' => 'Campaign ID to delete.',
						),
					),
					'required'             => array( 'campaign_id' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
				),

				'execute_callback'    => 'bcsend_ability_delete_campaign',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
				},

				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'ai_enabled'  => true,
				),
			)
		);
	}
);

/**
 * Delete a campaign.
 *
 * @param array $input {
 *     @type int $campaign_id Required campaign ID.
 * }
 * @return array|WP_Error Result or WP_Error.
 */
function bcsend_ability_delete_campaign( $input = array() ) {
	global $wpdb;

	$campaign_id = isset( $input['campaign_id'] ) ? absint( $input['campaign_id'] ) : 0;

	if ( empty( $campaign_id ) ) {
		return new WP_Error( 'missing_campaign_id', 'Campaign ID is required.' );
	}

	$table    = $wpdb->prefix . 'bcsend_campaigns';
	$campaign = $wpdb->get_row(
		$wpdb->prepare( "SELECT status, name FROM {$table} WHERE id = %d", $campaign_id ),
		ARRAY_A
	);

	if ( ! $campaign ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
	}

	if ( 'sending' === $campaign['status'] ) {
		return new WP_Error( 'campaign_sending', 'Cannot delete a campaign that is currently sending.' );
	}

	$deleted = $wpdb->delete( $table, array( 'id' => $campaign_id ), array( '%d' ) );

	if ( false === $deleted ) {
		return new WP_Error( 'delete_failed', 'Failed to delete campaign.' );
	}

	Bcsend_Logger::log( 'campaign', 'Campaign deleted: ' . $campaign['name'] . ' (ID ' . $campaign_id . ')' );

	return array(
		'success' => true,
		'message' => 'Campaign deleted.',
	);
}
