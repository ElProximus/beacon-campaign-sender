<?php
/**
 * Environment checker.
 *
 * Singleton that caches dependency checks per-request so that multiple
 * callers can query environment status without repeated work.
 *
 * @package Bcsend_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Environment
 */
class Bcsend_Environment {

	/**
	 * Singleton instance.
	 *
	 * @var Bcsend_Environment|null
	 */
	private static $instance = null;

	/**
	 * Cached check results.
	 *
	 * @var array|null
	 */
	private $checks = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Bcsend_Environment
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Query a single environment flag.
	 *
	 * @param string $key One of the recognised check keys.
	 * @return bool
	 */
	public function is( $key ) {
		$this->ensure_checks();

		return isset( $this->checks[ $key ] ) ? (bool) $this->checks[ $key ]['result'] : false;
	}

	/**
	 * Get the full environment report with labels and results.
	 *
	 * @return array
	 */
	public function get_report() {
		$this->ensure_checks();

		return $this->checks;
	}

	/**
	 * Get an array of check keys that are currently false.
	 *
	 * @return array
	 */
	public function get_missing() {
		$this->ensure_checks();

		$missing = array();

		foreach ( $this->checks as $key => $check ) {
			if ( ! $check['result'] ) {
				$missing[ $key ] = $check['label'];
			}
		}

		return $missing;
	}

	/**
	 * Populate the checks array (once per request).
	 */
	private function ensure_checks() {
		if ( null !== $this->checks ) {
			return;
		}

		$this->checks = array(
			'woocommerce_active'       => array(
				'label'  => __( 'WooCommerce Active', 'beacon-campaign-sender' ),
				'result' => $this->check_woocommerce_active(),
			),
			'hpos_enabled'             => array(
				'label'  => __( 'HPOS Enabled', 'beacon-campaign-sender' ),
				'result' => $this->check_hpos_enabled(),
			),
			'buddyboss_present'        => array(
				'label'  => __( 'BuddyBoss Platform Present', 'beacon-campaign-sender' ),
				'result' => $this->check_buddyboss_present(),
			),
			'action_scheduler_present' => array(
				'label'  => __( 'Action Scheduler Present', 'beacon-campaign-sender' ),
				'result' => $this->check_action_scheduler_present(),
			),
			'required_tables_found'    => array(
				'label'  => __( 'All Database Tables Present', 'beacon-campaign-sender' ),
				'result' => $this->check_required_tables_found(),
			),
			'brevo_configured'         => array(
				'label'  => __( 'Brevo API Configured', 'beacon-campaign-sender' ),
				'result' => $this->check_brevo_configured(),
			),
			'sender_domain_verified'   => array(
				'label'  => __( 'Sender Domain Verified', 'beacon-campaign-sender' ),
				'result' => $this->check_sender_domain_verified(),
			),
			'anthropic_configured'     => array(
				'label'  => __( 'Anthropic API Configured', 'beacon-campaign-sender' ),
				'result' => $this->check_anthropic_configured(),
			),
			'openai_configured'        => array(
				'label'  => __( 'OpenAI API Configured', 'beacon-campaign-sender' ),
				'result' => $this->check_openai_configured(),
			),
			'push_configured'          => array(
				'label'  => __( 'Push Notifications Configured', 'beacon-campaign-sender' ),
				'result' => $this->check_push_configured(),
			),
		);
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function check_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Check if HPOS (High-Performance Order Storage) is enabled.
	 *
	 * @return bool
	 */
	private function check_hpos_enabled() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	/**
	 * Check if BuddyBoss Platform is present.
	 *
	 * @return bool
	 */
	private function check_buddyboss_present() {
		return function_exists( 'buddypress' ) || defined( 'BP_PLATFORM_VERSION' );
	}

	/**
	 * Check if Action Scheduler is available.
	 *
	 * @return bool
	 */
	private function check_action_scheduler_present() {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Check if all 4 required database tables exist.
	 *
	 * @return bool
	 */
	private function check_required_tables_found() {
		global $wpdb;

		$required = array(
			$wpdb->prefix . 'bcsend_campaigns',
			$wpdb->prefix . 'bcsend_segments',
			$wpdb->prefix . 'bcsend_templates',
			$wpdb->prefix . 'bcsend_logs',
			$wpdb->prefix . 'bcsend_email_log',
		);

		foreach ( $required as $table ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);

			if ( null === $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if Brevo API key is configured.
	 *
	 * @return bool
	 */
	private function check_brevo_configured() {
		$settings = get_option( 'bcsend_settings', array() );

		// Key may be encrypted or plaintext; either way, non-empty means configured.
		return ! empty( $settings['brevo_api_key'] );
	}

	/**
	 * Check if the configured sender domain is verified in Brevo.
	 *
	 * Missing status is treated as "not checked yet" and passes.
	 *
	 * @return bool
	 */
	private function check_sender_domain_verified() {
		$status = get_transient( 'bcsend_sender_domain_verified' );

		if ( false === $status || 'verified' === $status ) {
			return true;
		}

		return 0 !== strpos( (string) $status, 'unverified:' );
	}

	/**
	 * Check if Anthropic API key is configured.
	 *
	 * @return bool
	 */
	private function check_anthropic_configured() {
		$settings = get_option( 'bcsend_settings', array() );

		return ! empty( $settings['anthropic_api_key'] );
	}

	/**
	 * Check if OpenAI API key is configured.
	 *
	 * @return bool
	 */
	private function check_openai_configured() {
		$settings = get_option( 'bcsend_settings', array() );

		return ! empty( $settings['openai_api_key'] );
	}

	/**
	 * Check if push notifications are configured.
	 *
	 * Auto-detects BuddyBoss push or checks for manual Firebase config.
	 *
	 * @return bool
	 */
	private function check_push_configured() {
		// BuddyBoss auto-detect.
		if ( function_exists( 'bbapp_send_push_notification' ) || class_exists( 'BuddyBossApp\Push\Sender' ) ) {
			return true;
		}

		// Manual Firebase config.
		$settings = get_option( 'bcsend_settings', array() );

		if ( ! empty( $settings['firebase_service_account_json'] ) ) {
			// Decrypt if needed.
			$json = $settings['firebase_service_account_json'];
			if ( class_exists( 'Bcsend_Encryption' ) && Bcsend_Encryption::is_encrypted( $json ) ) {
				$json = Bcsend_Encryption::decrypt( $json );
			}

			$parsed = json_decode( $json, true );

			return is_array( $parsed ) && ! empty( $parsed['project_id'] ) && ! empty( $parsed['private_key'] );
		}

		return false;
	}
}
