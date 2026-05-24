<?php
/**
 * Ability: beacon-campaign-sender/list-templates
 *
 * List available email templates with name, creation date, and a preview snippet.
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
			'beacon-campaign-sender/list-templates',
			array(
				'label'               => __( 'List Templates', 'beacon-campaign-sender' ),
				'description'         => 'List available email templates with name, creation date, and a preview snippet.',
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
							'id'              => array(
								'type'        => 'integer',
								'description' => 'Template ID.',
							),
							'name'            => array(
								'type'        => 'string',
								'description' => 'Template name.',
							),
							'created_at'      => array(
								'type'        => 'string',
								'description' => 'Creation timestamp.',
							),
							'preview_snippet' => array(
								'type'        => 'string',
								'description' => 'First 200 characters of plain text content.',
							),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_list_templates',
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
 * List available email templates.
 *
 * @param array $input Unused (no parameters).
 * @return array Array of template summary objects.
 */
function bcsend_ability_list_templates( $input = array() ) {
	global $wpdb;

	$table     = $wpdb->prefix . 'bcsend_templates';
	$templates = $wpdb->get_results(
		"SELECT id, name, plain_text, created_at FROM {$table} ORDER BY created_at DESC"
	);

	if ( empty( $templates ) ) {
		return array();
	}

	$result = array();

	foreach ( $templates as $template ) {
		$plain_text = isset( $template->plain_text ) ? $template->plain_text : '';
		$preview    = mb_strlen( $plain_text ) > 200 ? mb_substr( $plain_text, 0, 200 ) : $plain_text;

		$result[] = array(
			'id'              => (int) $template->id,
			'name'            => $template->name,
			'created_at'      => $template->created_at,
			'preview_snippet' => $preview,
		);
	}

	return $result;
}
