<?php
/**
 * Ability: beacon-campaign-sender/get-template
 *
 * Retrieve one email template with its full HTML so it can be reviewed or edited.
 *
 * @package Bcsend_Plugin
 * @since   1.1.1
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
			'beacon-campaign-sender/get-template',
			array(
				'label'               => __( 'Get Template', 'beacon-campaign-sender' ),
				'description'         => 'Retrieve one email template by ID, including its full html_content. Always display html_content as a rendered HTML preview so the user can see the design. Use this before update-template so edits start from the current HTML.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'template_id' => array(
							'type'        => 'integer',
							'description' => 'Template ID (from list-templates).',
						),
					),
					'required'             => array( 'template_id' ),
					'additionalProperties' => false,
				),

				'output_schema'       => bcsend_template_ability_output_schema(),

				'execute_callback'    => 'bcsend_ability_get_template',
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
 * Output schema shared by the template abilities.
 *
 * @return array
 */
function bcsend_template_ability_output_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'           => array( 'type' => 'integer' ),
			'name'         => array( 'type' => 'string' ),
			'html_content' => array(
				'type'        => 'string',
				'description' => 'Full email HTML.',
			),
			'plain_text'   => array(
				'type'        => 'string',
				'description' => 'Plain-text alternative.',
			),
			'thumbnail'    => array(
				'type'        => 'string',
				'description' => 'Preview image URL, if any.',
			),
			'created_at'   => array( 'type' => 'string' ),
			'is_default'   => array(
				'type'        => 'boolean',
				'description' => 'Whether this template is preloaded for new campaigns.',
			),
		),
	);
}

/**
 * Get one template.
 *
 * @param array $input {
 *     @type int $template_id Required template ID.
 * }
 * @return array|WP_Error
 */
function bcsend_ability_get_template( $input = array() ) {
	$template_id = isset( $input['template_id'] ) ? (int) $input['template_id'] : 0;

	if ( $template_id <= 0 ) {
		return new WP_Error( 'missing_template_id', 'The template_id parameter is required.' );
	}

	$row = bcsend_template_ability_fetch( $template_id );
	if ( ! $row ) {
		return new WP_Error( 'template_not_found', 'Template not found.' );
	}

	return bcsend_template_ability_format( $row );
}
