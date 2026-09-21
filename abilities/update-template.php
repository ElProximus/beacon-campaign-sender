<?php
/**
 * Ability: beacon-campaign-sender/update-template
 *
 * Rename an email template, replace its HTML, or make it the default.
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
			'beacon-campaign-sender/update-template',
			array(
				'label'               => __( 'Update Template', 'beacon-campaign-sender' ),
				'description'         => 'Update an existing email template. Only the fields you send are changed: rename it, replace its html_content, replace its plain_text, change its thumbnail, or set it as the default. Read the current HTML with get-template first, then send the complete revised HTML (partial HTML fragments overwrite the whole template). Show the user a rendered preview after saving.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'template_id'    => array(
							'type'        => 'integer',
							'description' => 'Template ID to update (from list-templates).',
						),
						'name'           => array(
							'type'        => 'string',
							'description' => 'New unique template name.',
						),
						'html_content'   => array(
							'type'        => 'string',
							'description' => 'Complete replacement email HTML. Unsafe tags and attributes are stripped.',
						),
						'plain_text'     => array(
							'type'        => 'string',
							'description' => 'Replacement plain-text alternative. When html_content changes and plain_text is omitted, plain text is re-derived from the new HTML.',
						),
						'thumbnail'      => array(
							'type'        => 'string',
							'description' => 'Replacement preview image URL (empty string clears it).',
						),
						'set_as_default' => array(
							'type'        => 'boolean',
							'description' => 'Make this the default template preloaded for new campaigns.',
						),
					),
					'required'             => array( 'template_id' ),
					'additionalProperties' => false,
				),

				'output_schema'       => bcsend_template_ability_output_schema(),

				'execute_callback'    => 'bcsend_ability_update_template',
				'permission_callback' => function () {
					// Manager tier: overwriting shared templates belongs to the same
					// boundary as the Templates screen, matching the admin AJAX rule.
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
 * Update a template (partial update).
 *
 * @param array $input {
 *     @type int    $template_id    Required template ID.
 *     @type string $name           Optional new name.
 *     @type string $html_content   Optional replacement HTML.
 *     @type string $plain_text     Optional replacement plain text.
 *     @type string $thumbnail      Optional replacement thumbnail URL.
 *     @type bool   $set_as_default Optional; make it the default template.
 * }
 * @return array|WP_Error
 */
function bcsend_ability_update_template( $input = array() ) {
	global $wpdb;

	$template_id = isset( $input['template_id'] ) ? (int) $input['template_id'] : 0;
	if ( $template_id <= 0 ) {
		return new WP_Error( 'missing_template_id', 'The template_id parameter is required.' );
	}

	$current = bcsend_template_ability_fetch( $template_id );
	if ( ! $current ) {
		return new WP_Error( 'template_not_found', 'Template not found.' );
	}

	$fields         = bcsend_template_ability_sanitize_fields( $input );
	$set_as_default = isset( $input['set_as_default'] ) ? (bool) $input['set_as_default'] : null;

	if ( empty( $fields ) && null === $set_as_default ) {
		return new WP_Error( 'nothing_to_update', 'Send at least one of name, html_content, plain_text, thumbnail, or set_as_default.' );
	}

	if ( isset( $fields['name'] ) ) {
		if ( '' === $fields['name'] ) {
			return new WP_Error( 'invalid_name', 'The template name cannot be empty.' );
		}

		$clash = bcsend_template_ability_find_by_name( $fields['name'], $template_id );
		if ( $clash ) {
			return new WP_Error(
				'template_name_exists',
				sprintf( 'Another template named "%s" already exists (ID %d). Choose a different name.', $fields['name'], $clash ),
				array( 'existing_id' => $clash )
			);
		}
	}

	if ( isset( $fields['html_content'] ) ) {
		if ( '' === $fields['html_content'] ) {
			return new WP_Error( 'invalid_html_content', 'html_content must contain HTML that survives sanitization.' );
		}

		// New HTML with no explicit plain text: keep the two in step.
		if ( ! isset( $fields['plain_text'] ) ) {
			$fields['plain_text'] = bcsend_template_ability_plain_text_from_html( $fields['html_content'] );
		}
	}

	if ( ! empty( $fields ) ) {
		$table  = $wpdb->prefix . 'bcsend_templates';
		$format = array_fill( 0, count( $fields ), '%s' );
		$result = $wpdb->update( $table, $fields, array( 'id' => $template_id ), $format, array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'template_update_failed', 'Failed to update the template.' );
		}
	}

	if ( true === $set_as_default ) {
		update_option( 'bcsend_default_template_id', $template_id );
	} elseif ( false === $set_as_default && (int) get_option( 'bcsend_default_template_id', 0 ) === $template_id ) {
		// Un-defaulting the current default: fall back to the newest other
		// template so the composer never preloads a dangling ID.
		$table        = $wpdb->prefix . 'bcsend_templates';
		$next_default = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id <> %d ORDER BY created_at DESC, id DESC LIMIT 1", $template_id )
		);

		if ( $next_default ) {
			update_option( 'bcsend_default_template_id', $next_default );
		} else {
			delete_option( 'bcsend_default_template_id' );
		}
	}

	Bcsend_Logger::log(
		'template',
		'Template updated via ability: ID ' . $template_id,
		wp_json_encode(
			array(
				'source'         => 'abilities',
				'fields'         => array_keys( $fields ),
				'set_as_default' => $set_as_default,
			)
		)
	);

	return bcsend_template_ability_format( bcsend_template_ability_fetch( $template_id ) );
}
