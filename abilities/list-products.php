<?php
/**
 * Ability: beacon-campaign-sender/list-products
 *
 * List WooCommerce products with optional search. Returns product ID,
 * title, price, image, and permalink.
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
			'beacon-campaign-sender/list-products',
			array(
				'label'               => __( 'List Products', 'beacon-campaign-sender' ),
				'description'         => 'List WooCommerce products with optional search. Returns product ID, title, price, image, and permalink.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Search term to filter products by title.',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Maximum products to return. Default 20, max 100.',
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array(
								'type'        => 'integer',
								'description' => 'Product ID.',
							),
							'title'     => array(
								'type'        => 'string',
								'description' => 'Product name.',
							),
							'price'     => array(
								'type'        => 'string',
								'description' => 'Product price.',
							),
							'image_url' => array(
								'type'        => 'string',
								'description' => 'Featured image URL.',
							),
							'permalink' => array(
								'type'        => 'string',
								'description' => 'Product page URL.',
							),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_list_products',
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
 * List WooCommerce products.
 *
 * @param array $input {
 *     @type string $search Optional search term.
 *     @type int    $limit  Max results (default 20, max 100).
 * }
 * @return array Array of product summary objects. Empty if WooCommerce is not active.
 */
function bcsend_ability_list_products( $input = array() ) {
	if ( ! Bcsend_Environment::get_instance()->is( 'woocommerce_active' ) ) {
		return array();
	}

	$search = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
	$limit  = isset( $input['limit'] ) ? min( (int) $input['limit'], 100 ) : 20;
	$limit  = max( 1, $limit );

	$args = array(
		'status'  => 'publish',
		'limit'   => $limit,
		'orderby' => 'title',
		'order'   => 'ASC',
		'return'  => 'objects',
	);

	if ( ! empty( $search ) ) {
		$args['s'] = $search;
	}

	$products = wc_get_products( $args );
	$result   = array();

	foreach ( $products as $product ) {
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

		$result[] = array(
			'id'        => $product->get_id(),
			'title'     => $product->get_name(),
			'price'     => $product->get_price(),
			'image_url' => $image_url,
			'permalink' => $product->get_permalink(),
		);
	}

	return $result;
}
