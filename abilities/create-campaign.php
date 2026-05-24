<?php
/**
 * Ability: beacon-campaign-sender/create-campaign
 *
 * Create a new campaign using AI-generated content. Generates and saves
 * in one operation (one-shot for AI agents).
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
			'beacon-campaign-sender/create-campaign',
			array(
				'label'               => __( 'Create Campaign', 'beacon-campaign-sender' ),
				'description'         => 'Create a new marketing campaign using AI-generated content. Optionally provide a product ID to generate product-specific content, a segment ID to target, and a scheduled send time. The response includes html_content — always display it as a rendered HTML preview so the user can see the email design.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt'       => array(
							'type'        => 'string',
							'description' => 'Prompt describing the campaign purpose, tone, and content direction.',
						),
						'product_id'   => array(
							'type'        => 'integer',
							'description' => 'WooCommerce product ID to feature in the campaign.',
						),
						'segment_id'   => array(
							'type'        => 'integer',
							'description' => 'Segment ID to target with this campaign.',
						),
						'scheduled_at' => array(
							'type'        => 'string',
							'description' => 'ISO 8601 datetime for when to send the campaign.',
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'campaign_id' => array(
							'type'        => 'integer',
							'description' => 'Created campaign ID.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'Campaign name.',
						),
						'status'      => array(
							'type'        => 'string',
							'description' => 'Campaign status (draft).',
						),
						'subject'     => array(
							'type'        => 'string',
							'description' => 'Email subject line.',
						),
						'push_title'  => array(
							'type'        => 'string',
							'description' => 'Push notification title.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_create_campaign',
				'permission_callback' => function () {
					return current_user_can( 'manage_bcsend' );
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
 * Create a campaign using AI-generated content.
 *
 * @param array $input {
 *     @type string $prompt       Required. Prompt for AI generation.
 *     @type int    $product_id   Optional WooCommerce product ID.
 *     @type int    $segment_id   Optional segment ID.
 *     @type string $scheduled_at Optional ISO 8601 send time.
 * }
 * @return array|WP_Error Campaign summary or WP_Error.
 */
function bcsend_ability_create_campaign( $input = array() ) {
	global $wpdb;

	$prompt       = isset( $input['prompt'] ) ? sanitize_textarea_field( $input['prompt'] ) : '';
	$product_id   = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
	$segment_id   = isset( $input['segment_id'] ) ? (int) $input['segment_id'] : 0;
	$scheduled_at = isset( $input['scheduled_at'] ) ? sanitize_text_field( $input['scheduled_at'] ) : '';

	if ( empty( $prompt ) ) {
		return new WP_Error( 'missing_prompt', 'The prompt parameter is required.' );
	}

	if ( ! empty( $scheduled_at ) && empty( $segment_id ) ) {
		return new WP_Error( 'missing_segment', 'A segment_id is required when scheduling a campaign. Create or select a segment first.' );
	}

	// Gather product data if provided and WooCommerce is active.
	$product_data = array();

	if ( $product_id > 0 && Bcsend_Environment::get_instance()->is( 'woocommerce_active' ) ) {
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$product_data = array(
				'id'          => $product->get_id(),
				'title'       => $product->get_name(),
				'price'       => $product->get_price(),
				'description' => $product->get_short_description(),
				'permalink'   => $product->get_permalink(),
				'image_url'   => wp_get_attachment_url( $product->get_image_id() ),
			);
		}
	}

	$generated = Bcsend_AI_Service::generate_campaign_content( $product_data, $prompt );

	if ( is_wp_error( $generated ) ) {
		Bcsend_Logger::log(
			'abilities',
			'create_campaign failed: ' . $generated->get_error_message(),
			wp_json_encode( array( 'ability' => 'create-campaign' ) ),
			'error'
		);
		return $generated;
	}

	// Insert campaign as draft.
	$table = $wpdb->prefix . 'bcsend_campaigns';

	$insert_data = array(
		'name'         => isset( $generated['content']['name'] ) ? sanitize_text_field( $generated['content']['name'] ) : 'AI Campaign',
		'subject'      => isset( $generated['content']['subject'] ) ? sanitize_text_field( $generated['content']['subject'] ) : '',
		'preview_text' => isset( $generated['content']['preview_text'] ) ? sanitize_text_field( $generated['content']['preview_text'] ) : '',
		'html_content' => isset( $generated['content']['html_content'] ) ? bcsend_kses_email( $generated['content']['html_content'] ) : '',
		'plain_text'   => isset( $generated['content']['plain_text'] ) ? sanitize_textarea_field( $generated['content']['plain_text'] ) : '',
		'push_title'   => isset( $generated['content']['push_title'] ) ? sanitize_text_field( $generated['content']['push_title'] ) : '',
		'push_message' => isset( $generated['content']['push_message'] ) ? sanitize_text_field( $generated['content']['push_message'] ) : '',
		'segment_id'   => $segment_id > 0 ? $segment_id : null,
		'product_id'   => $product_id > 0 ? $product_id : null,
		'status'       => 'draft',
		'scheduled_at' => ! empty( $scheduled_at ) ? $scheduled_at : null,
		'created_at'   => current_time( 'mysql', true ),
	);

	$inserted = $wpdb->insert(
		$table,
		$insert_data,
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		Bcsend_Logger::log(
			'abilities',
			'create_campaign insert failed: ' . $wpdb->last_error,
			wp_json_encode( array( 'ability' => 'create-campaign' ) ),
			'error'
		);
		return new WP_Error( 'campaign_insert_failed', 'Failed to create campaign: ' . $wpdb->last_error );
	}

	$campaign_id = (int) $wpdb->insert_id;

	Bcsend_Logger::log(
		'abilities',
		'create_campaign succeeded',
		wp_json_encode(
			array(
				'campaign_id' => $campaign_id,
				'name'        => $insert_data['name'],
			)
		)
	);

	return array(
		'campaign_id' => $campaign_id,
		'name'        => $insert_data['name'],
		'status'      => 'draft',
		'subject'     => $insert_data['subject'],
		'push_title'  => $insert_data['push_title'],
	);
}
