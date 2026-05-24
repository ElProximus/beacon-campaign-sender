<?php
/**
 * Ability: beacon-campaign-sender/send-test-email
 *
 * Send a test email to a single address via Brevo's transactional API.
 * Useful for previewing campaign content in a real inbox before scheduling.
 *
 * @package Bcsend_Plugin
 * @since   2.1.3
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
			'beacon-campaign-sender/send-test-email',
			array(
				'label'               => __( 'Send Test Email', 'beacon-campaign-sender' ),
				'description'         => 'Send a test email to a single address via Brevo. Use this to preview a campaign in a real inbox before scheduling. Provide a campaign_id to send that campaign\'s content, or provide custom subject and html_content.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'to_email'     => array(
							'type'        => 'string',
							'description' => 'Recipient email address for the test.',
						),
						'campaign_id'  => array(
							'type'        => 'integer',
							'description' => 'Campaign ID to send as a test. Uses the campaign subject and HTML content.',
						),
						'subject'      => array(
							'type'        => 'string',
							'description' => 'Email subject line. Overrides campaign subject if both provided.',
						),
						'html_content' => array(
							'type'        => 'string',
							'description' => 'Email HTML content. Overrides campaign content if both provided.',
						),
					),
					'required'             => array( 'to_email' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'message' => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_send_test_email',
				'permission_callback' => function () {
					return current_user_can( 'manage_bcsend' );
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
 * Send a test email via Brevo transactional API.
 *
 * @param array $input {
 *     @type string $to_email     Required. Recipient email.
 *     @type int    $campaign_id  Optional. Campaign to pull content from.
 *     @type string $subject      Optional. Custom subject line.
 *     @type string $html_content Optional. Custom HTML content.
 * }
 * @return array|WP_Error Result message or WP_Error.
 */
function bcsend_ability_send_test_email( $input = array() ) {
	global $wpdb;

	$to_email     = isset( $input['to_email'] ) ? sanitize_email( $input['to_email'] ) : '';
	$campaign_id  = isset( $input['campaign_id'] ) ? absint( $input['campaign_id'] ) : 0;
	$subject      = isset( $input['subject'] ) ? sanitize_text_field( $input['subject'] ) : '';
	$html_content = isset( $input['html_content'] ) ? bcsend_kses_email( $input['html_content'] ) : '';
	$reply_to     = '';

	if ( empty( $to_email ) || ! is_email( $to_email ) ) {
		return new WP_Error( 'invalid_email', 'A valid to_email address is required.' );
	}

	// Pull content from a campaign if provided.
	if ( $campaign_id > 0 ) {
		$table    = $wpdb->prefix . 'bcsend_campaigns';
		$campaign = $wpdb->get_row(
			$wpdb->prepare( "SELECT subject, html_content, reply_to FROM {$table} WHERE id = %d", $campaign_id ),
			ARRAY_A
		);

		if ( ! $campaign ) {
			return new WP_Error( 'campaign_not_found', 'Campaign not found.' );
		}

		if ( empty( $subject ) ) {
			$subject = $campaign['subject'];
		}
		if ( empty( $html_content ) ) {
			$html_content = $campaign['html_content'];
		}

		$reply_to = isset( $campaign['reply_to'] ) ? $campaign['reply_to'] : '';
	}

	if ( empty( $subject ) ) {
		$subject = 'Beacon Campaign Sender Test Email';
	}

	if ( empty( $html_content ) ) {
		$html_content = '<html><body><h1>Test Email</h1><p>This is a test email from Beacon Campaign Sender.</p></body></html>';
	}

	$settings = Bcsend_Settings::get_settings();
	$api_key  = isset( $settings['brevo_api_key'] ) ? $settings['brevo_api_key'] : '';

	if ( empty( $api_key ) ) {
		return new WP_Error( 'not_configured', 'Brevo API key is not configured.' );
	}

	$sender_name  = ! empty( $settings['brevo_sender_name'] ) ? $settings['brevo_sender_name'] : get_bloginfo( 'name' );
	$sender_email = ! empty( $settings['brevo_sender_email'] ) ? $settings['brevo_sender_email'] : get_option( 'admin_email' );

	$payload = array(
		'sender'      => array(
			'name'  => $sender_name,
			'email' => $sender_email,
		),
		'to'          => array(
			array( 'email' => $to_email ),
		),
		'subject'     => $subject,
		'htmlContent' => $html_content,
	);

	$reply_to_email = bcsend_get_campaign_reply_to( $reply_to );
	if ( ! empty( $reply_to_email ) ) {
		$payload['replyTo'] = array( 'email' => $reply_to_email );
	}

	$response = wp_remote_post(
		'https://api.brevo.com/v3/smtp/email',
		array(
			'headers' => array(
				'api-key'      => $api_key,
				'content-type' => 'application/json',
				'accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		Bcsend_Logger::log( 'email', 'Test email failed: ' . $response->get_error_message(), '', 'error' );
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		$error_msg = isset( $body['message'] ) ? $body['message'] : 'Failed to send test email.';
		Bcsend_Logger::log( 'email', 'Test email failed: ' . $error_msg, wp_json_encode( $body ), 'error' );
		return new WP_Error( 'send_failed', $error_msg );
	}

	Bcsend_Logger::log( 'email', 'Test email sent to ' . $to_email );

	return array(
		'message' => sprintf( 'Test email sent to %s.', $to_email ),
	);
}
