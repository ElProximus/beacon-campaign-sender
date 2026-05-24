<?php
/**
 * Ability: beacon-campaign-sender/save-draft
 *
 * Create or update a campaign draft. If an ID is provided and the
 * campaign is still in draft status, it updates; otherwise it creates.
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
			'beacon-campaign-sender/save-draft',
			array(
				'label'               => __( 'Save Draft', 'beacon-campaign-sender' ),
				'description'         => 'Create or update a campaign draft. Provide an ID to update an existing draft, or omit to create a new one.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'                  => array(
							'type'        => 'integer',
							'description' => 'Existing campaign ID to update. Omit to create new.',
						),
						'name'                => array(
							'type'        => 'string',
							'description' => 'Campaign name.',
						),
						'subject'             => array(
							'type'        => 'string',
							'description' => 'Email subject line.',
						),
						'preview_text'        => array(
							'type'        => 'string',
							'description' => 'Email preview text.',
						),
						'html_content'        => array(
							'type'        => 'string',
							'description' => 'Email HTML content.',
						),
						'plain_text'          => array(
							'type'        => 'string',
							'description' => 'Email plain text fallback.',
						),
						'reply_to'            => array(
							'type'        => 'string',
							'description' => 'Reply-to email address.',
						),
						'push_title'          => array(
							'type'        => 'string',
							'description' => 'Push notification title.',
						),
						'push_message'        => array(
							'type'        => 'string',
							'description' => 'Push notification body.',
						),
						'send_email'          => array(
							'type'        => 'integer',
							'description' => 'Include email delivery (1=yes, 0=no). Default 1.',
						),
						'send_push'           => array(
							'type'        => 'integer',
							'description' => 'Include push notification (1=yes, 0=no). Default 1.',
						),
						'send_social'         => array(
							'type'        => 'integer',
							'description' => 'Include social posting (1=yes, 0=no).',
						),
						'social_posts'        => array(
							'type'        => 'string',
							'description' => 'JSON object keyed by platform containing social copy.',
						),
						'social_account_ids'  => array(
							'type'        => 'string',
							'description' => 'JSON object keyed by platform containing selected account IDs.',
						),
						'social_media_items'  => array(
							'type'        => 'string',
							'description' => 'JSON object keyed by platform containing mediaItems arrays.',
						),
						'social_link_modes'   => array(
							'type'        => 'string',
							'description' => 'JSON object keyed by platform containing link modes.',
						),
						'social_link_urls'    => array(
							'type'        => 'string',
							'description' => 'JSON object keyed by platform containing resolved or custom link URLs.',
						),
						'social_link_labels'  => array(
							'type'        => 'string',
							'description' => 'JSON object keyed by platform containing optional link labels.',
						),
						'social_platforms'    => array(
							'type'        => 'string',
							'description' => 'JSON array of selected social platforms.',
						),
						'social_post_mode'    => array(
							'type'        => 'string',
							'description' => 'Zernio posting mode: single or per_platform.',
						),
						'segment_id'          => array(
							'type'        => 'integer',
							'description' => 'Target segment ID.',
						),
						'push_target_type'    => array(
							'type'        => 'string',
							'description' => 'Push audience target type: all_users, by_role, or specific_users.',
						),
						'push_target_data'    => array(
							'type'        => 'string',
							'description' => 'JSON array of roles (for by_role) or user IDs (for specific_users).',
						),
						'scheduled_at'        => array(
							'type'        => 'string',
							'description' => 'Planned send time (ISO 8601).',
						),
						'social_scheduled_at' => array(
							'type'        => 'string',
							'description' => 'Optional dedicated social schedule datetime.',
						),
						'content_library'     => array(
							'type'        => 'string',
							'description' => 'JSON snapshot of selected content-library items.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => 'Campaign ID.',
						),
						'message'  => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
						'warnings' => array(
							'type'        => 'array',
							'description' => 'Optional non-blocking validation warnings.',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_save_draft',
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
 * Create or update a campaign draft.
 *
 * @param array $input Campaign fields. 'name' required. 'id' to update existing.
 * @return array|WP_Error Result with campaign ID, or WP_Error.
 */
function bcsend_ability_save_draft( $input = array() ) {
	global $wpdb;

	$table = $wpdb->prefix . 'bcsend_campaigns';

	$id               = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
	$name             = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
	$subject          = isset( $input['subject'] ) ? sanitize_text_field( $input['subject'] ) : '';
	$preview_text     = isset( $input['preview_text'] ) ? sanitize_text_field( $input['preview_text'] ) : '';
	$html_content     = isset( $input['html_content'] ) ? bcsend_kses_email( $input['html_content'] ) : '';
	$plain_text       = isset( $input['plain_text'] ) ? sanitize_textarea_field( $input['plain_text'] ) : '';
	$reply_to         = isset( $input['reply_to'] ) ? sanitize_email( $input['reply_to'] ) : '';
	$push_title       = isset( $input['push_title'] ) ? sanitize_text_field( $input['push_title'] ) : '';
	$push_message     = isset( $input['push_message'] ) ? sanitize_textarea_field( $input['push_message'] ) : '';
	$send_email       = isset( $input['send_email'] ) ? absint( $input['send_email'] ) : 1;
	$send_push        = isset( $input['send_push'] ) ? absint( $input['send_push'] ) : 1;
	$send_social      = isset( $input['send_social'] ) ? absint( $input['send_social'] ) : 0;
	$segment_id       = isset( $input['segment_id'] ) ? absint( $input['segment_id'] ) : null;
	$push_target_type = isset( $input['push_target_type'] ) ? sanitize_key( (string) $input['push_target_type'] ) : 'all_users';
	if ( ! in_array( $push_target_type, array( 'all_users', 'by_role', 'specific_users' ), true ) ) {
		$push_target_type = 'all_users';
	}
	$push_target_data_raw = isset( $input['push_target_data'] ) ? (string) $input['push_target_data'] : '[]';
	$push_target_data     = bcsend_sanitize_json_string( $push_target_data_raw, '[]', 'key' );
	$scheduled_at         = isset( $input['scheduled_at'] ) ? sanitize_text_field( $input['scheduled_at'] ) : null;
	$social_scheduled_at  = isset( $input['social_scheduled_at'] ) ? sanitize_text_field( $input['social_scheduled_at'] ) : null;
	if ( ! empty( $social_scheduled_at ) && false === strtotime( $social_scheduled_at ) ) {
		$social_scheduled_at = null;
	}
	$content_library        = isset( $input['content_library'] ) ? bcsend_sanitize_json_string( (string) $input['content_library'], '', 'text' ) : '';
	$social_posts_raw       = isset( $input['social_posts'] ) ? bcsend_sanitize_json_string( (string) $input['social_posts'], '{}', 'textarea' ) : '{}';
	$social_account_ids_raw = isset( $input['social_account_ids'] ) ? bcsend_sanitize_json_string( (string) $input['social_account_ids'], '{}', 'text' ) : '{}';
	$social_media_items_raw = isset( $input['social_media_items'] ) ? bcsend_sanitize_json_string( (string) $input['social_media_items'], '{}', 'text' ) : '{}';
	$social_link_modes_raw  = isset( $input['social_link_modes'] ) ? bcsend_sanitize_json_string( (string) $input['social_link_modes'], '{}', 'key' ) : '{}';
	$social_link_urls_raw   = isset( $input['social_link_urls'] ) ? bcsend_sanitize_json_string( (string) $input['social_link_urls'], '{}', 'url' ) : '{}';
	$social_link_labels_raw = isset( $input['social_link_labels'] ) ? bcsend_sanitize_json_string( (string) $input['social_link_labels'], '{}', 'text' ) : '{}';
	$social_platforms_raw   = isset( $input['social_platforms'] ) ? bcsend_sanitize_json_string( (string) $input['social_platforms'], '[]', 'key' ) : '[]';
	$settings               = get_option( 'bcsend_settings', array() );
	$social_post_mode       = isset( $input['social_post_mode'] ) ? sanitize_key( (string) $input['social_post_mode'] ) : ( isset( $settings['zernio_post_mode'] ) ? sanitize_key( $settings['zernio_post_mode'] ) : 'single' );
	if ( ! in_array( $social_post_mode, array( 'single', 'per_platform' ), true ) ) {
		$social_post_mode = 'single';
	}

	// Validate content_library is valid JSON if provided.
	if ( ! empty( $content_library ) && is_string( $content_library ) ) {
		$decoded = json_decode( $content_library, true );
		if ( null === $decoded ) {
			$content_library = '';
		}
	}

	if ( empty( $name ) ) {
		return new WP_Error( 'missing_name', 'Campaign name is required.' );
	}

	$data = array(
		'name'             => $name,
		'subject'          => $subject,
		'preview_text'     => $preview_text,
		'html_content'     => $html_content,
		'plain_text'       => $plain_text,
		'reply_to'         => $reply_to,
		'push_title'       => $push_title,
		'push_message'     => $push_message,
		'send_email'       => $send_email,
		'send_push'        => $send_push,
		'send_social'      => $send_social,
		'email_status'     => $send_email ? null : 'skipped',
		'social_status'    => $send_social ? null : 'skipped',
		'social_post_mode' => $social_post_mode,
		'segment_id'       => $segment_id,
		'push_target_type' => $push_target_type,
		'push_target_data' => $push_target_data,
		'content_library'  => $content_library,
		'status'           => 'draft',
	);

	$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

	// Only include scheduled_at when set; empty strings break MySQL strict
	// mode on datetime columns (the DEFAULT NULL handles missing values).
	if ( ! empty( $scheduled_at ) ) {
		$data['scheduled_at'] = $scheduled_at;
		$format[]             = '%s';
	}

	if ( ! empty( $social_scheduled_at ) ) {
		$data['social_scheduled_at'] = $social_scheduled_at;
		$format[]                    = '%s';
	}

	if ( $id ) {
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id )
		);

		if ( null === $existing ) {
			return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
		}

		if ( 'draft' !== $existing ) {
			return new WP_Error( 'not_draft', 'Only draft campaigns can be edited.' );
		}

		$result = $wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) );

		// Clear scheduled_at when the user removed the schedule.
		if ( false !== $result && empty( $scheduled_at ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET scheduled_at = NULL WHERE id = %d", $id ) );
		}

		if ( false === $result ) {
			Bcsend_Logger::log( 'campaign', 'Failed to update draft ID ' . $id . ': ' . $wpdb->last_error, '', 'error' );
			return new WP_Error( 'save_failed', 'Failed to save draft.' );
		}

		Bcsend_Logger::log( 'campaign', 'Draft updated: ' . $name . ' (ID ' . $id . ')' );
		Bcsend_Social_Workflow::sync_draft_rows( $id, $send_social, $social_posts_raw, $social_account_ids_raw, $social_media_items_raw, $social_link_modes_raw, $social_link_urls_raw, $social_link_labels_raw, $social_platforms_raw, $social_scheduled_at );

		$warnings = array();
		if ( $send_social ) {
			$validation = Bcsend_Social_Workflow::validate_transport(
				$social_posts_raw,
				$social_account_ids_raw,
				$social_media_items_raw,
				$social_link_modes_raw,
				$social_link_urls_raw,
				$social_link_labels_raw,
				$social_platforms_raw,
				$content_library,
				false
			);
			$warnings   = isset( $validation['warnings'] ) && is_array( $validation['warnings'] ) ? $validation['warnings'] : array();
		}

		return array(
			'id'       => $id,
			'message'  => 'Draft saved.',
			'warnings' => $warnings,
		);
	}

	$result = $wpdb->insert( $table, $data, $format );

	if ( false === $result ) {
		Bcsend_Logger::log( 'campaign', 'Failed to create draft: ' . $wpdb->last_error, '', 'error' );
		return new WP_Error( 'save_failed', 'Failed to create draft.' );
	}

	$new_id = (int) $wpdb->insert_id;
	Bcsend_Social_Workflow::sync_draft_rows( $new_id, $send_social, $social_posts_raw, $social_account_ids_raw, $social_media_items_raw, $social_link_modes_raw, $social_link_urls_raw, $social_link_labels_raw, $social_platforms_raw, $social_scheduled_at );
	Bcsend_Logger::log( 'campaign', 'Draft created: ' . $name . ' (ID ' . $new_id . ')' );

	$warnings = array();
	if ( $send_social ) {
		$validation = Bcsend_Social_Workflow::validate_transport(
			$social_posts_raw,
			$social_account_ids_raw,
			$social_media_items_raw,
			$social_link_modes_raw,
			$social_link_urls_raw,
			$social_link_labels_raw,
			$social_platforms_raw,
			$content_library,
			false
		);
		$warnings   = isset( $validation['warnings'] ) && is_array( $validation['warnings'] ) ? $validation['warnings'] : array();
	}

	return array(
		'id'       => $new_id,
		'message'  => 'Draft created.',
		'warnings' => $warnings,
	);
}
