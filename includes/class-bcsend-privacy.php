<?php
/**
 * Privacy integration for Beacon Campaign Sender.
 *
 * Registers privacy policy content plus personal data exporters and erasers
 * for subscriber and email log records managed by the plugin.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Privacy
 */
class Bcsend_Privacy {

	/**
	 * Number of rows to process per export/erase page.
	 *
	 * @var int
	 */
	const PAGE_SIZE = 50;

	/**
	 * Register privacy hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );

		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		}
	}

	/**
	 * Register personal data exporters.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporters( $exporters ) {
		$exporters['beacon-campaign-sender-subscribers'] = array(
			'exporter_friendly_name' => __( 'Beacon Campaign Sender Subscribers', 'beacon-campaign-sender' ),
			'callback'               => array( __CLASS__, 'export_subscribers' ),
		);

		$exporters['beacon-campaign-sender-email-log'] = array(
			'exporter_friendly_name' => __( 'Beacon Campaign Sender Email Log', 'beacon-campaign-sender' ),
			'callback'               => array( __CLASS__, 'export_email_logs' ),
		);

		return $exporters;
	}

	/**
	 * Register personal data erasers.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_erasers( $erasers ) {
		$erasers['beacon-campaign-sender-subscribers'] = array(
			'eraser_friendly_name' => __( 'Beacon Campaign Sender Subscribers', 'beacon-campaign-sender' ),
			'callback'             => array( __CLASS__, 'erase_subscribers' ),
		);

		$erasers['beacon-campaign-sender-email-log'] = array(
			'eraser_friendly_name' => __( 'Beacon Campaign Sender Email Log', 'beacon-campaign-sender' ),
			'callback'             => array( __CLASS__, 'erase_email_logs' ),
		);

		return $erasers;
	}

	/**
	 * Add privacy policy guidance for the plugin.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		$content  = '<p>' . esc_html__( 'Beacon Campaign Sender can store subscriber sign-up records, including email address, name, consent text, source, IP address, browser user agent, and referrer metadata to document newsletter sign-ups and delivery attempts.', 'beacon-campaign-sender' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Beacon Campaign Sender can also store transactional email log records, including recipient addresses, message subject, message body, headers, attachments metadata, sender details, delivery status, and error details to support troubleshooting and resend workflows.', 'beacon-campaign-sender' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If enabled, Beacon Campaign Sender sends data to external services including Brevo for email delivery and contacts, OpenAI or Anthropic for AI content generation, Firebase for push delivery, and Zernio for social publishing. Review your site privacy policy to disclose which integrations are enabled on your installation.', 'beacon-campaign-sender' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Beacon Campaign Sender honors WordPress personal data export and erasure requests for subscriber and email log records stored locally by the plugin.', 'beacon-campaign-sender' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Beacon Campaign Sender', 'beacon-campaign-sender' ), wp_kses_post( $content ) );
	}

	/**
	 * Export subscriber records for an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function export_subscribers( $email_address, $page = 1 ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcsend_subscribers';
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$email  = sanitize_email( $email_address );
		$items  = array();
		$rows   = array();

		if ( is_email( $email ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$email,
					self::PAGE_SIZE,
					$offset
				),
				ARRAY_A
			);
		}

		foreach ( $rows as $row ) {
			$metadata = ! empty( $row['metadata_json'] ) ? json_decode( $row['metadata_json'], true ) : array();
			$list_ids = ! empty( $row['list_ids_json'] ) ? json_decode( $row['list_ids_json'], true ) : array();

			$data = array(
				array(
					'name'  => __( 'Email address', 'beacon-campaign-sender' ),
					'value' => isset( $row['email'] ) ? $row['email'] : '',
				),
				array(
					'name'  => __( 'First name', 'beacon-campaign-sender' ),
					'value' => isset( $row['first_name'] ) ? $row['first_name'] : '',
				),
				array(
					'name'  => __( 'Last name', 'beacon-campaign-sender' ),
					'value' => isset( $row['last_name'] ) ? $row['last_name'] : '',
				),
				array(
					'name'  => __( 'Source', 'beacon-campaign-sender' ),
					'value' => isset( $row['source'] ) ? $row['source'] : '',
				),
				array(
					'name'  => __( 'Consent text', 'beacon-campaign-sender' ),
					'value' => isset( $row['consent_text'] ) ? $row['consent_text'] : '',
				),
				array(
					'name'  => __( 'IP address', 'beacon-campaign-sender' ),
					'value' => isset( $row['ip_address'] ) ? $row['ip_address'] : '',
				),
				array(
					'name'  => __( 'User agent', 'beacon-campaign-sender' ),
					'value' => isset( $row['user_agent'] ) ? $row['user_agent'] : '',
				),
				array(
					'name'  => __( 'Status', 'beacon-campaign-sender' ),
					'value' => isset( $row['status'] ) ? $row['status'] : '',
				),
				array(
					'name'  => __( 'Submitted at', 'beacon-campaign-sender' ),
					'value' => isset( $row['submitted_at'] ) ? $row['submitted_at'] : '',
				),
				array(
					'name'  => __( 'Confirmed at', 'beacon-campaign-sender' ),
					'value' => isset( $row['confirmed_at'] ) ? $row['confirmed_at'] : '',
				),
				array(
					'name'  => __( 'List IDs', 'beacon-campaign-sender' ),
					'value' => ! empty( $list_ids ) && is_array( $list_ids ) ? implode( ', ', array_map( 'strval', $list_ids ) ) : '',
				),
				array(
					'name'  => __( 'Metadata', 'beacon-campaign-sender' ),
					'value' => ! empty( $metadata ) && is_array( $metadata ) ? wp_json_encode( $metadata ) : '',
				),
			);

			$items[] = array(
				'group_id'    => 'beacon-campaign-sender-subscribers',
				'group_label' => __( 'Beacon Campaign Sender Subscribers', 'beacon-campaign-sender' ),
				'item_id'     => 'beacon-campaign-sender-subscriber-' . (int) $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Export email log rows for an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function export_email_logs( $email_address, $page = 1 ) {
		global $wpdb;

		$table  = $wpdb->prefix . Bcsend_Email_Log::TABLE;
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$email  = sanitize_email( $email_address );
		$items  = array();
		$rows   = array();

		if ( is_email( $email ) ) {
			$like = '%' . $wpdb->esc_like( $email ) . '%';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					self::PAGE_SIZE,
					$offset
				),
				ARRAY_A
			);
		}

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'beacon-campaign-sender-email-log',
				'group_label' => __( 'Beacon Campaign Sender Email Log', 'beacon-campaign-sender' ),
				'item_id'     => 'beacon-campaign-sender-email-log-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'To', 'beacon-campaign-sender' ),
						'value' => isset( $row['to_email'] ) ? $row['to_email'] : '',
					),
					array(
						'name'  => __( 'CC', 'beacon-campaign-sender' ),
						'value' => isset( $row['cc'] ) ? $row['cc'] : '',
					),
					array(
						'name'  => __( 'BCC', 'beacon-campaign-sender' ),
						'value' => isset( $row['bcc'] ) ? $row['bcc'] : '',
					),
					array(
						'name'  => __( 'Subject', 'beacon-campaign-sender' ),
						'value' => isset( $row['subject'] ) ? $row['subject'] : '',
					),
					array(
						'name'  => __( 'Body', 'beacon-campaign-sender' ),
						'value' => isset( $row['body'] ) ? $row['body'] : '',
					),
					array(
						'name'  => __( 'Headers', 'beacon-campaign-sender' ),
						'value' => isset( $row['headers'] ) ? $row['headers'] : '',
					),
					array(
						'name'  => __( 'Attachments', 'beacon-campaign-sender' ),
						'value' => isset( $row['attachments'] ) ? $row['attachments'] : '',
					),
					array(
						'name'  => __( 'From name', 'beacon-campaign-sender' ),
						'value' => isset( $row['from_name'] ) ? $row['from_name'] : '',
					),
					array(
						'name'  => __( 'From email', 'beacon-campaign-sender' ),
						'value' => isset( $row['from_email'] ) ? $row['from_email'] : '',
					),
					array(
						'name'  => __( 'Status', 'beacon-campaign-sender' ),
						'value' => isset( $row['status'] ) ? $row['status'] : '',
					),
					array(
						'name'  => __( 'Error message', 'beacon-campaign-sender' ),
						'value' => isset( $row['error_message'] ) ? $row['error_message'] : '',
					),
					array(
						'name'  => __( 'Logged at', 'beacon-campaign-sender' ),
						'value' => isset( $row['created_at'] ) ? $row['created_at'] : '',
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase subscriber records for an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function erase_subscribers( $email_address, $page = 1 ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'bcsend_subscribers';
		$email         = sanitize_email( $email_address );
		$items_removed = false;

		if ( is_email( $email ) ) {
			$deleted       = $wpdb->delete( $table, array( 'email' => $email ), array( '%s' ) );
			$items_removed = ! empty( $deleted );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase email log records for an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function erase_email_logs( $email_address, $page = 1 ) {
		global $wpdb;

		$table         = $wpdb->prefix . Bcsend_Email_Log::TABLE;
		$email         = sanitize_email( $email_address );
		$items_removed = false;

		if ( is_email( $email ) ) {
			$like          = '%' . $wpdb->esc_like( $email ) . '%';
			$deleted       = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s",
					$like,
					$like,
					$like
				)
			);
			$items_removed = ! empty( $deleted );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
