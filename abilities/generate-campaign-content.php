<?php
/**
 * Ability: beacon-campaign-sender/generate-campaign-content
 *
 * Generate campaign content via AI without saving to the database.
 * Returns generated content for review before saving as a draft.
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
			'beacon-campaign-sender/generate-campaign-content',
			array(
				'label'               => __( 'Generate Campaign Content', 'beacon-campaign-sender' ),
				'description'         => 'Generate campaign content via AI without saving. Returns content for review. Use create-campaign for one-shot generation + save. The response includes html_content — always display it as a rendered HTML preview so the user can see the email design.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt'           => array(
							'type'        => 'string',
							'description' => 'Prompt describing the campaign purpose, tone, and content direction.',
						),
						'product_ids'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'WooCommerce product IDs to feature in the email.',
						),
						'image_urls'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Media library image URLs to include in the email.',
						),
						'post_ids'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'WordPress post/page IDs to feature in the email with title, excerpt, image, and link.',
						),
						'template_id'      => array(
							'type'        => 'integer',
							'description' => 'Base template ID to use for HTML generation.',
						),
						'current_html'     => array(
							'type'        => 'string',
							'description' => 'Existing HTML content to edit instead of starting from scratch.',
						),
						'channels'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Active channels to generate for. Defaults to email + push.',
						),
						'social_platforms' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Requested social platforms to generate copy for.',
						),
						'social_post_mode' => array(
							'type'        => 'string',
							'enum'        => array( 'single', 'per_platform' ),
							'description' => 'Social post generation mode. Defaults to the current Zernio setting.',
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'content'  => array(
							'type'        => 'object',
							'description' => 'Generated campaign content (name, subject, html_content, plain_text, push_title, push_message, etc.).',
						),
						'provider' => array(
							'type'        => 'string',
							'description' => 'AI provider used (anthropic or openai).',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_generate_campaign_content',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
				},

				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
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
 * Generate campaign content via AI without saving.
 *
 * @param array $input {
 *     @type string $prompt      Required. Campaign generation prompt.
 *     @type array  $product_ids Optional product IDs.
 *     @type int    $template_id Optional template ID.
 * }
 * @return array|WP_Error Generated content or WP_Error.
 */
function bcsend_ability_generate_campaign_content( $input = array() ) {
	$prompt           = isset( $input['prompt'] ) ? sanitize_textarea_field( $input['prompt'] ) : '';
	$product_ids      = isset( $input['product_ids'] ) ? array_map( 'absint', (array) $input['product_ids'] ) : array();
	$image_urls       = isset( $input['image_urls'] ) ? array_map( 'esc_url_raw', (array) $input['image_urls'] ) : array();
	$post_ids         = isset( $input['post_ids'] ) ? array_map( 'absint', (array) $input['post_ids'] ) : array();
	$template_id      = isset( $input['template_id'] ) ? absint( $input['template_id'] ) : 0;
	$current_html     = isset( $input['current_html'] ) ? bcsend_kses_email( $input['current_html'] ) : '';
	$channels         = isset( $input['channels'] ) ? array_map( 'sanitize_text_field', (array) $input['channels'] ) : array( 'email', 'push' );
	$social_platforms = isset( $input['social_platforms'] ) ? array_map( 'sanitize_text_field', (array) $input['social_platforms'] ) : array();
	$social_post_mode = isset( $input['social_post_mode'] ) ? sanitize_key( (string) $input['social_post_mode'] ) : '';

	if ( empty( $prompt ) ) {
		return new WP_Error( 'missing_prompt', 'The prompt parameter is required.' );
	}

	$generated = Bcsend_AI_Service::generate_campaign_from_request( $product_ids, $prompt, $template_id, $current_html, $image_urls, $post_ids, $channels, $social_platforms, $social_post_mode );

	if ( is_wp_error( $generated ) ) {
		Bcsend_Logger::log( 'abilities', 'generate_campaign_content failed: ' . $generated->get_error_message(), '', 'error' );
		return $generated;
	}

	Bcsend_Logger::log( 'abilities', 'Campaign content generated successfully.' );

	return array(
		'content'  => $generated['content'],
		'provider' => $generated['provider'],
	);
}
