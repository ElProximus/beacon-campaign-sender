<?php
/**
 * Ability: beacon-campaign-sender/list-contacts
 *
 * Retrieve a paginated list of Brevo contacts, optionally scoped to a
 * specific list and filtered by blacklist state or free-text search.
 * When search or filter_blacklisted is used, total_matched reflects only
 * matches within the fetched page after client-side filtering, not a
 * cross-page total.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
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
			'beacon-campaign-sender/list-contacts',
			array(
				'label'               => __( 'List Brevo Contacts', 'beacon-campaign-sender' ),
				'description'         => 'List Brevo contacts with pagination and optional list, date, blacklist, and free-text filtering. search and filter_blacklisted are applied after the fetched page is returned, so total_matched is only page-accurate when those filters are used.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'list_id'            => array(
							'type'        => 'integer',
							'description' => 'Optional Brevo list ID to scope the query.',
						),
						'limit'              => array(
							'type'        => 'integer',
							'description' => 'Maximum contacts to fetch per page. Default 50, max 1000.',
							'default'     => 50,
						),
						'offset'             => array(
							'type'        => 'integer',
							'description' => 'Pagination offset. Default 0.',
							'default'     => 0,
						),
						'modified_since'     => array(
							'type'        => 'string',
							'description' => 'Optional ISO 8601 datetime. Filters contacts modified at or after this time.',
						),
						'created_since'      => array(
							'type'        => 'string',
							'description' => 'Optional ISO 8601 datetime. Filters contacts created at or after this time.',
						),
						'sort'               => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => 'Sort order for server-side contact retrieval. Default desc.',
							'default'     => 'desc',
						),
						'filter_blacklisted' => array(
							'type'        => 'string',
							'enum'        => array( 'only_active', 'only_blacklisted', 'all' ),
							'description' => 'Optional client-side blacklist filter. Default only_active.',
							'default'     => 'only_active',
						),
						'search'             => array(
							'type'        => 'string',
							'description' => 'Optional case-insensitive substring match against email, FIRSTNAME, and LASTNAME.',
						),
					),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'contacts'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'contact_id'        => array( 'type' => 'integer' ),
									'email'             => array( 'type' => 'string' ),
									'first_name'        => array( 'type' => array( 'string', 'null' ) ),
									'last_name'         => array( 'type' => array( 'string', 'null' ) ),
									'attributes'        => array(
										'type' => 'object',
										'additionalProperties' => true,
									),
									'list_ids'          => array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
									),
									'email_blacklisted' => array( 'type' => 'boolean' ),
									'sms_blacklisted'   => array( 'type' => 'boolean' ),
									'created_at'        => array( 'type' => array( 'string', 'null' ) ),
									'modified_at'       => array( 'type' => array( 'string', 'null' ) ),
								),
							),
						),
						'count'         => array( 'type' => 'integer' ),
						'total_matched' => array( 'type' => 'integer' ),
						'offset'        => array( 'type' => 'integer' ),
						'limit'         => array( 'type' => 'integer' ),
						'has_more'      => array( 'type' => 'boolean' ),
					),
				),

				'execute_callback'    => 'bcsend_ability_list_contacts',
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
 * Execute callback for beacon-campaign-sender/list-contacts.
 *
 * @param array $input Optional filter parameters.
 * @return array|WP_Error
 */
function bcsend_ability_list_contacts( $input = array() ) {
	$brevo = new Bcsend_Brevo_API();

	if ( ! $brevo->is_configured() ) {
		return new WP_Error(
			'not_configured',
			'Brevo API key is not configured. Set it in Beacon Campaign Sender > Settings.'
		);
	}

	$list_id            = isset( $input['list_id'] ) ? absint( $input['list_id'] ) : 0;
	$limit              = isset( $input['limit'] ) ? min( max( absint( $input['limit'] ), 1 ), 1000 ) : 50;
	$offset             = isset( $input['offset'] ) ? max( 0, absint( $input['offset'] ) ) : 0;
	$sort               = isset( $input['sort'] ) && 'asc' === strtolower( (string) $input['sort'] ) ? 'asc' : 'desc';
	$filter_blacklisted = isset( $input['filter_blacklisted'] ) ? sanitize_key( (string) $input['filter_blacklisted'] ) : 'only_active';
	$filter_blacklisted = in_array( $filter_blacklisted, array( 'only_active', 'only_blacklisted', 'all' ), true )
		? $filter_blacklisted
		: 'only_active';
	$search             = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';

	$brevo_args = array(
		'limit'  => $limit,
		'offset' => $offset,
		'sort'   => $sort,
	);

	if ( ! empty( $input['modified_since'] ) ) {
		$brevo_args['modifiedSince'] = sanitize_text_field( $input['modified_since'] );
	}

	if ( ! empty( $input['created_since'] ) ) {
		$brevo_args['createdSince'] = sanitize_text_field( $input['created_since'] );
	}

	if ( $list_id > 0 ) {
		$response = $brevo->list_list_contacts( $list_id, $brevo_args );
	} else {
		$response = $brevo->list_contacts( $brevo_args );
	}

	if ( is_wp_error( $response ) ) {
		Bcsend_Logger::log(
			'abilities',
			'list_contacts failed: ' . $response->get_error_message(),
			wp_json_encode(
				array(
					'list_id'            => $list_id,
					'limit'              => $limit,
					'offset'             => $offset,
					'sort'               => $sort,
					'filter_blacklisted' => $filter_blacklisted,
					'search_present'     => '' !== $search,
					'modified_since'     => isset( $brevo_args['modifiedSince'] ),
					'created_since'      => isset( $brevo_args['createdSince'] ),
				)
			),
			'error'
		);

		return $response;
	}

	$raw_contacts = isset( $response['contacts'] ) && is_array( $response['contacts'] )
		? $response['contacts']
		: array();
	$raw_total    = isset( $response['count'] ) ? (int) $response['count'] : count( $raw_contacts );

	$contacts = array();
	foreach ( $raw_contacts as $contact ) {
		$attributes = isset( $contact['attributes'] ) && is_array( $contact['attributes'] )
			? $contact['attributes']
			: array();

		$contacts[] = array(
			'contact_id'        => isset( $contact['id'] ) ? (int) $contact['id'] : 0,
			'email'             => isset( $contact['email'] ) ? strtolower( (string) $contact['email'] ) : '',
			'first_name'        => isset( $attributes['FIRSTNAME'] ) ? (string) $attributes['FIRSTNAME'] : ( isset( $attributes['FIRST_NAME'] ) ? (string) $attributes['FIRST_NAME'] : null ),
			'last_name'         => isset( $attributes['LASTNAME'] ) ? (string) $attributes['LASTNAME'] : ( isset( $attributes['LAST_NAME'] ) ? (string) $attributes['LAST_NAME'] : null ),
			'attributes'        => $attributes,
			'list_ids'          => isset( $contact['listIds'] ) ? array_values( array_map( 'intval', (array) $contact['listIds'] ) ) : array(),
			'email_blacklisted' => ! empty( $contact['emailBlacklisted'] ),
			'sms_blacklisted'   => ! empty( $contact['smsBlacklisted'] ),
			'created_at'        => isset( $contact['createdAt'] ) ? (string) $contact['createdAt'] : null,
			'modified_at'       => isset( $contact['modifiedAt'] ) ? (string) $contact['modifiedAt'] : null,
		);
	}

	if ( 'only_active' === $filter_blacklisted ) {
		$contacts = array_values(
			array_filter(
				$contacts,
				static function ( $contact ) {
					return empty( $contact['email_blacklisted'] );
				}
			)
		);
	} elseif ( 'only_blacklisted' === $filter_blacklisted ) {
		$contacts = array_values(
			array_filter(
				$contacts,
				static function ( $contact ) {
					return ! empty( $contact['email_blacklisted'] );
				}
			)
		);
	}

	if ( '' !== $search ) {
		$search_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

		$contacts = array_values(
			array_filter(
				$contacts,
				static function ( $contact ) use ( $search_lc ) {
					$haystack = implode(
						' ',
						array(
							$contact['email'],
							null !== $contact['first_name'] ? $contact['first_name'] : '',
							null !== $contact['last_name'] ? $contact['last_name'] : '',
						)
					);

					$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

					return false !== strpos( $haystack, $search_lc );
				}
			)
		);
	}

	Bcsend_Logger::log(
		'abilities',
		'list_contacts executed',
		wp_json_encode(
			array(
				'list_id'            => $list_id,
				'limit'              => $limit,
				'offset'             => $offset,
				'sort'               => $sort,
				'filter_blacklisted' => $filter_blacklisted,
				'search_present'     => '' !== $search,
				'modified_since'     => isset( $brevo_args['modifiedSince'] ) ? $brevo_args['modifiedSince'] : '',
				'created_since'      => isset( $brevo_args['createdSince'] ) ? $brevo_args['createdSince'] : '',
			)
		)
	);

	return array(
		'contacts'      => $contacts,
		'count'         => count( $contacts ),
		'total_matched' => ( '' !== $search || 'all' !== $filter_blacklisted ) ? count( $contacts ) : $raw_total,
		'offset'        => $offset,
		'limit'         => $limit,
		'has_more'      => ( $offset + count( $raw_contacts ) ) < $raw_total,
	);
}
