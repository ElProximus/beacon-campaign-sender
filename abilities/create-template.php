<?php
/**
 * Ability: beacon-campaign-sender/create-template
 *
 * Create a new reusable email template from HTML.
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
			'beacon-campaign-sender/create-template',
			array(
				'label'               => __( 'Create Template', 'beacon-campaign-sender' ),
				'description'         => 'Create a new reusable email template from complete email HTML. Template names must be unique; to change an existing template use update-template instead. Show the user a rendered preview of the HTML before and after saving.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'           => array(
							'type'        => 'string',
							'description' => 'Unique template name shown on the Templates screen.',
						),
						'html_content'   => array(
							'type'        => 'string',
							'description' => 'Complete email HTML (inline CSS recommended). Unsafe tags and attributes are stripped.',
						),
						'plain_text'     => array(
							'type'        => 'string',
							'description' => 'Optional plain-text alternative. Derived from the HTML when omitted.',
						),
						'thumbnail'      => array(
							'type'        => 'string',
							'description' => 'Optional preview image URL.',
						),
						'set_as_default' => array(
							'type'        => 'boolean',
							'description' => 'Make this the default template preloaded for new campaigns. Requires the manage_bcsend capability.',
						),
					),
					'required'             => array( 'name', 'html_content' ),
					'additionalProperties' => false,
				),

				'output_schema'       => bcsend_template_ability_output_schema(),

				'execute_callback'    => 'bcsend_ability_create_template',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
				},

				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'ai_enabled'  => true,
				),
			)
		);
	}
);

/**
 * Create a template.
 *
 * @param array $input {
 *     @type string $name           Required unique name.
 *     @type string $html_content   Required email HTML.
 *     @type string $plain_text     Optional plain text.
 *     @type string $thumbnail      Optional preview URL.
 *     @type bool   $set_as_default Optional; make it the default template.
 * }
 * @return array|WP_Error
 */
function bcsend_ability_create_template( $input = array() ) {
	global $wpdb;

	$fields = bcsend_template_ability_sanitize_fields( $input );

	if ( empty( $fields['name'] ) ) {
		return new WP_Error( 'missing_name', 'The name parameter is required.' );
	}

	if ( empty( $fields['html_content'] ) ) {
		return new WP_Error( 'missing_html_content', 'The html_content parameter is required and must contain HTML that survives sanitization.' );
	}

	$existing_id = bcsend_template_ability_find_by_name( $fields['name'] );
	if ( $existing_id ) {
		return new WP_Error(
			'template_name_exists',
			sprintf( 'A template named "%s" already exists (ID %d). Use update-template to change it, or choose a different name.', $fields['name'], $existing_id ),
			array( 'existing_id' => $existing_id )
		);
	}

	$set_as_default = ! empty( $input['set_as_default'] );
	if ( $set_as_default && ! current_user_can( 'manage_bcsend' ) ) {
		return new WP_Error( 'insufficient_permission', 'Setting the default template requires the manage_bcsend capability.' );
	}

	if ( ! isset( $fields['plain_text'] ) || '' === $fields['plain_text'] ) {
		$fields['plain_text'] = bcsend_template_ability_plain_text_from_html( $fields['html_content'] );
	}
	if ( ! isset( $fields['thumbnail'] ) ) {
		$fields['thumbnail'] = '';
	}

	$table  = $wpdb->prefix . 'bcsend_templates';
	$result = $wpdb->insert(
		$table,
		array(
			'name'         => $fields['name'],
			'html_content' => $fields['html_content'],
			'plain_text'   => $fields['plain_text'],
			'thumbnail'    => $fields['thumbnail'],
		),
		array( '%s', '%s', '%s', '%s' )
	);

	if ( false === $result ) {
		return new WP_Error( 'template_create_failed', 'Failed to create the template.' );
	}

	$new_id = (int) $wpdb->insert_id;

	if ( $set_as_default ) {
		update_option( 'bcsend_default_template_id', $new_id );
	}

	Bcsend_Logger::log(
		'template',
		'Template created via ability: ' . $fields['name'] . ' (ID ' . $new_id . ')',
		wp_json_encode(
			array(
				'source'         => 'abilities',
				'set_as_default' => $set_as_default,
				'html_length'    => strlen( $fields['html_content'] ),
			)
		)
	);

	return bcsend_template_ability_format( bcsend_template_ability_fetch( $new_id ) );
}
