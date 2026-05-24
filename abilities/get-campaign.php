<?php
/**
 * Ability: beacon-campaign-sender/get-campaign
 *
 * Retrieve full details for a single campaign including segment info.
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
			'beacon-campaign-sender/get-campaign',
			array(
				'label'               => __( 'Get Campaign', 'beacon-campaign-sender' ),
				'description'         => 'Retrieve full details for a single campaign by ID, including segment information. The response includes html_content — always display it as a rendered HTML preview so the user can see the email design.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'campaign_id' => array(
							'type'        => 'integer',
							'description' => 'Campaign ID to retrieve.',
						),
					),
					'required'             => array( 'campaign_id' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'                  => array( 'type' => 'integer' ),
						'name'                => array( 'type' => 'string' ),
						'subject'             => array( 'type' => 'string' ),
						'preview_text'        => array( 'type' => 'string' ),
						'html_content'        => array( 'type' => 'string' ),
						'plain_text'          => array( 'type' => 'string' ),
						'push_title'          => array( 'type' => 'string' ),
						'push_message'        => array( 'type' => 'string' ),
						'status'              => array( 'type' => 'string' ),
						'email_status'        => array( 'type' => array( 'string', 'null' ) ),
						'push_status'         => array( 'type' => array( 'string', 'null' ) ),
						'social_status'       => array( 'type' => array( 'string', 'null' ) ),
						'send_push'           => array( 'type' => array( 'integer', 'null' ) ),
						'send_social'         => array( 'type' => array( 'integer', 'null' ) ),
						'social_post_mode'    => array( 'type' => array( 'string', 'null' ) ),
						'segment_id'          => array( 'type' => array( 'integer', 'null' ) ),
						'push_segment_id'     => array( 'type' => array( 'integer', 'null' ) ),
						'push_target_type'    => array( 'type' => array( 'string', 'null' ) ),
						'push_target_data'    => array( 'type' => array( 'string', 'null' ) ),
						'segment'             => array( 'type' => array( 'object', 'null' ) ),
						'scheduled_at'        => array( 'type' => array( 'string', 'null' ) ),
						'social_scheduled_at' => array( 'type' => array( 'string', 'null' ) ),
						'sent_at'             => array( 'type' => array( 'string', 'null' ) ),
						'created_at'          => array( 'type' => 'string' ),
						'social_posts'        => array( 'type' => 'array' ),
					),
				),

				'execute_callback'    => 'bcsend_ability_get_campaign',
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
 * Get a single campaign by ID with segment info.
 *
 * @param array $input {
 *     @type int $campaign_id Required campaign ID.
 * }
 * @return array|WP_Error Campaign data or WP_Error.
 */
function bcsend_ability_get_campaign( $input = array() ) {
	global $wpdb;

	$campaign_id = isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0;

	if ( empty( $campaign_id ) ) {
		return new WP_Error( 'missing_campaign_id', 'The campaign_id parameter is required.' );
	}

	$table    = $wpdb->prefix . 'bcsend_campaigns';
	$campaign = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $campaign_id ),
		ARRAY_A
	);

	if ( ! $campaign ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
	}

	// Include segment info.
	if ( ! empty( $campaign['segment_id'] ) ) {
		$seg_table           = $wpdb->prefix . 'bcsend_segments';
		$campaign['segment'] = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$seg_table} WHERE id = %d", $campaign['segment_id'] ),
			ARRAY_A
		);
	}

	$campaign['social_posts'] = Bcsend_Social_Workflow::get_campaign_rows( $campaign_id, ARRAY_A );

	return $campaign;
}
