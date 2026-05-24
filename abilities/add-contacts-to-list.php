<?php
/**
 * Ability: beacon-campaign-sender/add-contacts-to-list
 *
 * Bulk-add existing Brevo contacts to a specific list. This is list
 * membership management only and does not create contacts, update
 * attributes, or record consent evidence.
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
			'beacon-campaign-sender/add-contacts-to-list',
			array(
				'label'               => __( 'Add Contacts to Brevo List', 'beacon-campaign-sender' ),
				'description'         => 'Bulk-add existing Brevo contacts to a specific list. Accepts up to 150 emails per call. Contacts must already exist in Brevo; use beacon-campaign-sender/create-contact to create new contacts. Idempotent: adding a contact already on the list is a silent no-op.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'list_id' => array(
							'type'        => 'integer',
							'description' => 'Brevo list ID to add contacts to.',
						),
						'emails'  => array(
							'type'        => 'array',
							'items'       => array(
								'type'   => 'string',
								'format' => 'email',
							),
							'description' => 'Array of 1 to 150 email addresses for existing Brevo contacts.',
						),
					),
					'required'             => array( 'list_id', 'emails' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'list_id'         => array( 'type' => 'integer' ),
						'added_count'     => array( 'type' => 'integer' ),
						'total_processed' => array( 'type' => 'integer' ),
						'invalid_emails'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'failed_emails'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_add_contacts_to_list',
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
 * Execute callback for beacon-campaign-sender/add-contacts-to-list.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function bcsend_ability_add_contacts_to_list( $input = array() ) {
	$brevo = new Bcsend_Brevo_API();

	if ( ! $brevo->is_configured() ) {
		return new WP_Error(
			'not_configured',
			'Brevo API key is not configured. Set it in Beacon Campaign Sender > Settings.'
		);
	}

	$list_id = isset( $input['list_id'] ) ? absint( $input['list_id'] ) : 0;
	if ( $list_id <= 0 ) {
		return new WP_Error( 'invalid_list_id', 'A valid positive Brevo list ID is required.' );
	}

	$emails = isset( $input['emails'] ) && is_array( $input['emails'] ) ? $input['emails'] : array();
	if ( empty( $emails ) ) {
		return new WP_Error( 'no_emails_provided', 'Provide at least one email address.' );
	}

	$invalid_emails = array();
	$valid_emails   = array();

	foreach ( $emails as $email ) {
		$normalized = strtolower( sanitize_email( (string) $email ) );

		if ( ! is_email( $normalized ) ) {
			$invalid_emails[] = (string) $email;
			continue;
		}

		$valid_emails[] = $normalized;
	}

	$valid_emails = array_values( array_unique( $valid_emails ) );

	if ( empty( $valid_emails ) ) {
		return new WP_Error( 'no_valid_emails', 'No valid email addresses remained after validation.' );
	}

	if ( count( $valid_emails ) > 150 ) {
		return new WP_Error(
			'batch_too_large',
			'Brevo accepts a maximum of 150 emails per bulk add call. Split the input into smaller batches and try again.'
		);
	}

	$response = $brevo->add_contacts_to_list( $list_id, $valid_emails );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$success_emails = isset( $response['success'] ) && is_array( $response['success'] )
		? array_values( array_map( 'strtolower', $response['success'] ) )
		: array();
	$failed_emails  = isset( $response['failure'] ) && is_array( $response['failure'] )
		? array_values( array_map( 'strtolower', $response['failure'] ) )
		: array();

	$added_count = count( $success_emails );

	Bcsend_Logger::log(
		'brevo_bulk_add',
		'Bulk add contacts to list completed',
		array(
			'list_id'         => $list_id,
			'requested_count' => count( $valid_emails ),
			'added_count'     => $added_count,
			'failed_count'    => count( $failed_emails ),
			'invalid_count'   => count( $invalid_emails ),
		)
	);

	return array(
		'list_id'         => $list_id,
		'added_count'     => $added_count,
		'total_processed' => $added_count + count( $failed_emails ),
		'invalid_emails'  => $invalid_emails,
		'failed_emails'   => $failed_emails,
	);
}
