<?php
/**
 * Ability: beacon-campaign-sender/create-segment
 *
 * Create a new audience segment.
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
			'beacon-campaign-sender/create-segment',
			array(
				'label'               => __( 'Create Segment', 'beacon-campaign-sender' ),
				'description'         => 'Create a new audience segment for targeting campaigns.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'          => array(
							'type'        => 'string',
							'description' => 'Segment name.',
						),
						'type'          => array(
							'type'        => 'string',
							'description' => 'Segment type.',
							'enum'        => array( 'brevo_list', 'wc_customers', 'buddyboss_members', 'manual' ),
						),
						'brevo_list_id' => array(
							'type'        => 'integer',
							'description' => 'Brevo list ID (required if type is brevo_list).',
						),
						'query_type'    => array(
							'type'        => 'string',
							'description' => 'Query type for smart segments.',
						),
						'query_params'  => array(
							'type'        => 'string',
							'description' => 'JSON query parameters for smart segments.',
						),
					),
					'required'             => array( 'name', 'type' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Created segment ID.',
						),
						'message' => array( 'type' => 'string' ),
					),
				),

				'execute_callback'    => 'bcsend_ability_create_segment',
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
 * Create a new audience segment.
 *
 * @param array $input {
 *     @type string $name          Required segment name.
 *     @type string $type          Required segment type.
 *     @type int    $brevo_list_id Optional Brevo list ID.
 *     @type string $query_type    Optional query type.
 *     @type string $query_params  Optional JSON query params.
 * }
 * @return array|WP_Error Created segment ID or WP_Error.
 */
function bcsend_ability_create_segment( $input = array() ) {
	global $wpdb;

	$table        = $wpdb->prefix . 'bcsend_segments';
	$name         = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
	$type         = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '';
	$brevo_list   = isset( $input['brevo_list_id'] ) ? absint( $input['brevo_list_id'] ) : 0;
	$query_type   = isset( $input['query_type'] ) ? sanitize_text_field( $input['query_type'] ) : '';
	$query_params = isset( $input['query_params'] ) ? sanitize_text_field( $input['query_params'] ) : '';

	if ( empty( $name ) || empty( $type ) ) {
		return new WP_Error( 'missing_params', 'Name and type are required.' );
	}

	$allowed_types = array( 'brevo_list', 'wc_customers', 'buddyboss_members', 'manual' );
	if ( ! in_array( $type, $allowed_types, true ) ) {
		return new WP_Error( 'invalid_type', 'Invalid segment type.' );
	}

	// For brevo_list segments, fetch the subscriber count from Brevo.
	$contact_count = 0;
	if ( 'brevo_list' === $type && $brevo_list ) {
		$brevo = new Bcsend_Brevo_API();
		if ( $brevo->is_configured() ) {
			$list_data = $brevo->get_list( $brevo_list );
			if ( ! is_wp_error( $list_data ) ) {
				$contact_count = Bcsend_Brevo_API::extract_subscriber_count( $list_data );
			}
		}
	}

	$inserted = $wpdb->insert(
		$table,
		array(
			'name'          => $name,
			'type'          => $type,
			'brevo_list_id' => $brevo_list ? $brevo_list : null,
			'query_type'    => $query_type,
			'query_params'  => $query_params,
			'contact_count' => $contact_count,
			'last_synced'   => 'brevo_list' === $type && $brevo_list ? current_time( 'mysql', true ) : null,
		),
		array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
	);

	if ( false === $inserted ) {
		Bcsend_Logger::log( 'segment', 'Failed to create segment: ' . $wpdb->last_error, '', 'error' );
		return new WP_Error( 'insert_failed', 'Failed to create segment.' );
	}

	$segment_id = (int) $wpdb->insert_id;
	Bcsend_Logger::log( 'segment', 'Segment created: ' . $name . ' (ID ' . $segment_id . ')' );

	return array(
		'id'      => $segment_id,
		'message' => 'Segment created.',
	);
}
