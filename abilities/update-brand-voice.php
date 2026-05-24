<?php
/**
 * Ability: beacon-campaign-sender/update-brand-voice
 *
 * Update the brand voice text used for AI-generated campaign content.
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
			'beacon-campaign-sender/update-brand-voice',
			array(
				'label'               => __( 'Update Brand Voice', 'beacon-campaign-sender' ),
				'description'         => 'Update the brand voice text used for AI-generated campaign content.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'brand_voice' => array(
							'type'        => 'string',
							'description' => 'The brand voice description text to use for AI content generation.',
						),
					),
					'required'             => array( 'brand_voice' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'voice_text' => array(
							'type'        => 'string',
							'description' => 'The saved brand voice text.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_update_brand_voice',
				'permission_callback' => function () {
					return current_user_can( 'manage_bcsend' );
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
 * Update the brand voice setting.
 *
 * @param array $input {
 *     @type string $brand_voice Required brand voice text.
 * }
 * @return array|WP_Error Success result or WP_Error.
 */
function bcsend_ability_update_brand_voice( $input = array() ) {
	$brand_voice = isset( $input['brand_voice'] ) ? sanitize_textarea_field( $input['brand_voice'] ) : '';

	if ( empty( $brand_voice ) ) {
		return new WP_Error( 'missing_brand_voice', 'The brand_voice parameter is required.' );
	}

	$settings                = get_option( 'bcsend_settings', array() );
	$settings['brand_voice'] = $brand_voice;
	update_option( 'bcsend_settings', $settings );

	Bcsend_Logger::log(
		'abilities',
		'update_brand_voice succeeded',
		wp_json_encode( array( 'length' => mb_strlen( $brand_voice ) ) )
	);

	return array(
		'success'    => true,
		'voice_text' => $brand_voice,
	);
}
