<?php
/**
 * Ability: beacon-campaign-sender/list-brevo-lists
 *
 * Fetch contact lists directly from the Brevo account.
 * Use this to discover available Brevo lists that can be
 * imported as segments via beacon-campaign-sender/create-segment.
 *
 * @package Bcsend_Plugin
 * @since   2.1.3
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
			'beacon-campaign-sender/list-brevo-lists',
			array(
				'label'               => __( 'List Brevo Lists', 'beacon-campaign-sender' ),
				'description'         => 'Fetch contact lists from the connected Brevo account. Returns list ID, name, subscriber count, and folder. Use the list ID with beacon-campaign-sender/create-segment (type: brevo_list) to import a list as a usable audience segment.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Maximum number of lists to return. Default 50, max 50.',
							'default'     => 50,
						),
						'offset' => array(
							'type'        => 'integer',
							'description' => 'Number of lists to skip for pagination. Default 0.',
							'default'     => 0,
						),
					),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'lists' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'               => array(
										'type'        => 'integer',
										'description' => 'Brevo list ID.',
									),
									'name'             => array(
										'type'        => 'string',
										'description' => 'List name.',
									),
									'totalSubscribers' => array(
										'type'        => 'integer',
										'description' => 'Number of subscribers.',
									),
									'folderId'         => array(
										'type'        => 'integer',
										'description' => 'Brevo folder ID.',
									),
								),
							),
						),
						'count' => array(
							'type'        => 'integer',
							'description' => 'Total number of lists in the account.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_list_brevo_lists',
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
 * Fetch contact lists from the Brevo API.
 *
 * @param array $input {
 *     @type int $limit  Optional. Max lists to return (default 50).
 *     @type int $offset Optional. Pagination offset (default 0).
 * }
 * @return array|WP_Error List data or WP_Error.
 */
function bcsend_ability_list_brevo_lists( $input = array() ) {
	$brevo = new Bcsend_Brevo_API();

	if ( ! $brevo->is_configured() ) {
		return new WP_Error( 'not_configured', 'Brevo API key is not configured. Set it in Beacon Campaign Sender > Settings.' );
	}

	$limit  = isset( $input['limit'] ) ? min( absint( $input['limit'] ), 50 ) : 50;
	$offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

	$response = $brevo->get_lists();

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// get_lists() returns the full array of lists. Apply limit/offset locally.
	$total = count( $response );
	$slice = array_slice( $response, $offset, $limit );

	$lists = array();
	foreach ( $slice as $list ) {
		$lists[] = array(
			'id'               => isset( $list['id'] ) ? (int) $list['id'] : 0,
			'name'             => isset( $list['name'] ) ? $list['name'] : '',
			'totalSubscribers' => Bcsend_Brevo_API::extract_subscriber_count( $list ),
			'folderId'         => isset( $list['folderId'] ) ? (int) $list['folderId'] : 0,
		);
	}

	return array(
		'lists' => $lists,
		'count' => $total,
	);
}
