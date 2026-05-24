<?php
/**
 * Ability: beacon-campaign-sender/create-social-post
 *
 * Create and send social posts through Zernio.
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
			'beacon-campaign-sender/create-social-post',
			array(
				'label'               => __( 'Create Social Post', 'beacon-campaign-sender' ),
				'description'         => 'Create one or more social posts via Zernio for a campaign or ad hoc publishing.',
				'category'            => 'beacon-campaign-sender',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'campaign_id' => array( 'type' => 'integer' ),
						'platforms'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => 'bcsend_ability_create_social_post',
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
 * Create/send social posts.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function bcsend_ability_create_social_post( $input = array() ) {
	global $wpdb;

	$campaign_id = isset( $input['campaign_id'] ) ? absint( $input['campaign_id'] ) : 0;
	$platforms   = isset( $input['platforms'] ) ? (array) $input['platforms'] : array();

	if ( empty( $campaign_id ) && empty( $platforms ) ) {
		return new WP_Error( 'missing_social_input', 'Provide a campaign_id or platform payloads.' );
	}

	if ( $campaign_id > 0 ) {
		$result = Bcsend_Social_Sender::send_for_campaign( $campaign_id );
		return array(
			'posts' => array( $result ),
		);
	}

	$table     = $wpdb->prefix . 'bcsend_social_posts';
	$supported = array_keys( Bcsend_Zernio_API::get_supported_platforms() );
	$post_ids  = array();
	$errors    = array();

	foreach ( $platforms as $idx => $entry ) {
		$platform   = isset( $entry['platform'] ) ? sanitize_key( $entry['platform'] ) : '';
		$account_id = isset( $entry['account_id'] ) ? sanitize_text_field( $entry['account_id'] ) : '';
		$content    = isset( $entry['content'] ) ? sanitize_textarea_field( $entry['content'] ) : '';
		$scheduled  = isset( $entry['scheduled_for'] ) ? sanitize_text_field( $entry['scheduled_for'] ) : null;

		if ( empty( $platform ) || empty( $account_id ) || empty( $content ) ) {
			$errors[] = sprintf( 'Row %d: platform, account_id, and content are required.', $idx );
			continue;
		}

		if ( ! in_array( $platform, $supported, true ) ) {
			$errors[] = sprintf( 'Row %d: unsupported platform "%s".', $idx, $platform );
			continue;
		}

		if ( ! empty( $scheduled ) && false === strtotime( $scheduled ) ) {
			$errors[] = sprintf( 'Row %d: invalid scheduled_for datetime.', $idx );
			continue;
		}

		$post_data = array(
			'campaign_id' => 0,
			'platform'    => $platform,
			'account_id'  => $account_id,
			'content'     => $content,
			'status'      => 'draft',
		);
		$format    = array( '%d', '%s', '%s', '%s', '%s' );
		if ( ! empty( $scheduled ) ) {
			$post_data['scheduled_for'] = $scheduled;
			$format[]                   = '%s';
		}

		$wpdb->insert( $table, $post_data, $format );

		$post_ids[] = (int) $wpdb->insert_id;
	}

	if ( empty( $post_ids ) && ! empty( $errors ) ) {
		return new WP_Error( 'validation_errors', implode( ' ', $errors ) );
	}

	return array(
		'posts'  => $post_ids,
		'errors' => $errors,
	);
}
