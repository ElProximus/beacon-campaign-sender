<?php
/**
 * Ability: beacon-campaign-sender/create-contact
 *
 * Add or update a contact in Brevo with optional name attributes
 * and list memberships.
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
			'beacon-campaign-sender/create-contact',
			array(
				'label'               => __( 'Create Brevo Contact', 'beacon-campaign-sender' ),
				'description'         => 'Create or update a contact through Beacon Campaign Sender subscriber ingest. Existing contacts are updated rather than erroring. Use for explicit opt-in imports and optional list routing while preserving local consent evidence.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'email'      => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => 'Contact email address.',
						),
						'first_name' => array(
							'type'        => 'string',
							'description' => 'Optional first name (mapped to Brevo FIRSTNAME attribute).',
						),
						'last_name'  => array(
							'type'        => 'string',
							'description' => 'Optional last name (mapped to Brevo LASTNAME attribute).',
						),
						'list_ids'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Optional Brevo list IDs to add the contact to.',
						),
						'source'     => array(
							'type'        => 'string',
							'description' => 'Optional subscriber source slug for audit tracking. Defaults to api.',
						),
					),
					'required'             => array( 'email' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'email'         => array(
							'type'        => 'string',
							'description' => 'The email address that was created or updated.',
						),
						'contact_id'    => array(
							'type'        => array( 'integer', 'null' ),
							'description' => 'Brevo contact ID. May be null while the ingest record is pending retry.',
						),
						'created'       => array(
							'type'        => 'boolean',
							'description' => 'True if a new contact was created, false if an existing contact was updated.',
						),
						'subscriber_id' => array(
							'type'        => 'integer',
							'description' => 'Local Beacon Campaign Sender subscriber ledger row ID.',
						),
						'status'        => array(
							'type'        => 'string',
							'description' => 'Current ingest status: confirmed or pending.',
						),
						'deduplicated'  => array(
							'type'        => 'boolean',
							'description' => 'True when a recent matching ingest row was reused instead of inserting a duplicate.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_create_contact',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
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
 * Execute callback for beacon-campaign-sender/create-contact.
 *
 * @param array $input {
 *     @type string $email      Contact email (required).
 *     @type string $first_name Optional first name.
 *     @type string $last_name  Optional last name.
 *     @type array  $list_ids   Optional list of Brevo list IDs.
 * }
 * @return array|WP_Error
 */
function bcsend_ability_create_contact( $input = array() ) {
	$brevo = new Bcsend_Brevo_API();

	if ( ! $brevo->is_configured() ) {
		return new WP_Error(
			'not_configured',
			'Brevo API key is not configured. Set it in Beacon Campaign Sender > Settings.'
		);
	}

	$email = isset( $input['email'] ) ? strtolower( sanitize_email( $input['email'] ) ) : '';
	if ( empty( $email ) ) {
		return new WP_Error( 'invalid_email', 'A valid email address is required.' );
	}

	$list_ids = array();
	if ( ! empty( $input['list_ids'] ) && is_array( $input['list_ids'] ) ) {
		$list_ids = array_values( array_map( 'intval', $input['list_ids'] ) );
	}

	$source = ! empty( $input['source'] ) ? sanitize_key( $input['source'] ) : 'api';

	$result = Bcsend_Subscriber_Ingest::register(
		array(
			'email'        => $email,
			'first_name'   => ! empty( $input['first_name'] ) ? sanitize_text_field( $input['first_name'] ) : '',
			'last_name'    => ! empty( $input['last_name'] ) ? sanitize_text_field( $input['last_name'] ) : '',
			'list_ids'     => $list_ids,
			'source'       => $source ? $source : 'api',
			'consent_text' => 'Added via MCP create-contact ability',
			'metadata'     => array(
				'caller' => 'mcp_ability',
			),
		)
	);

	if ( empty( $result['success'] ) ) {
		return new WP_Error(
			isset( $result['reason'] ) ? $result['reason'] : 'subscriber_ingest_failed',
			'Unable to create the subscriber record.'
		);
	}

	return array(
		'email'         => $email,
		'contact_id'    => isset( $result['brevo_contact_id'] ) ? $result['brevo_contact_id'] : null,
		'created'       => empty( $result['deduplicated'] ) && ! empty( $result['brevo_contact_id'] ),
		'subscriber_id' => isset( $result['subscriber_id'] ) ? (int) $result['subscriber_id'] : 0,
		'status'        => isset( $result['status'] ) ? $result['status'] : 'pending',
		'deduplicated'  => ! empty( $result['deduplicated'] ),
	);
}
