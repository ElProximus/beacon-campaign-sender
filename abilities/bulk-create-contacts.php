<?php
/**
 * Ability: beacon-campaign-sender/bulk-create-contacts
 *
 * Bulk-create or update multiple Brevo contacts through the Beacon Campaign Sender
 * subscriber ingest pipeline. Each contact is handled independently so
 * consent evidence, dedupe, retry behavior, and local ledger rows are
 * preserved per contact.
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
			'beacon-campaign-sender/bulk-create-contacts',
			array(
				'label'               => __( 'Bulk Create Brevo Contacts', 'beacon-campaign-sender' ),
				'description'         => 'Bulk-create or update up to 100 Brevo contacts in a single call through the subscriber ingest pipeline. Each contact is logged with consent evidence in the local subscribers ledger, and transient Brevo failures are queued for automatic retry. Safe to retry, with short-window dedupe behavior.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'contacts'     => array(
							'type'        => 'array',
							'description' => 'Array of contacts to create or update. Maximum 100 per call.',
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'email'      => array(
										'type'        => 'string',
										'format'      => 'email',
										'description' => 'Contact email address.',
									),
									'first_name' => array(
										'type'        => 'string',
										'description' => 'Optional first name.',
									),
									'last_name'  => array(
										'type'        => 'string',
										'description' => 'Optional last name.',
									),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => false,
							),
						),
						'list_ids'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Optional Brevo list IDs applied to every contact in the batch.',
						),
						'source'       => array(
							'type'        => 'string',
							'description' => 'Optional source slug applied to every contact. Defaults to api_bulk.',
						),
						'consent_text' => array(
							'type'        => 'string',
							'description' => 'Optional consent context applied to every contact in the batch.',
						),
						'metadata'     => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => 'Optional metadata applied to every contact in the batch.',
						),
					),
					'required'             => array( 'contacts' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_submitted' => array( 'type' => 'integer' ),
						'succeeded'       => array( 'type' => 'integer' ),
						'pending_retry'   => array( 'type' => 'integer' ),
						'failed'          => array( 'type' => 'integer' ),
						'skipped_invalid' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'deduplicated'    => array( 'type' => 'integer' ),
						'results'         => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'email'            => array( 'type' => 'string' ),
									'status'           => array( 'type' => 'string' ),
									'brevo_contact_id' => array( 'type' => array( 'integer', 'null' ) ),
									'subscriber_id'    => array( 'type' => 'integer' ),
									'deduplicated'     => array( 'type' => 'boolean' ),
									'reason'           => array( 'type' => array( 'string', 'null' ) ),
								),
							),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_bulk_create_contacts',
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
 * Execute callback for beacon-campaign-sender/bulk-create-contacts.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function bcsend_ability_bulk_create_contacts( $input = array() ) {
	$brevo = new Bcsend_Brevo_API();

	if ( ! $brevo->is_configured() ) {
		return new WP_Error(
			'not_configured',
			'Brevo API key is not configured. Set it in Beacon Campaign Sender > Settings.'
		);
	}

	$contacts = isset( $input['contacts'] ) && is_array( $input['contacts'] ) ? $input['contacts'] : array();

	if ( empty( $contacts ) ) {
		return new WP_Error( 'no_contacts_provided', 'Provide at least one contact.' );
	}

	if ( count( $contacts ) > 100 ) {
		return new WP_Error(
			'batch_too_large',
			'Bulk create supports at most 100 contacts per call. Split the batch and try again.'
		);
	}

	$list_ids = array();
	if ( ! empty( $input['list_ids'] ) && is_array( $input['list_ids'] ) ) {
		$list_ids = array_values( array_unique( array_filter( array_map( 'intval', $input['list_ids'] ) ) ) );
	}

	$source       = ! empty( $input['source'] ) ? sanitize_key( $input['source'] ) : 'api_bulk';
	$consent_text = ! empty( $input['consent_text'] ) ? sanitize_text_field( $input['consent_text'] ) : 'Added via MCP bulk-create-contacts ability';
	$metadata     = isset( $input['metadata'] ) && is_array( $input['metadata'] ) ? $input['metadata'] : array();
	$metadata     = array_merge( $metadata, array( 'caller' => 'mcp_bulk_ability' ) );

	$skipped_invalid = array();
	$valid_contacts  = array();

	foreach ( $contacts as $contact ) {
		if ( ! is_array( $contact ) ) {
			continue;
		}

		$email = isset( $contact['email'] ) ? strtolower( sanitize_email( $contact['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			$skipped_invalid[] = isset( $contact['email'] ) ? (string) $contact['email'] : '';
			continue;
		}

		$valid_contacts[] = array(
			'email'      => $email,
			'first_name' => ! empty( $contact['first_name'] ) ? sanitize_text_field( $contact['first_name'] ) : '',
			'last_name'  => ! empty( $contact['last_name'] ) ? sanitize_text_field( $contact['last_name'] ) : '',
		);
	}

	if ( empty( $valid_contacts ) ) {
		return new WP_Error( 'no_valid_contacts', 'No valid contacts remained after validation.' );
	}

	$succeeded    = 0;
	$pending      = 0;
	$failed       = 0;
	$deduplicated = 0;
	$results      = array();

	foreach ( $valid_contacts as $contact ) {
		$result = Bcsend_Subscriber_Ingest::register(
			array(
				'email'        => $contact['email'],
				'first_name'   => $contact['first_name'],
				'last_name'    => $contact['last_name'],
				'list_ids'     => $list_ids,
				'source'       => $source ? $source : 'api_bulk',
				'consent_text' => $consent_text,
				'metadata'     => $metadata,
			)
		);

		$status = isset( $result['status'] ) ? $result['status'] : 'failed';

		if ( 'confirmed' === $status ) {
			++$succeeded;
		} elseif ( 'pending' === $status ) {
			++$pending;
		} else {
			++$failed;
		}

		if ( ! empty( $result['deduplicated'] ) ) {
			++$deduplicated;
		}

		$results[] = array(
			'email'            => $contact['email'],
			'status'           => $status,
			'brevo_contact_id' => isset( $result['brevo_contact_id'] ) ? $result['brevo_contact_id'] : null,
			'subscriber_id'    => isset( $result['subscriber_id'] ) ? (int) $result['subscriber_id'] : 0,
			'deduplicated'     => ! empty( $result['deduplicated'] ),
			'reason'           => isset( $result['reason'] ) ? $result['reason'] : null,
		);
	}

	Bcsend_Logger::log(
		'bulk_ingest',
		'Bulk create contacts completed',
		array(
			'source'          => $source ? $source : 'api_bulk',
			'list_ids'        => $list_ids,
			'total_submitted' => count( $valid_contacts ),
			'succeeded'       => $succeeded,
			'pending_retry'   => $pending,
			'failed'          => $failed,
			'deduplicated'    => $deduplicated,
			'skipped_invalid' => count( $skipped_invalid ),
		)
	);

	return array(
		'total_submitted' => count( $valid_contacts ),
		'succeeded'       => $succeeded,
		'pending_retry'   => $pending,
		'failed'          => $failed,
		'skipped_invalid' => $skipped_invalid,
		'deduplicated'    => $deduplicated,
		'results'         => $results,
	);
}
