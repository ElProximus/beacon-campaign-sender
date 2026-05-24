<?php
/**
 * Ability: beacon-campaign-sender/update-contact
 *
 * Update an existing Brevo contact's attributes, list memberships,
 * blacklist state, and optionally primary email address. Beacon Campaign Sender
 * performs a read-then-write diff so changes_applied reflects actual
 * changes, not just requested fields.
 *
 * Warning: Brevo documents that updating a blocklisted contact's email
 * address resubscribes that contact by removing the blocklist.
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
			'beacon-campaign-sender/update-contact',
			array(
				'label'               => __( 'Update Brevo Contact', 'beacon-campaign-sender' ),
				'description'         => 'Update an existing Brevo contact by email. Supports FIRSTNAME, LASTNAME, arbitrary Brevo attributes, list add/remove, blacklist flags, and email changes. Uses a read-then-write diff so changes_applied reflects actual changes. Updating a blocklisted contact email may resubscribe them per Brevo behavior.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'email'             => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => 'Email of the contact to update.',
						),
						'new_email'         => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => 'Optional new primary email address.',
						),
						'first_name'        => array(
							'type'        => 'string',
							'description' => 'Optional first name mapped to FIRSTNAME.',
						),
						'last_name'         => array(
							'type'        => 'string',
							'description' => 'Optional last name mapped to LASTNAME.',
						),
						'attributes'        => array(
							'type'                 => 'object',
							'description'          => 'Optional arbitrary Brevo attributes. Scalar values only; keys should be uppercase.',
							'additionalProperties' => true,
						),
						'add_to_lists'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Optional list IDs to add the contact to.',
						),
						'remove_from_lists' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Optional list IDs to remove the contact from.',
						),
						'email_blacklisted' => array(
							'type'        => 'boolean',
							'description' => 'Optional email blacklist state.',
						),
						'sms_blacklisted'   => array(
							'type'        => 'boolean',
							'description' => 'Optional SMS blacklist state.',
						),
					),
					'required'             => array( 'email' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'email'           => array( 'type' => 'string' ),
						'contact_id'      => array( 'type' => 'integer' ),
						'updated'         => array( 'type' => 'boolean' ),
						'changes_applied' => array(
							'type'       => 'object',
							'properties' => array(
								'attributes'        => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'lists_added'       => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'lists_removed'     => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'blacklist_changed' => array( 'type' => 'boolean' ),
								'email_changed'     => array( 'type' => 'boolean' ),
							),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_update_contact',
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
 * Execute callback for beacon-campaign-sender/update-contact.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function bcsend_ability_update_contact( $input = array() ) {
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

	$new_email = '';
	if ( isset( $input['new_email'] ) && '' !== (string) $input['new_email'] ) {
		$new_email = strtolower( sanitize_email( $input['new_email'] ) );
		if ( empty( $new_email ) ) {
			return new WP_Error( 'invalid_new_email', 'A valid new_email address is required.' );
		}
	}

	$requested_attribute_updates = array();
	if ( ! empty( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
		foreach ( $input['attributes'] as $key => $value ) {
			$attribute_key = strtoupper( sanitize_key( $key ) );
			if ( '' === $attribute_key || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$requested_attribute_updates[ $attribute_key ] = (bool) $value;
			} elseif ( is_numeric( $value ) ) {
				$requested_attribute_updates[ $attribute_key ] = 0 + $value;
			} else {
				$requested_attribute_updates[ $attribute_key ] = sanitize_text_field( (string) $value );
			}
		}
	}

	if ( ! empty( $input['first_name'] ) ) {
		$requested_attribute_updates['FIRSTNAME'] = sanitize_text_field( $input['first_name'] );
	}

	if ( ! empty( $input['last_name'] ) ) {
		$requested_attribute_updates['LASTNAME'] = sanitize_text_field( $input['last_name'] );
	}

	if ( '' !== $new_email ) {
		$requested_attribute_updates['EMAIL'] = $new_email;
	}

	$add_to_lists      = ! empty( $input['add_to_lists'] ) && is_array( $input['add_to_lists'] ) ? array_values( array_unique( array_map( 'intval', $input['add_to_lists'] ) ) ) : array();
	$remove_from_lists = ! empty( $input['remove_from_lists'] ) && is_array( $input['remove_from_lists'] ) ? array_values( array_unique( array_map( 'intval', $input['remove_from_lists'] ) ) ) : array();

	$requested_email_blacklisted = array_key_exists( 'email_blacklisted', $input ) ? rest_sanitize_boolean( $input['email_blacklisted'] ) : null;
	$requested_sms_blacklisted   = array_key_exists( 'sms_blacklisted', $input ) ? rest_sanitize_boolean( $input['sms_blacklisted'] ) : null;

	if (
		'' === $new_email &&
		empty( $requested_attribute_updates ) &&
		empty( $add_to_lists ) &&
		empty( $remove_from_lists ) &&
		null === $requested_email_blacklisted &&
		null === $requested_sms_blacklisted
	) {
		return new WP_Error( 'no_changes_requested', 'At least one contact modification is required.' );
	}

	$current_contact = $brevo->get_contact( $email );
	if ( is_wp_error( $current_contact ) ) {
		return $current_contact;
	}

	$current_attributes = isset( $current_contact['attributes'] ) && is_array( $current_contact['attributes'] )
		? $current_contact['attributes']
		: array();
	$current_list_ids   = isset( $current_contact['listIds'] ) ? array_values( array_map( 'intval', (array) $current_contact['listIds'] ) ) : array();
	$current_email      = isset( $current_contact['email'] ) ? strtolower( (string) $current_contact['email'] ) : $email;
	$current_contact_id = isset( $current_contact['id'] ) ? (int) $current_contact['id'] : 0;

	$attributes_to_update = array();
	$attribute_changes    = array();
	foreach ( $requested_attribute_updates as $key => $value ) {
		$current_value = isset( $current_attributes[ $key ] ) ? $current_attributes[ $key ] : null;
		if ( $current_value !== $value ) {
			$attributes_to_update[ $key ] = $value;
			$attribute_changes[]          = $key;
		}
	}

	$lists_added   = array_values( array_diff( $add_to_lists, $current_list_ids ) );
	$lists_removed = array_values( array_intersect( $remove_from_lists, $current_list_ids ) );

	$email_blacklist_changed = null !== $requested_email_blacklisted
		? ( ! empty( $current_contact['emailBlacklisted'] ) !== (bool) $requested_email_blacklisted )
		: false;
	$sms_blacklist_changed   = null !== $requested_sms_blacklisted
		? ( ! empty( $current_contact['smsBlacklisted'] ) !== (bool) $requested_sms_blacklisted )
		: false;
	$email_changed           = '' !== $new_email && $new_email !== $current_email;

	$payload = array();

	if ( ! empty( $attributes_to_update ) ) {
		$payload['attributes'] = $attributes_to_update;
	}

	if ( ! empty( $lists_added ) ) {
		$payload['listIds'] = $lists_added;
	}

	if ( ! empty( $lists_removed ) ) {
		$payload['unlinkListIds'] = $lists_removed;
	}

	if ( $email_blacklist_changed ) {
		$payload['emailBlacklisted'] = (bool) $requested_email_blacklisted;
	}

	if ( $sms_blacklist_changed ) {
		$payload['smsBlacklisted'] = (bool) $requested_sms_blacklisted;
	}

	if ( ! empty( $payload ) ) {
		$response = $brevo->update_contact( $email, $payload );
		if ( is_wp_error( $response ) ) {
			Bcsend_Logger::log(
				'abilities',
				'update_contact failed: ' . $response->get_error_message(),
				wp_json_encode(
					array(
						'email'             => $email,
						'attribute_keys'    => $attribute_changes,
						'lists_added'       => $lists_added,
						'lists_removed'     => $lists_removed,
						'email_changed'     => $email_changed,
						'blacklist_changed' => $email_blacklist_changed || $sms_blacklist_changed,
					)
				),
				'error'
			);

			return $response;
		}
	}

	$final_email = $email_changed ? $new_email : $current_email;

	$log_payload = array(
		'email'             => $email,
		'attribute_keys'    => $attribute_changes,
		'lists_added'       => $lists_added,
		'lists_removed'     => $lists_removed,
		'email_changed'     => $email_changed,
		'blacklist_changed' => $email_blacklist_changed || $sms_blacklist_changed,
	);

	if ( $email_changed ) {
		$log_payload['old_email'] = $current_email;
		$log_payload['new_email'] = $new_email;
	}

	Bcsend_Logger::log(
		'abilities',
		'update_contact executed',
		wp_json_encode( $log_payload )
	);

	return array(
		'email'           => $final_email,
		'contact_id'      => $current_contact_id,
		'updated'         => true,
		'changes_applied' => array(
			'attributes'        => $attribute_changes,
			'lists_added'       => array_values( array_map( 'intval', $lists_added ) ),
			'lists_removed'     => array_values( array_map( 'intval', $lists_removed ) ),
			'blacklist_changed' => $email_blacklist_changed || $sms_blacklist_changed,
			'email_changed'     => $email_changed,
		),
	);
}
