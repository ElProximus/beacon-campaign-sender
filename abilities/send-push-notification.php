<?php
/**
 * Ability: beacon-campaign-sender/send-push-notification
 *
 * Send a push notification to a segment immediately.
 * Title max 26 characters, message max 354 characters.
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
			'beacon-campaign-sender/send-push-notification',
			array(
				'label'               => __( 'Send Push Notification', 'beacon-campaign-sender' ),
				'description'         => 'Send a push notification to a segment immediately. Title max 26 characters, message max 354 characters.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'      => array(
							'type'        => 'string',
							'description' => 'Notification title (max 26 characters).',
							'maxLength'   => 26,
						),
						'message'    => array(
							'type'        => 'string',
							'description' => 'Notification message body (max 354 characters).',
							'maxLength'   => 354,
						),
						'segment_id' => array(
							'type'        => 'integer',
							'description' => 'Segment ID to send the push notification to.',
						),
					),
					'required'             => array( 'title', 'message', 'segment_id' ),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'sent_count'   => array(
							'type'        => 'integer',
							'description' => 'Notifications sent successfully.',
						),
						'failed_count' => array(
							'type'        => 'integer',
							'description' => 'Notifications that failed.',
						),
						'segment_name' => array(
							'type'        => 'string',
							'description' => 'Name of the target segment.',
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_send_push_notification',
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
 * Send a push notification to a segment immediately.
 *
 * @param array $input {
 *     @type string $title      Required. Notification title (max 26 chars).
 *     @type string $message    Required. Notification body (max 354 chars).
 *     @type int    $segment_id Required. Target segment ID.
 * }
 * @return array|WP_Error Delivery result or WP_Error.
 */
function bcsend_ability_send_push_notification( $input = array() ) {
	$title      = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
	$message    = isset( $input['message'] ) ? sanitize_text_field( $input['message'] ) : '';
	$segment_id = isset( $input['segment_id'] ) ? (int) $input['segment_id'] : 0;

	if ( empty( $title ) || empty( $message ) || empty( $segment_id ) ) {
		return new WP_Error( 'missing_params', 'title, message, and segment_id are all required.' );
	}

	if ( mb_strlen( $title ) > 26 ) {
		return new WP_Error( 'title_too_long', 'Push notification title must be 26 characters or less.' );
	}

	if ( mb_strlen( $message ) > 354 ) {
		return new WP_Error( 'message_too_long', 'Push notification message must be 354 characters or less.' );
	}

	$segment = Bcsend_Segment_Engine::get_segment( $segment_id );

	if ( empty( $segment ) ) {
		return new WP_Error( 'segment_not_found', 'Segment not found.' );
	}

	$user_ids = Bcsend_Segment_Engine::get_user_ids_for_segment( $segment );

	if ( empty( $user_ids ) ) {
		return new WP_Error( 'no_users', 'No users found for the specified segment.' );
	}

	$push_service = new Bcsend_Push_Service();

	if ( ! $push_service->is_configured() ) {
		return new WP_Error( 'push_not_configured', 'Push notification service is not configured.' );
	}

	$tokens = $push_service->get_tokens_for_users( $user_ids );

	if ( empty( $tokens ) ) {
		return new WP_Error( 'no_tokens', 'No push tokens found for segment users.' );
	}

	$result = $push_service->send_batch( $tokens, $title, $message );

	$sent_count   = is_array( $result ) && isset( $result['sent'] ) ? (int) $result['sent'] : 0;
	$failed_count = is_array( $result ) && isset( $result['failed'] ) ? (int) $result['failed'] : 0;

	Bcsend_Logger::log(
		'abilities',
		'send_push_notification succeeded',
		wp_json_encode(
			array(
				'segment_id'   => $segment_id,
				'segment_name' => $segment->name,
				'sent'         => $sent_count,
				'failed'       => $failed_count,
			)
		)
	);

	return array(
		'sent_count'   => $sent_count,
		'failed_count' => $failed_count,
		'segment_name' => $segment->name,
	);
}
