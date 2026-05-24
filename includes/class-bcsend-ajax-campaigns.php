<?php
/**
 * Campaign-focused AJAX handlers for Beacon Campaign Sender.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Ajax_Campaigns
 */
class Bcsend_Ajax_Campaigns {

	/**
	 * Register campaign workflow AJAX endpoints.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_bcsend_generate_campaign', array( $this, 'ajax_generate_campaign' ) );
		add_action( 'wp_ajax_bcsend_regenerate_html', array( $this, 'ajax_regenerate_html' ) );
		add_action( 'wp_ajax_bcsend_regenerate_push', array( $this, 'ajax_regenerate_push' ) );
		add_action( 'wp_ajax_bcsend_regenerate_social', array( $this, 'ajax_regenerate_social' ) );
		add_action( 'wp_ajax_bcsend_save_draft', array( $this, 'ajax_save_draft' ) );
		add_action( 'wp_ajax_bcsend_approve_schedule', array( $this, 'ajax_approve_schedule' ) );
		add_action( 'wp_ajax_bcsend_send_campaign_preview_email', array( $this, 'ajax_send_campaign_preview_email' ) );
		add_action( 'wp_ajax_bcsend_get_campaigns', array( $this, 'ajax_get_campaigns' ) );
		add_action( 'wp_ajax_bcsend_delete_campaign', array( $this, 'ajax_delete_campaign' ) );
		add_action( 'wp_ajax_bcsend_revert_to_draft', array( $this, 'ajax_revert_to_draft' ) );
		add_action( 'wp_ajax_bcsend_send_now', array( $this, 'ajax_send_now' ) );
		add_action( 'wp_ajax_bcsend_get_campaign', array( $this, 'ajax_get_campaign' ) );
	}

	/**
	 * Resolve a segment ID from a dropdown value.
	 *
	 * @param string $raw Raw dropdown value.
	 * @return int
	 */
	private function resolve_segment_id( $raw ) {
		if ( empty( $raw ) ) {
			return 0;
		}

		if ( 0 === strpos( $raw, 'brevo_' ) ) {
			global $wpdb;

			$brevo_list_id = absint( substr( $raw, 6 ) );
			if ( $brevo_list_id <= 0 ) {
				return 0;
			}

			$seg_table = $wpdb->prefix . 'bcsend_segments';
			$existing  = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$seg_table} WHERE type = 'brevo_list' AND brevo_list_id = %d", $brevo_list_id )
			);

			if ( $existing ) {
				return (int) $existing;
			}

			$wpdb->insert(
				$seg_table,
				array(
					'name'          => 'Brevo List #' . $brevo_list_id,
					'type'          => 'brevo_list',
					'brevo_list_id' => $brevo_list_id,
					'query_type'    => '',
					'query_params'  => '',
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);

			return (int) $wpdb->insert_id;
		}

		return absint( $raw );
	}

	/**
	 * AJAX: Generate a full campaign via AI.
	 *
	 * @return void
	 */
	public function ajax_generate_campaign() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';

		$has_content = ! empty( $_POST['product_ids'] ) || ! empty( $_POST['image_urls'] ) || ! empty( $_POST['post_ids'] );
		if ( empty( $prompt ) && ! $has_content ) {
			wp_send_json_error( array( 'message' => __( 'Enter a prompt or select content to include.', 'beacon-campaign-sender' ) ) );
		}

		if ( empty( $prompt ) && $has_content ) {
			$prompt = 'Create a marketing email campaign featuring the selected content.';
		}

		$image_urls = array();
		if ( ! empty( $_POST['image_urls'] ) && is_array( $_POST['image_urls'] ) ) {
			$image_urls_raw = array_map( 'esc_url_raw', wp_unslash( $_POST['image_urls'] ) );
			$image_urls     = array_values( array_filter( $image_urls_raw ) );
		}

		$post_ids         = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
		$channels         = isset( $_POST['channels'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['channels'] ) ) : array( 'email', 'push' );
		$social_platforms = isset( $_POST['social_platforms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['social_platforms'] ) ) : array();
		$social_post_mode = isset( $_POST['social_post_mode'] ) ? sanitize_key( wp_unslash( $_POST['social_post_mode'] ) ) : '';

		$result = bcsend_ability_generate_campaign_content(
			array(
				'prompt'           => $prompt,
				'product_ids'      => isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array(),
				'image_urls'       => $image_urls,
				'post_ids'         => $post_ids,
				'template_id'      => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
				'current_html'     => isset( $_POST['current_html'] ) ? bcsend_kses_email( wp_unslash( $_POST['current_html'] ) ) : '',
				'channels'         => $channels,
				'social_platforms' => $social_platforms,
				'social_post_mode' => $social_post_mode,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'content'  => wp_json_encode( $result['content'] ),
				'provider' => $result['provider'],
			)
		);
	}

	/**
	 * AJAX: Regenerate HTML content for an existing campaign.
	 *
	 * @return void
	 */
	public function ajax_regenerate_html() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$prompt      = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$plain_text  = $prompt;

		$generated = Bcsend_AI_Service::regenerate_html_from_request( $campaign_id, $plain_text );

		if ( is_wp_error( $generated ) ) {
			Bcsend_Logger::log( 'ai', 'HTML regeneration failed: ' . $generated->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $generated->get_error_message() ) );
		}

		Bcsend_Logger::log( 'ai', 'HTML regenerated for campaign ID ' . $campaign_id );
		wp_send_json_success(
			array(
				'html_content' => $generated['html_content'],
				'provider'     => $generated['provider'],
			)
		);
	}

	/**
	 * AJAX: Regenerate push notification content for an existing campaign.
	 *
	 * @return void
	 */
	public function ajax_regenerate_push() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$prompt       = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$context_text = isset( $_POST['context_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_text'] ) ) : '';

		$generated = Bcsend_AI_Service::regenerate_push_from_request( $campaign_id, $context_text, $prompt );

		if ( is_wp_error( $generated ) ) {
			Bcsend_Logger::log( 'ai', 'Push regeneration failed: ' . $generated->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $generated->get_error_message() ) );
		}

		Bcsend_Logger::log( 'ai', 'Push content regenerated for campaign ID ' . $campaign_id );
		wp_send_json_success(
			array(
				'push_title'   => $generated['push_title'],
				'push_message' => $generated['push_message'],
				'provider'     => $generated['provider'],
			)
		);
	}

	/**
	 * AJAX: Regenerate social post content for selected platforms.
	 *
	 * @return void
	 */
	public function ajax_regenerate_social() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$context_text = isset( $_POST['context_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_text'] ) ) : '';
		$prompt       = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$platforms    = isset( $_POST['social_platforms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['social_platforms'] ) ) : array();
		$social_mode  = isset( $_POST['social_post_mode'] ) ? sanitize_key( wp_unslash( $_POST['social_post_mode'] ) ) : '';

		$generated = Bcsend_AI_Service::regenerate_social_from_request( $campaign_id, $context_text, $platforms, $prompt, $social_mode );

		if ( is_wp_error( $generated ) ) {
			Bcsend_Logger::log( 'ai', 'Social regeneration failed: ' . $generated->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $generated->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'social'   => isset( $generated['social'] ) ? $generated['social'] : array(),
				'provider' => $generated['provider'],
			)
		);
	}

	/**
	 * AJAX: Save a campaign as draft.
	 *
	 * @return void
	 */
	public function ajax_save_draft() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$segment_raw = isset( $_POST['segment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_id'] ) ) : '';
		$segment_id  = $this->resolve_segment_id( $segment_raw );

		$push_target_type = isset( $_POST['push_target_type'] ) ? sanitize_key( wp_unslash( $_POST['push_target_type'] ) ) : 'all_users';
		$push_target_data = isset( $_POST['push_target_data'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['push_target_data'] ), '[]', 'key' ) : '[]';
		$social_post_mode = isset( $_POST['social_post_mode'] ) ? sanitize_key( wp_unslash( $_POST['social_post_mode'] ) ) : '';

		$result = bcsend_ability_save_draft(
			array(
				'id'                  => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'name'                => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'subject'             => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'preview_text'        => isset( $_POST['preview_text'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_text'] ) ) : '',
				'html_content'        => isset( $_POST['html_content'] ) ? bcsend_kses_email( wp_unslash( $_POST['html_content'] ) ) : '',
				'plain_text'          => isset( $_POST['plain_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['plain_text'] ) ) : '',
				'push_title'          => isset( $_POST['push_title'] ) ? sanitize_text_field( wp_unslash( $_POST['push_title'] ) ) : '',
				'push_message'        => isset( $_POST['push_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['push_message'] ) ) : '',
				'send_email'          => isset( $_POST['send_email'] ) ? absint( $_POST['send_email'] ) : 1,
				'send_push'           => isset( $_POST['send_push'] ) ? absint( $_POST['send_push'] ) : 1,
				'send_social'         => isset( $_POST['send_social'] ) ? absint( $_POST['send_social'] ) : 0,
				'social_posts'        => isset( $_POST['social_posts'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_posts'] ), '{}', 'textarea' ) : '{}',
				'social_account_ids'  => isset( $_POST['social_account_ids'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_account_ids'] ), '{}', 'text' ) : '{}',
				'social_media_items'  => isset( $_POST['social_media_items'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_media_items'] ), '{}', 'text' ) : '{}',
				'social_link_modes'   => isset( $_POST['social_link_modes'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_link_modes'] ), '{}', 'key' ) : '{}',
				'social_link_urls'    => isset( $_POST['social_link_urls'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_link_urls'] ), '{}', 'url' ) : '{}',
				'social_link_labels'  => isset( $_POST['social_link_labels'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_link_labels'] ), '{}', 'text' ) : '{}',
				'social_platforms'    => isset( $_POST['social_platforms'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_platforms'] ), '[]', 'key' ) : '[]',
				'social_post_mode'    => $social_post_mode,
				'segment_id'          => $segment_id,
				'push_target_type'    => $push_target_type,
				'push_target_data'    => $push_target_data,
				'scheduled_at'        => isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : null,
				'social_scheduled_at' => isset( $_POST['social_scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['social_scheduled_at'] ) ) : null,
				'content_library'     => isset( $_POST['content_library'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['content_library'] ), '', 'text' ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Approve and schedule a campaign.
	 *
	 * @return void
	 */
	public function ajax_approve_schedule() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. Only managers can approve campaigns.', 'beacon-campaign-sender' ) ) );
		}

		$id                  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name                = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$subject             = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$preview_text        = isset( $_POST['preview_text'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_text'] ) ) : '';
		$html_content        = isset( $_POST['html_content'] ) ? bcsend_kses_email( wp_unslash( $_POST['html_content'] ) ) : '';
		$plain_text          = isset( $_POST['plain_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['plain_text'] ) ) : '';
		$push_title          = isset( $_POST['push_title'] ) ? sanitize_text_field( wp_unslash( $_POST['push_title'] ) ) : '';
		$push_message        = isset( $_POST['push_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['push_message'] ) ) : '';
		$send_email          = isset( $_POST['send_email'] ) ? absint( $_POST['send_email'] ) : 1;
		$send_push           = isset( $_POST['send_push'] ) ? absint( $_POST['send_push'] ) : 1;
		$send_social         = isset( $_POST['send_social'] ) ? absint( $_POST['send_social'] ) : 0;
		$social_posts        = isset( $_POST['social_posts'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_posts'] ), '{}', 'textarea' ) : '{}';
		$social_account_ids  = isset( $_POST['social_account_ids'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_account_ids'] ), '{}', 'text' ) : '{}';
		$social_media_items  = isset( $_POST['social_media_items'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_media_items'] ), '{}', 'text' ) : '{}';
		$social_link_modes   = isset( $_POST['social_link_modes'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_link_modes'] ), '{}', 'key' ) : '{}';
		$social_link_urls    = isset( $_POST['social_link_urls'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_link_urls'] ), '{}', 'url' ) : '{}';
		$social_link_labels  = isset( $_POST['social_link_labels'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_link_labels'] ), '{}', 'text' ) : '{}';
		$social_platforms    = isset( $_POST['social_platforms'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['social_platforms'] ), '[]', 'key' ) : '[]';
		$segment_raw         = isset( $_POST['segment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_id'] ) ) : '';
		$push_target_type    = isset( $_POST['push_target_type'] ) ? sanitize_key( wp_unslash( $_POST['push_target_type'] ) ) : 'all_users';
		$push_target_data    = isset( $_POST['push_target_data'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['push_target_data'] ), '[]', 'key' ) : '[]';
		$social_post_mode    = isset( $_POST['social_post_mode'] ) ? sanitize_key( wp_unslash( $_POST['social_post_mode'] ) ) : '';
		$scheduled_at        = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
		$social_scheduled_at = isset( $_POST['social_scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['social_scheduled_at'] ) ) : '';
		$content_library     = isset( $_POST['content_library'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['content_library'] ), '', 'text' ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign name is required.', 'beacon-campaign-sender' ) ) );
		}

		if ( empty( $scheduled_at ) ) {
			wp_send_json_error( array( 'message' => __( 'A future scheduled time is required.', 'beacon-campaign-sender' ) ) );
		}

		if ( ! $send_email && ! $send_push && ! $send_social ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one delivery channel: email, push, or social.', 'beacon-campaign-sender' ) ) );
		}

		if ( $send_email && ( empty( $subject ) || empty( $html_content ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign must have a subject and HTML content before approval.', 'beacon-campaign-sender' ) ) );
		}

		if ( $send_email && empty( $segment_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign must have a target segment before approval.', 'beacon-campaign-sender' ) ) );
		}

		$segment_id = empty( $segment_raw ) ? 0 : $this->resolve_segment_id( $segment_raw );

		if ( $send_email && empty( $segment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid audience selection.', 'beacon-campaign-sender' ) ) );
		}

		if ( $send_social ) {
			$social_validation = Bcsend_Social_Workflow::validate_transport(
				$social_posts,
				$social_account_ids,
				$social_media_items,
				$social_link_modes,
				$social_link_urls,
				$social_link_labels,
				$social_platforms,
				$content_library,
				true
			);

			if ( ! empty( $social_validation['errors'] ) ) {
				wp_send_json_error(
					array(
						'message' => implode( ' ', $social_validation['errors'] ),
					)
				);
			}
		}

		$draft_result = bcsend_ability_save_draft(
			array(
				'id'                  => $id,
				'name'                => $name,
				'subject'             => $subject,
				'preview_text'        => $preview_text,
				'html_content'        => $html_content,
				'plain_text'          => $plain_text,
				'push_title'          => $push_title,
				'push_message'        => $push_message,
				'send_email'          => $send_email,
				'send_push'           => $send_push,
				'send_social'         => $send_social,
				'social_posts'        => $social_posts,
				'social_account_ids'  => $social_account_ids,
				'social_media_items'  => $social_media_items,
				'social_link_modes'   => $social_link_modes,
				'social_link_urls'    => $social_link_urls,
				'social_link_labels'  => $social_link_labels,
				'social_platforms'    => $social_platforms,
				'social_post_mode'    => $social_post_mode,
				'segment_id'          => $segment_id,
				'push_target_type'    => $push_target_type,
				'push_target_data'    => $push_target_data,
				'scheduled_at'        => $scheduled_at,
				'social_scheduled_at' => $social_scheduled_at,
				'content_library'     => $content_library,
			)
		);

		if ( is_wp_error( $draft_result ) ) {
			wp_send_json_error( array( 'message' => $draft_result->get_error_message() ) );
		}

		$id = isset( $draft_result['id'] ) ? (int) $draft_result['id'] : $id;

		$tz_offset = isset( $_POST['tz_offset'] ) ? intval( $_POST['tz_offset'] ) : null;

		$schedule_result = bcsend_ability_schedule_campaign(
			array(
				'campaign_id'         => $id,
				'scheduled_at'        => $scheduled_at,
				'tz_offset'           => $tz_offset,
				'social_scheduled_at' => $social_scheduled_at,
			)
		);

		if ( is_wp_error( $schedule_result ) ) {
			Bcsend_Logger::log( 'campaign', 'Failed to schedule campaign ID ' . $id . ': ' . $schedule_result->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $schedule_result->get_error_message() ) );
		}

		Bcsend_Logger::log( 'campaign', 'Campaign approved and scheduled: ' . $name . ' (ID ' . $id . ')' );

		wp_send_json_success(
			array(
				'message' => __( 'Campaign approved and scheduled.', 'beacon-campaign-sender' ),
				'id'      => $id,
				'status'  => 'scheduled',
			)
		);
	}

	/**
	 * AJAX: Send a campaign preview email through Brevo transactional email.
	 *
	 * @return void
	 */
	public function ajax_send_campaign_preview_email() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$to_email     = isset( $_POST['to_email'] ) ? sanitize_email( wp_unslash( $_POST['to_email'] ) ) : '';
		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$subject      = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$html_content = isset( $_POST['html_content'] ) ? bcsend_kses_email( wp_unslash( $_POST['html_content'] ) ) : '';

		if ( empty( $to_email ) || ! is_email( $to_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Valid email address required.', 'beacon-campaign-sender' ) ) );
		}

		if ( empty( $html_content ) ) {
			wp_send_json_error( array( 'message' => __( 'Generate campaign content first.', 'beacon-campaign-sender' ) ) );
		}

		$result = bcsend_ability_send_test_email(
			array(
				'to_email'     => $to_email,
				'campaign_id'  => $campaign_id,
				'subject'      => $subject,
				'html_content' => $html_content,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get campaigns list.
	 *
	 * @return void
	 */
	public function ajax_get_campaigns() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_campaigns';

		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( $_POST['per_page'] ) ) ) : 20;
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! empty( $status ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, subject, push_title, segment_id, scheduled_at, status, email_status, push_status, social_status, created_at, approved_at, sent_at FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, subject, push_title, segment_id, scheduled_at, status, email_status, push_status, social_status, created_at, approved_at, sent_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		wp_send_json_success(
			array(
				'items'    => $items ? $items : array(),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * AJAX: Delete a campaign.
	 *
	 * @return void
	 */
	public function ajax_delete_campaign() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$result = bcsend_ability_delete_campaign( array( 'campaign_id' => $id ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Revert a scheduled/approved campaign back to draft.
	 *
	 * @return void
	 */
	public function ajax_revert_to_draft() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_campaigns';

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$campaign = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, name FROM {$table} WHERE id = %d", $id )
		);

		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found.', 'beacon-campaign-sender' ) ) );
		}

		$revertable = array( 'scheduled', 'approved', 'failed' );

		if ( ! in_array( $campaign->status, $revertable, true ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: current campaign status */
						__( 'Cannot revert a campaign with status "%s".', 'beacon-campaign-sender' ),
						$campaign->status
					),
				)
			);
		}

		if ( 'scheduled' === $campaign->status ) {
			Bcsend_Scheduler::unschedule_campaign( $id );
		}

		$wpdb->update(
			$table,
			array(
				'status'       => 'draft',
				'scheduled_at' => null,
				'approved_at'  => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log( 'campaign', 'Campaign reverted to draft: ' . $campaign->name . ' (ID ' . $id . ')' );

		wp_send_json_success( array( 'message' => __( 'Campaign reverted to draft.', 'beacon-campaign-sender' ) ) );
	}

	/**
	 * AJAX: Manually trigger campaign send now.
	 *
	 * @return void
	 */
	public function ajax_send_now() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$result = Bcsend_Campaign_Sender::send( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'      => __( 'Campaign sent.', 'beacon-campaign-sender' ),
				'status'       => isset( $result['status'] ) ? $result['status'] : '',
				'email_status' => isset( $result['email_status'] ) ? $result['email_status'] : '',
				'push_status'  => isset( $result['push_status'] ) ? $result['push_status'] : '',
			)
		);
	}

	/**
	 * AJAX: Get a single campaign.
	 *
	 * @return void
	 */
	public function ajax_get_campaign() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$campaign = bcsend_ability_get_campaign( array( 'campaign_id' => $id ) );

		if ( is_wp_error( $campaign ) ) {
			wp_send_json_error( array( 'message' => $campaign->get_error_message() ) );
		}

		if ( ! empty( $campaign['send_social'] ) ) {
			Bcsend_Social_Sender::refresh_campaign_statuses( $id );
			$campaign = bcsend_ability_get_campaign( array( 'campaign_id' => $id ) );
		}

		wp_send_json_success( array( 'campaign' => $campaign ) );
	}
}
