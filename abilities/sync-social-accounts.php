<?php
/**
 * Ability: beacon-campaign-sender/sync-social-accounts
 *
 * Sync connected social accounts from Zernio.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
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
			'beacon-campaign-sender/sync-social-accounts',
			array(
				'label'               => __( 'Sync Social Accounts', 'beacon-campaign-sender' ),
				'description'         => 'Sync connected social media accounts from Zernio and store them for Beacon Campaign Sender composer use.',
				'category'            => 'beacon-campaign-sender',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'accounts' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => 'bcsend_ability_sync_social_accounts',
				'permission_callback' => function () {
					return current_user_can( 'manage_bcsend' );
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
 * Sync Zernio social accounts.
 *
 * @return array|WP_Error
 */
function bcsend_ability_sync_social_accounts() {
	$api = new Bcsend_Zernio_API();

	if ( ! $api->is_configured() ) {
		return new WP_Error( 'zernio_not_configured', 'Zernio API key not configured.' );
	}

	$accounts = $api->list_accounts();

	if ( is_wp_error( $accounts ) ) {
		return $accounts;
	}

	$validated = bcsend_sanitize_zernio_accounts( $accounts );
	update_option( 'bcsend_zernio_accounts', $validated, false );

	return array(
		'accounts' => $validated,
	);
}

/**
 * Sanitize and whitelist Zernio account data before storing.
 *
 * @param mixed $accounts Raw accounts from API.
 * @return array Sanitized accounts.
 */
function bcsend_sanitize_zernio_accounts( $accounts ) {
	if ( ! is_array( $accounts ) ) {
		return array();
	}

	$clean = array();
	foreach ( $accounts as $acc ) {
		if ( ! is_array( $acc ) ) {
			continue;
		}
		$profile_id = '';
		if ( isset( $acc['profileId'] ) && is_array( $acc['profileId'] ) ) {
			$profile_id = isset( $acc['profileId']['_id'] ) ? sanitize_text_field( $acc['profileId']['_id'] ) : '';
		} elseif ( isset( $acc['profileId'] ) ) {
			$profile_id = sanitize_text_field( $acc['profileId'] );
		}

		$clean[] = array(
			'_id'            => isset( $acc['_id'] ) ? sanitize_text_field( $acc['_id'] ) : '',
			'platform'       => isset( $acc['platform'] ) ? sanitize_key( $acc['platform'] ) : '',
			'displayName'    => isset( $acc['displayName'] ) ? sanitize_text_field( $acc['displayName'] ) : '',
			'username'       => isset( $acc['username'] ) ? sanitize_text_field( $acc['username'] ) : '',
			'profilePicture' => isset( $acc['profilePicture'] ) ? esc_url_raw( $acc['profilePicture'] ) : '',
			'isActive'       => ! empty( $acc['isActive'] ),
			'profileId'      => $profile_id,
		);
	}

	return $clean;
}
