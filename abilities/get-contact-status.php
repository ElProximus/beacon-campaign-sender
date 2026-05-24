<?php
/**
 * Ability: beacon-campaign-sender/get-contact-status
 *
 * Fetch subscription and blocklist status for a single Brevo contact,
 * classified by reason.
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
			'beacon-campaign-sender/get-contact-status',
			array(
				'label'               => __( 'Get Brevo Contact Status', 'beacon-campaign-sender' ),
				'description'         => 'Look up a single Brevo contact by email and return whether they are subscribed to email marketing, whether they are blocklisted, and if so, a classified reason.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'email' => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => 'Email address of the contact to look up.',
						),
					),
					'required'             => array( 'email' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'email'             => array( 'type' => 'string' ),
						'found'             => array(
							'type'        => 'boolean',
							'description' => 'True if the contact exists in Brevo.',
						),
						'email_blacklisted' => array(
							'type'        => 'boolean',
							'description' => 'True if the contact is blocklisted from email campaigns.',
						),
						'reason'            => array(
							'type'        => 'string',
							'enum'        => array( 'not_found', 'not_blocklisted', 'user_unsubscribed', 'hard_bounce', 'spam_complaint', 'admin_or_import', 'unknown' ),
							'description' => 'Classified reason for the current contact status.',
						),
						'list_ids'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Brevo list IDs the contact belongs to.',
						),
						'attributes'        => array(
							'type'                 => 'object',
							'description'          => 'Raw Brevo contact attributes (FIRSTNAME, LASTNAME, etc.).',
							'additionalProperties' => true,
						),
						'created_at'        => array( 'type' => array( 'string', 'null' ) ),
						'modified_at'       => array( 'type' => array( 'string', 'null' ) ),
					),
				),

				'execute_callback'    => 'bcsend_ability_get_contact_status',
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
 * Fetch and classify a single Brevo contact.
 *
 * @param array $input {
 *     @type string $email Contact email.
 * }
 * @return array|WP_Error
 */
function bcsend_ability_get_contact_status( $input = array() ) {
	$brevo = new Bcsend_Brevo_API();

	if ( ! $brevo->is_configured() ) {
		return new WP_Error( 'not_configured', 'Brevo API key is not configured. Set it in Beacon Campaign Sender > Settings.' );
	}

	$email = isset( $input['email'] ) ? strtolower( sanitize_email( $input['email'] ) ) : '';
	if ( empty( $email ) ) {
		return new WP_Error( 'invalid_email', 'A valid email address is required.' );
	}

	$contact = $brevo->get_contact( $email );

	if ( is_wp_error( $contact ) ) {
		if ( 'brevo_contact_not_found' === $contact->get_error_code() ) {
			Bcsend_Logger::log(
				'abilities',
				'get_contact_status not found',
				wp_json_encode( array( 'email' => $email ) )
			);

			return array(
				'email'             => $email,
				'found'             => false,
				'email_blacklisted' => false,
				'reason'            => 'not_found',
				'list_ids'          => array(),
				'attributes'        => array(),
				'created_at'        => null,
				'modified_at'       => null,
			);
		}

		return $contact;
	}

	$result = array(
		'email'             => $email,
		'found'             => true,
		'email_blacklisted' => ! empty( $contact['emailBlacklisted'] ),
		'reason'            => Bcsend_Brevo_API::classify_blocklist_reason( $contact ),
		'list_ids'          => isset( $contact['listIds'] ) ? array_map( 'intval', (array) $contact['listIds'] ) : array(),
		'attributes'        => isset( $contact['attributes'] ) && is_array( $contact['attributes'] ) ? $contact['attributes'] : array(),
		'created_at'        => isset( $contact['createdAt'] ) ? $contact['createdAt'] : null,
		'modified_at'       => isset( $contact['modifiedAt'] ) ? $contact['modifiedAt'] : null,
	);

	Bcsend_Logger::log(
		'abilities',
		'get_contact_status succeeded',
		wp_json_encode(
			array(
				'email'  => $email,
				'found'  => true,
				'reason' => $result['reason'],
			)
		)
	);

	return $result;
}
