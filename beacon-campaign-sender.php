<?php
/**
 * Plugin Name: Beacon Campaign Sender
 * Description: Email and push notification campaign manager with AI content generation, Brevo integration, and Firebase push delivery.
 * Version: 1.0.3
 * Author: Joe Campbell
 * Author URI: https://aisystemadmin.com/joe-campbell/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beacon-campaign-sender
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Bcsend_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'BCSEND_VERSION', '1.0.3' );
define( 'BCSEND_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCSEND_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BCSEND_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-encryption.php';
require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-subscriber-ingest.php';
require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-activator.php';
require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-deactivator.php';

register_activation_hook( __FILE__, array( 'Bcsend_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bcsend_Deactivator', 'deactivate' ) );

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Sanitise email HTML while preserving <style> tags.
 *
 * wp_kses_post() strips <style> (not in its allow-list) but keeps
 * the inner CSS as plain text, which corrupts saved drafts.
 * This wrapper adds <style> to the allow-list so full email
 * templates survive the round-trip.
 *
 * @param string $html Raw email HTML.
 * @return string Sanitised HTML with <style> blocks intact.
 */
function bcsend_kses_email( $html ) {
	$allowed          = wp_kses_allowed_html( 'post' );
	$allowed['style'] = array( 'type' => true );
	$allowed['link']  = array(
		'rel'   => true,
		'type'  => true,
		'href'  => true,
		'media' => true,
	);
	return wp_kses( $html, $allowed );
}

/**
 * Sanitize nested JSON-style campaign transport data.
 *
 * @param mixed  $value Raw decoded JSON value.
 * @param string $mode  Sanitization mode.
 * @param string $key   Current nested key.
 * @return mixed
 */
function bcsend_sanitize_json_value( $value, $mode = 'text', $key = '' ) {
	if ( is_array( $value ) ) {
		$sanitized = array();
		foreach ( $value as $item_key => $item_value ) {
			$clean_key               = is_int( $item_key ) ? $item_key : sanitize_key( (string) $item_key );
			$sanitized[ $clean_key ] = bcsend_sanitize_json_value( $item_value, $mode, (string) $clean_key );
		}
		return $sanitized;
	}

	if ( is_bool( $value ) || null === $value ) {
		return $value;
	}

	if ( is_int( $value ) || is_float( $value ) ) {
		return $value;
	}

	$value = (string) $value;

	if ( 'url' === $mode || in_array( $key, array( 'url', 'thumb', 'link_url', 'image_url', 'permalink' ), true ) ) {
		return esc_url_raw( $value );
	}

	if ( 'key' === $mode || in_array( $key, array( 'type', 'platform', 'link_mode', 'target_type' ), true ) ) {
		return sanitize_key( $value );
	}

	if ( in_array( $key, array( 'id', 'user_id', 'post_id', 'product_id', 'campaign_id' ), true ) ) {
		return absint( $value );
	}

	if ( 'textarea' === $mode || in_array( $key, array( 'content', 'message', 'excerpt', 'description' ), true ) ) {
		return sanitize_textarea_field( $value );
	}

	return sanitize_text_field( $value );
}

/**
 * Sanitize a JSON string and return a JSON string suitable for storage.
 *
 * @param mixed  $raw     Raw JSON string.
 * @param string $default Default JSON string when invalid.
 * @param string $mode    Sanitization mode.
 * @return string
 */
function bcsend_sanitize_json_string( $raw, $default = '{}', $mode = 'text' ) {
	if ( null === $raw || '' === $raw ) {
		return $default;
	}

	$decoded = json_decode( (string) $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
		return $default;
	}

	return wp_json_encode( bcsend_sanitize_json_value( $decoded, $mode ) );
}

/**
 * Resolve the campaign Reply-To using campaign override, settings default, then sender fallback.
 *
 * Empty return values are intentional: Brevo campaign creation falls back to
 * sender email, and transactional preview emails omit replyTo to let replies
 * use the From address.
 *
 * @param string $campaign_reply_to Optional campaign-level Reply-To.
 * @return string Valid Reply-To email or empty string.
 */
function bcsend_get_campaign_reply_to( $campaign_reply_to = '' ) {
	$campaign_reply_to = sanitize_email( (string) $campaign_reply_to );
	if ( ! empty( $campaign_reply_to ) && is_email( $campaign_reply_to ) ) {
		return $campaign_reply_to;
	}

	$settings = class_exists( 'Bcsend_Settings' ) ? Bcsend_Settings::get_settings() : array();
	$reply_to = isset( $settings['reply_to_email'] ) ? sanitize_email( $settings['reply_to_email'] ) : '';

	return ! empty( $reply_to ) && is_email( $reply_to ) ? $reply_to : '';
}

/**
 * Main Beacon Campaign Sender Class
 *
 * @since 1.0.0
 */
final class Bcsend_Plugin {

	/**
	 * Single instance.
	 *
	 * @var Bcsend_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Bcsend_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load required files.
	 */
	private function load_dependencies() {
		// Core includes.
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-activator.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-deactivator.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-encryption.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-environment.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-logger.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-brevo-api.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-subscriber-ingest.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-subscribe-endpoint.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-privacy.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-zernio-api.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-social-workflow.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-ajax-campaigns.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-email-log.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-anthropic-api.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-openai-api.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-ai-service.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-push-service.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-segment-engine.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-campaign-sender.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-scheduler.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-smtp.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-push-manager.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-social-sender.php';
		require_once BCSEND_PLUGIN_DIR . 'includes/class-bcsend-abilities-bridge.php';
		require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-settings.php';
		require_once BCSEND_PLUGIN_DIR . 'abilities/_loader.php';

		// Admin includes.
		if ( is_admin() ) {
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-admin.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-dashboard.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-composer.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-queue.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-audiences.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-templates.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-analytics.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-logs.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-subscribers.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-email-log.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-test.php';
			require_once BCSEND_PLUGIN_DIR . 'admin/class-bcsend-push-admin.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->register_privacy_hooks();
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		Bcsend_Subscribe_Endpoint::init();
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Initialize admin.
		if ( is_admin() ) {
			new Bcsend_Admin();
			add_action( 'admin_notices', array( $this, 'render_zernio_webhook_notice' ) );
		}

		// Initialize scheduler action handlers.
		Bcsend_Scheduler::init();

		$campaign_ajax = new Bcsend_Ajax_Campaigns();
		$campaign_ajax->register();

		// Initialize SMTP routing (must run outside is_admin — wp_mail fires everywhere).
		$smtp = new Bcsend_Smtp();
		$smtp->init();

		// Abilities are loaded via abilities/_loader.php in load_dependencies().

		// Schedule recurring jobs on init.
		add_action( 'init', array( $this, 'maybe_schedule_recurring_jobs' ) );

		// Run settings migration on admin_init (handles upgrades without reactivation).
		add_action( 'admin_init', array( $this, 'maybe_migrate_settings' ) );

		// --- AJAX: Connection testing ---
		add_action( 'wp_ajax_bcsend_test_brevo', array( $this, 'ajax_test_brevo' ) );
		add_action( 'wp_ajax_bcsend_test_zernio', array( $this, 'ajax_test_zernio' ) );
		add_action( 'wp_ajax_bcsend_test_anthropic', array( $this, 'ajax_test_anthropic' ) );
		add_action( 'wp_ajax_bcsend_test_openai', array( $this, 'ajax_test_openai' ) );
		add_action( 'wp_ajax_bcsend_test_firebase', array( $this, 'ajax_test_firebase' ) );

		// --- AJAX: Send test messages ---
		add_action( 'wp_ajax_bcsend_send_test_email', array( $this, 'ajax_send_test_email' ) );
		add_action( 'wp_ajax_bcsend_send_test_email_default', array( $this, 'ajax_send_test_email_default' ) );
		add_action( 'wp_ajax_bcsend_send_test_push', array( $this, 'ajax_send_test_push' ) );

		// --- AJAX: AI generation ---
		add_action( 'wp_ajax_bcsend_generate_sample', array( $this, 'ajax_generate_sample' ) );

		// --- AJAX: Environment / Logs ---
		add_action( 'wp_ajax_bcsend_verify_tables', array( $this, 'ajax_verify_tables' ) );
		add_action( 'wp_ajax_bcsend_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_bcsend_clear_old_logs', array( $this, 'ajax_clear_old_logs' ) );
		add_action( 'wp_ajax_bcsend_resend_email', array( $this, 'ajax_resend_email' ) );
		add_action( 'wp_ajax_bcsend_verify_email_log', array( $this, 'ajax_verify_email_log' ) );
		add_action( 'wp_ajax_bcsend_test_resend', array( $this, 'ajax_test_resend' ) );

		// --- AJAX: Brevo lists & segments ---
		add_action( 'wp_ajax_bcsend_get_brevo_lists', array( $this, 'ajax_get_brevo_lists' ) );
		add_action( 'wp_ajax_bcsend_get_segments', array( $this, 'ajax_get_segments' ) );
		add_action( 'wp_ajax_bcsend_create_segment', array( $this, 'ajax_create_segment' ) );
		add_action( 'wp_ajax_bcsend_update_segment', array( $this, 'ajax_update_segment' ) );
		add_action( 'wp_ajax_bcsend_delete_segment', array( $this, 'ajax_delete_segment' ) );
		add_action( 'wp_ajax_bcsend_sync_segment', array( $this, 'ajax_sync_segment' ) );
		add_action( 'wp_ajax_bcsend_sync_all_segments', array( $this, 'ajax_sync_all_segments' ) );
		add_action( 'wp_ajax_bcsend_get_segment_contacts', array( $this, 'ajax_get_segment_contacts' ) );
		add_action( 'wp_ajax_bcsend_get_audience_options', array( $this, 'ajax_get_audience_options' ) );

		// --- AJAX: Products, categories & AI campaign generation ---
		add_action( 'wp_ajax_bcsend_search_products', array( $this, 'ajax_search_products' ) );
		add_action( 'wp_ajax_bcsend_search_categories', array( $this, 'ajax_search_categories' ) );
		// Campaign generation/workflow/CRUD AJAX is registered by Bcsend_Ajax_Campaigns.

		// --- AJAX: Templates ---
		add_action( 'wp_ajax_bcsend_get_templates', array( $this, 'ajax_get_templates' ) );
		add_action( 'wp_ajax_bcsend_save_template', array( $this, 'ajax_save_template' ) );
		add_action( 'wp_ajax_bcsend_delete_template', array( $this, 'ajax_delete_template' ) );
		add_action( 'wp_ajax_bcsend_duplicate_template', array( $this, 'ajax_duplicate_template' ) );

		// --- AJAX: Dashboard & Analytics ---
		add_action( 'wp_ajax_bcsend_get_dashboard_data', array( $this, 'ajax_get_dashboard_data' ) );
		add_action( 'wp_ajax_bcsend_get_analytics_data', array( $this, 'ajax_get_analytics_data' ) );

		// --- AJAX: Settings helpers ---
		add_action( 'wp_ajax_bcsend_get_default_template', array( $this, 'ajax_get_default_template' ) );
		add_action( 'wp_ajax_bcsend_zernio_fetch_profiles', array( $this, 'ajax_zernio_fetch_profiles' ) );
		add_action( 'wp_ajax_bcsend_zernio_set_profile', array( $this, 'ajax_zernio_set_profile' ) );
		add_action( 'wp_ajax_bcsend_zernio_sync_accounts', array( $this, 'ajax_zernio_sync_accounts' ) );
		add_action( 'wp_ajax_bcsend_zernio_sync_webhook', array( $this, 'ajax_zernio_sync_webhook' ) );
		add_action( 'wp_ajax_bcsend_zernio_clear_webhook_diagnostics', array( $this, 'ajax_zernio_clear_webhook_diagnostics' ) );
		add_action( 'wp_ajax_bcsend_zernio_test_webhook_diagnostics', array( $this, 'ajax_zernio_test_webhook_diagnostics' ) );

		// --- AJAX: Standalone push notifications ---
		add_action( 'wp_ajax_bcsend_push_submit', array( $this, 'ajax_push_submit' ) );
		add_action( 'wp_ajax_bcsend_push_search_users', array( $this, 'ajax_push_search_users' ) );
		add_action( 'wp_ajax_bcsend_push_delete', array( $this, 'ajax_push_delete' ) );
		add_action( 'wp_ajax_bcsend_push_cancel', array( $this, 'ajax_push_cancel' ) );

		// --- AJAX: Content Library (posts, snippets) ---
		add_action( 'wp_ajax_bcsend_search_posts', array( $this, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_bcsend_get_snippets', array( $this, 'ajax_get_snippets' ) );
		add_action( 'wp_ajax_bcsend_save_snippet', array( $this, 'ajax_save_snippet' ) );
		add_action( 'wp_ajax_bcsend_delete_snippet', array( $this, 'ajax_delete_snippet' ) );
		add_action( 'wp_ajax_bcsend_test_content_library', array( $this, 'ajax_test_content_library' ) );
	}

	/**
	 * Load textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'beacon-campaign-sender', false, dirname( BCSEND_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Register privacy exporters, erasers, and policy text.
	 *
	 * @return void
	 */
	public function register_privacy_hooks() {
		if ( class_exists( 'Bcsend_Privacy' ) ) {
			Bcsend_Privacy::init();
		}
	}

	/**
	 * Register REST endpoints used by Beacon Campaign Sender.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'beacon-campaign-sender/v1',
			'/zernio/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_zernio_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		Bcsend_Subscribe_Endpoint::register_rest_routes();
	}

	/**
	 * Prompt admins to sync Zernio after the REST webhook URL changes.
	 *
	 * @return void
	 */
	public function render_zernio_webhook_notice() {
		if ( ! current_user_can( 'manage_bcsend' ) ) {
			return;
		}

		$settings = Bcsend_Settings::get_settings();
		if ( empty( $settings['zernio_api_key'] ) || empty( $settings['zernio_webhook_secret'] ) ) {
			return;
		}

		$current_url = rest_url( 'beacon-campaign-sender/v1/zernio/webhook' );
		$synced_url  = get_option( 'bcsend_zernio_webhook_url', '' );
		if ( $synced_url === $current_url ) {
			return;
		}

		$settings_url = add_query_arg( array( 'page' => 'bcsend-settings' ), admin_url( 'admin.php' ) );
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Beacon Campaign Sender:', 'beacon-campaign-sender' ),
			esc_html__( 'Your Zernio webhook URL changed during the rename. Re-sync the webhook so social status updates keep arriving.', 'beacon-campaign-sender' ),
			esc_url( $settings_url . '#bcsend-zernio-sync-webhook' ),
			esc_html__( 'Open webhook settings', 'beacon-campaign-sender' )
		);
	}

	/**
	 * Schedule recurring jobs if not already scheduled.
	 */
	public function maybe_schedule_recurring_jobs() {
		Bcsend_Scheduler::schedule_recurring_jobs();
	}

	/**
	 * Register custom cron intervals for fallback scheduling.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			$schedules['five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every Five Minutes', 'beacon-campaign-sender' ),
			);
		}

		return $schedules;
	}

	/**
	 * Migrate legacy settings keys on admin_init (handles upgrades without reactivation).
	 *
	 * @since 1.1.0
	 */
	public function maybe_migrate_settings() {
		Bcsend_Activator::maybe_upgrade_schema_public();
		self::sanitize_zernio_webhook_diagnostics();

		// Self-heal capabilities if they're missing (e.g. plugin uploaded while active).
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( 'manage_bcsend' ) ) {
			$admin_role->add_cap( 'manage_bcsend' );
			$admin_role->add_cap( 'edit_bcsend_campaigns' );
			$admin_role->add_cap( 'view_bcsend_logs' );
		}

		$settings = get_option( 'bcsend_settings', array() );
		if ( isset( $settings['push_source'] ) || isset( $settings['push_method'] ) ) {
			Bcsend_Activator::migrate_settings_public();
			return;
		}

		Bcsend_Activator::migrate_settings_public();
	}

	// =========================================================================
	// Connection Testing
	// =========================================================================

	/**
	 * AJAX: Test Brevo API connection.
	 */
	public function ajax_test_brevo() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			$settings = get_option( 'bcsend_settings', array() );
			$settings = Bcsend_Encryption::decrypt_settings( $settings );
			$api_key  = isset( $settings['brevo_api_key'] ) ? $settings['brevo_api_key'] : '';
		}

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'No Brevo API key provided.', 'beacon-campaign-sender' ) ) );
		}

		$response = wp_remote_get(
			'https://api.brevo.com/v3/account',
			array(
				'headers' => array(
					'api-key' => $api_key,
					'accept'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			Bcsend_Logger::log( 'api', 'Brevo connection test failed: ' . $response->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = isset( $body['message'] ) ? $body['message'] : __( 'Unknown error.', 'beacon-campaign-sender' );
			Bcsend_Logger::log( 'api', 'Brevo connection test failed: ' . $error_msg, wp_json_encode( $body ), 'error' );
			wp_send_json_error( array( 'message' => $error_msg ) );
		}

		Bcsend_Logger::log( 'api', 'Brevo connection test successful.' );
		wp_send_json_success(
			array(
				'message' => __( 'Connected successfully.', 'beacon-campaign-sender' ),
				'email'   => isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '',
				'plan'    => isset( $body['plan'] ) ? array_map( 'sanitize_text_field', $body['plan'] ) : array(),
			)
		);
	}

	/**
	 * AJAX: Test Zernio API connection.
	 */
	public function ajax_test_zernio() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$client  = new Bcsend_Zernio_API( ! empty( $api_key ) ? $api_key : null );

		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'No Zernio API key provided.', 'beacon-campaign-sender' ) ) );
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			Bcsend_Logger::log( 'api', 'Zernio connection test failed: ' . $result->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Bcsend_Logger::log( 'api', 'Zernio connection test successful.' );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Test Anthropic API connection.
	 */
	public function ajax_test_anthropic() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$client = new Bcsend_Anthropic_API();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'No Anthropic API key provided.', 'beacon-campaign-sender' ) ) );
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			Bcsend_Logger::log( 'api', 'Anthropic connection test failed: ' . $result->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Bcsend_Logger::log( 'api', 'Anthropic connection test successful.' );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Test OpenAI API connection.
	 */
	public function ajax_test_openai() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$client = new Bcsend_OpenAI_API();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'No OpenAI API key provided.', 'beacon-campaign-sender' ) ) );
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			Bcsend_Logger::log( 'api', 'OpenAI connection test failed: ' . $result->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Bcsend_Logger::log( 'api', 'OpenAI connection test successful.' );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Test Firebase push connection.
	 */
	public function ajax_test_firebase() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$settings = get_option( 'bcsend_settings', array() );
		$settings = Bcsend_Encryption::decrypt_settings( $settings );

		$push_method = isset( $settings['push_mode'] ) ? $settings['push_mode'] : 'auto';

		if ( 'auto' === $push_method ) {
			// Check if BuddyBoss push functions are available.
			if ( function_exists( 'bbapp_send_push_notification' ) || class_exists( 'BuddyBossApp\Push\Sender' ) ) {
				Bcsend_Logger::log( 'api', 'Firebase test: BuddyBoss push integration detected.' );
				wp_send_json_success(
					array(
						'message' => __( 'BuddyBoss push integration detected and available.', 'beacon-campaign-sender' ),
						'method'  => 'buddyboss',
					)
				);
			} else {
				wp_send_json_error( array( 'message' => __( 'BuddyBoss push integration not detected. Is the BuddyBoss App plugin active?', 'beacon-campaign-sender' ) ) );
			}
		} else {
			// Manual Firebase — validate service account JSON.
			$service_json = isset( $settings['firebase_service_account_json'] ) ? $settings['firebase_service_account_json'] : '';

			if ( empty( $service_json ) ) {
				wp_send_json_error( array( 'message' => __( 'No Firebase service account JSON configured.', 'beacon-campaign-sender' ) ) );
			}

			$parsed = json_decode( $service_json, true );

			if ( ! is_array( $parsed ) || empty( $parsed['project_id'] ) || empty( $parsed['private_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid Firebase service account JSON. Ensure it contains project_id and private_key.', 'beacon-campaign-sender' ) ) );
			}

			Bcsend_Logger::log( 'api', 'Firebase service account JSON validated for project: ' . sanitize_text_field( $parsed['project_id'] ) );
			wp_send_json_success(
				array(
					'message'    => __( 'Firebase service account JSON is valid.', 'beacon-campaign-sender' ),
					'method'     => 'firebase',
					'project_id' => sanitize_text_field( $parsed['project_id'] ),
				)
			);
		}
	}

	// =========================================================================
	// Test Messages
	// =========================================================================

	/**
	 * AJAX: Send a test email through the full Beacon Campaign Sender SMTP stack.
	 *
	 * Routes through wp_mail() while requiring Beacon Campaign Sender SMTP routing
	 * to be active, so the test exercises retry logic, fallback handling,
	 * failure alerting, and email log capture.
	 */
	public function ajax_send_test_email() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$args = $this->get_test_email_request_data();

		if ( is_wp_error( $args ) ) {
			wp_send_json_error( array( 'message' => $args->get_error_message() ) );
		}

		$settings = get_option( 'bcsend_settings', array() );
		if ( empty( $settings['smtp_routing_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Beacon Campaign Sender SMTP routing is disabled. Use the WordPress Default Mail test button instead.', 'beacon-campaign-sender' ) ) );
		}

		if ( empty( $settings['brevo_api_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Brevo API key not configured. Beacon Campaign Sender SMTP cannot be tested until Brevo is configured.', 'beacon-campaign-sender' ) ) );
		}

		$before_log_id = $this->get_latest_email_log_id();

		$sent = wp_mail( $args['to_email'], $args['subject'], $args['html'], $args['headers'] );

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Test email failed. Check Email Log and Logs for details.', 'beacon-campaign-sender' ) ) );
		}

		$log_entry = Bcsend_Email_Log::find_recent_match( $args['to_email'], $args['subject'], $before_log_id );
		if ( ! $log_entry ) {
			wp_send_json_error( array( 'message' => __( 'Beacon Campaign Sender SMTP test email was submitted, but no matching email log row was captured.', 'beacon-campaign-sender' ) ) );
		}

		$message_id = ! empty( $log_entry->brevo_message_id ) ? $log_entry->brevo_message_id : '';

		wp_send_json_success(
			array(
				'message'       => sprintf(
					/* translators: %s: email address */
					__( 'Beacon Campaign Sender SMTP test email sent to %s.', 'beacon-campaign-sender' ),
					$args['to_email']
				),
				'message_id'    => $message_id,
				'email_log_id'  => (int) $log_entry->id,
				'delivery_path' => 'bcsend_smtp',
			)
		);
	}

	/**
	 * AJAX: Send a test email through WordPress default mail.
	 *
	 * Uses a one-shot bypass flag so the request can exercise the
	 * default wp_mail()/PHPMailer path even when Beacon Campaign Sender SMTP
	 * routing is enabled.
	 */
	public function ajax_send_test_email_default() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$args = $this->get_test_email_request_data();

		if ( is_wp_error( $args ) ) {
			wp_send_json_error( array( 'message' => $args->get_error_message() ) );
		}

		Bcsend_Smtp::$bypass_once = true;

		try {
			$sent = wp_mail( $args['to_email'], $args['subject'], $args['html'], $args['headers'] );
		} finally {
			Bcsend_Smtp::$bypass_once = false;
		}

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'WordPress default mail test failed.', 'beacon-campaign-sender' ) ) );
		}

		wp_send_json_success(
			array(
				'message'       => sprintf(
					/* translators: %s: email address */
					__( 'WordPress default mail test email sent to %s.', 'beacon-campaign-sender' ),
					$args['to_email']
				),
				'delivery_path' => 'wordpress_default_mail',
			)
		);
	}

	/**
	 * Normalize request data for the System Tests email send buttons.
	 *
	 * @return array|WP_Error
	 */
	private function get_test_email_request_data() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		$to_email = isset( $_POST['to_email'] ) ? sanitize_email( wp_unslash( $_POST['to_email'] ) ) : '';
		$subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : __( 'Beacon Campaign Sender Test Email', 'beacon-campaign-sender' );
		$html     = isset( $_POST['html_content'] ) ? bcsend_kses_email( wp_unslash( $_POST['html_content'] ) ) : '';

		if ( empty( $to_email ) || ! is_email( $to_email ) ) {
			return new WP_Error( 'invalid_email', __( 'Valid email address required.', 'beacon-campaign-sender' ) );
		}

		if ( empty( $html ) ) {
			$html = '<html><body><h1>Test Email</h1><p>This is a test email from Beacon Campaign Sender.</p></body></html>';
		}

		return array(
			'to_email' => $to_email,
			'subject'  => $subject,
			'html'     => $html,
			'headers'  => array( 'Content-Type: text/html; charset=UTF-8' ),
		);
	}

	/**
	 * Get the current highest email log ID.
	 *
	 * @return int
	 */
	private function get_latest_email_log_id() {
		global $wpdb;

		$table = $wpdb->prefix . Bcsend_Email_Log::TABLE;
		$max   = $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );

		return $max ? (int) $max : 0;
	}

	/**
	 * AJAX: Send a test push notification.
	 */
	public function ajax_send_test_push() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : __( 'Beacon Campaign Sender Test', 'beacon-campaign-sender' );
		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : __( 'This is a test push notification.', 'beacon-campaign-sender' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();

		if ( empty( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No target user specified.', 'beacon-campaign-sender' ) ) );
		}

		$settings    = get_option( 'bcsend_settings', array() );
		$push_method = isset( $settings['push_mode'] ) ? $settings['push_mode'] : 'auto';

		if ( 'auto' === $push_method ) {
			if ( ! function_exists( 'bbapp_send_push_notification' ) ) {
				wp_send_json_error( array( 'message' => __( 'BuddyBoss push function not available.', 'beacon-campaign-sender' ) ) );
			}

			$result = bbapp_send_push_notification(
				array(
					'user_id' => $user_id,
					'title'   => $title,
					'message' => $message,
				)
			);

			if ( is_wp_error( $result ) ) {
				Bcsend_Logger::log( 'push', 'Test push failed (BuddyBoss): ' . $result->get_error_message(), '', 'error' );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			Bcsend_Logger::log( 'push', 'Test push sent to user ' . $user_id . ' via BuddyBoss.' );
			wp_send_json_success( array( 'message' => __( 'Test push notification sent via BuddyBoss.', 'beacon-campaign-sender' ) ) );
		} else {
			// Manual Firebase — placeholder for service class delegation.
			Bcsend_Logger::log( 'push', 'Test push requested via Firebase for user ' . $user_id . '. Firebase direct send not yet implemented.', '', 'info' );
			wp_send_json_error( array( 'message' => __( 'Direct Firebase push send will be handled by the push service class.', 'beacon-campaign-sender' ) ) );
		}
	}

	// =========================================================================
	// AI Generation
	// =========================================================================

	/**
	 * AJAX: Generate sample content via Anthropic.
	 */
	public function ajax_generate_sample() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'email';

		if ( empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Prompt is required.', 'beacon-campaign-sender' ) ) );
		}

		$generated = Bcsend_AI_Service::generate_sample( $prompt, $type );

		if ( is_wp_error( $generated ) ) {
			Bcsend_Logger::log( 'ai', 'Sample generation failed: ' . $generated->get_error_message(), '', 'error' );
			wp_send_json_error( array( 'message' => $generated->get_error_message() ) );
		}

		Bcsend_Logger::log( 'ai', 'Sample content generated successfully.', '', 'success' );
		wp_send_json_success(
			array(
				'content'  => wp_json_encode( $generated['content'] ),
				'provider' => $generated['provider'],
			)
		);
	}

	// =========================================================================
	// Environment & Logs
	// =========================================================================

	/**
	 * AJAX: Verify database tables exist.
	 */
	public function ajax_verify_tables() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$env    = Bcsend_Environment::get_instance();
		$report = $env->get_report();

		wp_send_json_success( array( 'report' => $report ) );
	}

	/**
	 * AJAX: Get logs.
	 */
	public function ajax_get_logs() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'view_bcsend_logs' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( $_POST['per_page'] ) ) ) : 50;

		$results = Bcsend_Logger::get_logs(
			array(
				'type'     => $type,
				'status'   => $status,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: Clear old logs.
	 */
	public function ajax_clear_old_logs() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$days    = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$deleted = Bcsend_Logger::delete_old_logs( $days );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of deleted log entries */
					__( '%d log entries deleted.', 'beacon-campaign-sender' ),
					$deleted
				),
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * AJAX: Resend a logged email through the normal wp_mail flow.
	 */
	public function ajax_resend_email() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$email_id = isset( $_POST['email_id'] ) ? absint( $_POST['email_id'] ) : 0;
		if ( $email_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Email log ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$email = Bcsend_Email_Log::get( $email_id );
		if ( ! $email ) {
			wp_send_json_error( array( 'message' => __( 'Email log entry not found.', 'beacon-campaign-sender' ) ) );
		}

		if ( ! Bcsend_Email_Log::supports_resend( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'This email log entry was stored in minimal privacy mode and cannot be resent. Switch Email Log Privacy Mode to full for future resend support.', 'beacon-campaign-sender' ) ) );
		}

		$to          = isset( $email->to_email ) ? $email->to_email : '';
		$subject     = isset( $email->subject ) ? $email->subject : '';
		$message     = isset( $email->body ) ? $email->body : '';
		$headers     = $this->build_resend_headers( $email );
		$attachments = $this->filter_resend_attachments( $email );

		Bcsend_Smtp::$resend_from_id = $email_id;
		try {
			$sent = wp_mail( $to, $subject, $message, $headers, $attachments );
		} finally {
			Bcsend_Smtp::$resend_from_id = null;
		}

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Email resend failed. Check the email log for details.', 'beacon-campaign-sender' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Email resend submitted.', 'beacon-campaign-sender' ),
			)
		);
	}

	/**
	 * Rebuild wp_mail headers from a stored email log entry.
	 *
	 * @param object $email Email log row.
	 * @return array
	 */
	private function build_resend_headers( $email ) {
		$headers = array();

		if ( ! empty( $email->is_html ) ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		if ( ! empty( $email->from_email ) ) {
			$from_header = ! empty( $email->from_name )
				? sprintf( 'From: %s <%s>', $email->from_name, $email->from_email )
				: sprintf( 'From: %s', $email->from_email );
			$headers[]   = $from_header;
		}

		foreach ( array(
			'cc'  => 'Cc',
			'bcc' => 'Bcc',
		) as $field => $label ) {
			if ( empty( $email->{$field} ) ) {
				continue;
			}

			$decoded = json_decode( $email->{$field}, true );
			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				continue;
			}

			$parts = array();
			foreach ( $decoded as $recipient ) {
				if ( empty( $recipient['email'] ) ) {
					continue;
				}

				$parts[] = ! empty( $recipient['name'] )
					? sprintf( '%s <%s>', $recipient['name'], $recipient['email'] )
					: $recipient['email'];
			}

			if ( ! empty( $parts ) ) {
				$headers[] = $label . ': ' . implode( ', ', $parts );
			}
		}

		return $headers;
	}

	/**
	 * Filter stored attachment paths to readable files for resend.
	 *
	 * @param object $email Email log row.
	 * @return array
	 */
	private function filter_resend_attachments( $email ) {
		if ( empty( $email->attachments ) ) {
			return array();
		}

		$attachments = json_decode( $email->attachments, true );
		if ( ! is_array( $attachments ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$attachments,
				static function ( $path ) {
					return is_string( $path ) && '' !== trim( $path ) && is_readable( $path );
				}
			)
		);
	}

	/**
	 * AJAX: Verify the email log captured data from the most recent test email.
	 *
	 * Checks that the newest email log row exists and has the expected
	 * status and subject. Used by the System Tests page.
	 */
	public function ajax_verify_email_log() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$recent = Bcsend_Email_Log::get_emails( 'all', '', 1, 5 );

		if ( empty( $recent['items'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No email log entries found. Send a test email first.', 'beacon-campaign-sender' ) ) );
		}

		$checks = array();

		foreach ( $recent['items'] as $entry ) {
			$checks[] = array(
				'id'               => (int) $entry->id,
				'subject'          => $entry->subject,
				'to'               => $entry->to_email,
				'status'           => $entry->status,
				'has_body'         => ! empty( $entry->body ),
				'has_from'         => ! empty( $entry->from_email ),
				'brevo_message_id' => ! empty( $entry->brevo_message_id ) ? $entry->brevo_message_id : null,
				'resent_from'      => ! empty( $entry->resent_from_log_id ) ? (int) $entry->resent_from_log_id : null,
				'created_at'       => $entry->created_at,
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: total email log entries */
					__( '%d email log entries found.', 'beacon-campaign-sender' ),
					$recent['total']
				),
				'total'   => $recent['total'],
				'recent'  => $checks,
			)
		);
	}

	/**
	 * AJAX: Resend the most recent email log entry and verify provenance.
	 *
	 * Finds the latest sent email, resends it via wp_mail(), then checks
	 * that a new row was created with resent_from_log_id pointing back.
	 */
	public function ajax_test_resend() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		// Find the most recent sent email to use as the resend source.
		$recent = Bcsend_Email_Log::get_emails( 'sent', '', 1, 1 );

		if ( empty( $recent['items'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No sent emails found to resend. Send a test email first.', 'beacon-campaign-sender' ) ) );
		}

		$source    = $recent['items'][0];
		$source_id = (int) $source->id;

		if ( ! Bcsend_Email_Log::supports_resend( $source ) ) {
			wp_send_json_error( array( 'message' => __( 'The latest email log entry was stored in minimal privacy mode and cannot be resent. Switch Email Log Privacy Mode to full and send a new test email first.', 'beacon-campaign-sender' ) ) );
		}

		// Resend via the same path as the UI resend button.
		$headers     = $this->build_resend_headers( $source );
		$attachments = $this->filter_resend_attachments( $source );

		Bcsend_Smtp::$resend_from_id = $source_id;
		try {
			$sent = wp_mail(
				$source->to_email,
				$source->subject,
				$source->body,
				$headers,
				$attachments
			);
		} finally {
			Bcsend_Smtp::$resend_from_id = null;
		}

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Resend failed. Check Email Log for details.', 'beacon-campaign-sender' ) ) );
		}

		// Verify the new log entry has resent_from_log_id set.
		$new_entries = Bcsend_Email_Log::get_emails( 'all', '', 1, 1 );
		$new_entry   = ! empty( $new_entries['items'] ) ? $new_entries['items'][0] : null;

		$provenance_ok = $new_entry
			&& (int) $new_entry->id !== $source_id
			&& (int) $new_entry->resent_from_log_id === $source_id;

		wp_send_json_success(
			array(
				'message'       => $provenance_ok
					? sprintf(
						/* translators: 1: new log ID, 2: original log ID */
						__( 'Resend succeeded. New log entry #%1$d with provenance from #%2$d.', 'beacon-campaign-sender' ),
						(int) $new_entry->id,
						$source_id
					)
					: __( 'Resend sent but provenance verification could not be confirmed.', 'beacon-campaign-sender' ),
				'provenance_ok' => $provenance_ok,
				'source_id'     => $source_id,
				'new_id'        => $new_entry ? (int) $new_entry->id : 0,
			)
		);
	}

	// =========================================================================
	// Brevo Lists & Segments
	// =========================================================================

	/**
	 * AJAX: Get Brevo contact lists.
	 */
	public function ajax_get_brevo_lists() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$settings = get_option( 'bcsend_settings', array() );
		$settings = Bcsend_Encryption::decrypt_settings( $settings );
		$api_key  = isset( $settings['brevo_api_key'] ) ? $settings['brevo_api_key'] : '';

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Brevo API key not configured.', 'beacon-campaign-sender' ) ) );
		}

		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$response = wp_remote_get(
			add_query_arg(
				array(
					'limit'  => $limit,
					'offset' => $offset,
				),
				'https://api.brevo.com/v3/contacts/lists'
			),
			array(
				'headers' => array(
					'api-key' => $api_key,
					'accept'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = isset( $body['message'] ) ? $body['message'] : __( 'Failed to fetch lists.', 'beacon-campaign-sender' );
			wp_send_json_error( array( 'message' => $error_msg ) );
		}

		wp_send_json_success(
			array(
				'lists' => isset( $body['lists'] ) ? $body['lists'] : array(),
				'count' => isset( $body['count'] ) ? intval( $body['count'] ) : 0,
			)
		);
	}

	/**
	 * AJAX: Create a segment.
	 */
	public function ajax_create_segment() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$result = bcsend_ability_create_segment(
			array(
				'name'          => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'type'          => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
				'brevo_list_id' => isset( $_POST['brevo_list_id'] ) ? absint( $_POST['brevo_list_id'] ) : 0,
				'query_type'    => isset( $_POST['query_type'] ) ? sanitize_text_field( wp_unslash( $_POST['query_type'] ) ) : '',
				'query_params'  => isset( $_POST['query_params'] ) ? sanitize_text_field( wp_unslash( $_POST['query_params'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Update a segment.
	 */
	public function ajax_update_segment() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_segments';

		$id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$brevo_list   = isset( $_POST['brevo_list_id'] ) ? absint( $_POST['brevo_list_id'] ) : 0;
		$query_type   = isset( $_POST['query_type'] ) ? sanitize_text_field( wp_unslash( $_POST['query_type'] ) ) : '';
		$query_params = isset( $_POST['query_params'] ) ? sanitize_text_field( wp_unslash( $_POST['query_params'] ) ) : '';

		if ( empty( $id ) || empty( $name ) || empty( $type ) ) {
			wp_send_json_error( array( 'message' => __( 'ID, name, and type are required.', 'beacon-campaign-sender' ) ) );
		}

		$allowed_types = array( 'brevo_list', 'wc_customers', 'buddyboss_members', 'manual' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid segment type.', 'beacon-campaign-sender' ) ) );
		}

		$updated = $wpdb->update(
			$table,
			array(
				'name'          => $name,
				'type'          => $type,
				'brevo_list_id' => $brevo_list ? $brevo_list : null,
				'query_type'    => $query_type,
				'query_params'  => $query_params,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			Bcsend_Logger::log( 'segment', 'Failed to update segment ID ' . $id . ': ' . $wpdb->last_error, '', 'error' );
			wp_send_json_error( array( 'message' => __( 'Failed to update segment.', 'beacon-campaign-sender' ) ) );
		}

		Bcsend_Logger::log( 'segment', 'Segment updated: ' . $name . ' (ID ' . $id . ')' );
		wp_send_json_success( array( 'message' => __( 'Segment updated.', 'beacon-campaign-sender' ) ) );
	}

	/**
	 * AJAX: Delete a segment.
	 */
	public function ajax_delete_segment() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_segments';

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Segment ID is required.', 'beacon-campaign-sender' ) ) );
		}

		// Prevent deletion if segment is used by active campaigns.
		$campaigns_table = $wpdb->prefix . 'bcsend_campaigns';
		$in_use          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $campaigns_table WHERE segment_id = %d AND status NOT IN ('sent', 'failed')",
				$id
			)
		);

		if ( $in_use > 0 ) {
			wp_send_json_error( array( 'message' => __( 'Cannot delete segment that is used by active campaigns.', 'beacon-campaign-sender' ) ) );
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete segment.', 'beacon-campaign-sender' ) ) );
		}

		Bcsend_Logger::log( 'segment', 'Segment deleted: ID ' . $id );
		wp_send_json_success( array( 'message' => __( 'Segment deleted.', 'beacon-campaign-sender' ) ) );
	}

	/**
	 * AJAX: Sync a segment's contact count from Brevo.
	 */
	public function ajax_sync_segment() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_segments';

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Segment ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$segment = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $segment ) {
			wp_send_json_error( array( 'message' => __( 'Segment not found.', 'beacon-campaign-sender' ) ) );
		}

		$now = current_time( 'mysql', true );

		if ( 'brevo_list' === $segment['type'] && ! empty( $segment['brevo_list_id'] ) ) {
			// Brevo list segments: fetch the latest count from the Brevo API.
			$brevo     = new Bcsend_Brevo_API();
			$list_data = $brevo->get_list( (int) $segment['brevo_list_id'] );

			if ( is_wp_error( $list_data ) ) {
				wp_send_json_error( array( 'message' => $list_data->get_error_message() ) );
			}

			$contact_count = Bcsend_Brevo_API::extract_subscriber_count( $list_data );

			$wpdb->update(
				$table,
				array(
					'contact_count' => $contact_count,
					'last_synced'   => $now,
				),
				array( 'id' => $id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			Bcsend_Logger::log( 'segment', 'Brevo list segment synced: ID ' . $id . ', count: ' . $contact_count );

			wp_send_json_success(
				array(
					'message'       => __( 'Segment synced.', 'beacon-campaign-sender' ),
					'contact_count' => $contact_count,
					'last_synced'   => $now,
				)
			);
		}

		// Smart segments: resolve the actual email list from local data.
		$segment_obj   = (object) $segment;
		$emails        = Bcsend_Segment_Engine::get_user_emails_for_segment( $segment_obj );
		$contact_count = count( $emails );

		$wpdb->update(
			$table,
			array(
				'contact_count' => $contact_count,
				'last_synced'   => $now,
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log( 'segment', 'Segment synced: ID ' . $id . ', count: ' . $contact_count );

		wp_send_json_success(
			array(
				'message'       => __( 'Segment synced.', 'beacon-campaign-sender' ),
				'contact_count' => $contact_count,
				'last_synced'   => $now,
			)
		);
	}

	/**
	 * AJAX: Get all segments.
	 */
	public function ajax_get_segments() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$segments = Bcsend_Segment_Engine::get_segments();

		wp_send_json_success( array( 'segments' => $segments ) );
	}

	/**
	 * AJAX: Sync all smart segments.
	 */
	public function ajax_sync_all_segments() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$result = Bcsend_Segment_Engine::sync_all();

		wp_send_json_success(
			array(
				'message' => __( 'All segments synced.', 'beacon-campaign-sender' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * AJAX: Get contacts (emails) for a segment.
	 */
	public function ajax_get_segment_contacts() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Segment ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$segment = Bcsend_Segment_Engine::get_segment( $id );

		if ( ! $segment ) {
			wp_send_json_error( array( 'message' => __( 'Segment not found.', 'beacon-campaign-sender' ) ) );
		}

		// Brevo list segments get their count from the Brevo API via sync,
		// not from a local query. Return the stored count as-is.
		if ( 'brevo_list' === $segment->type ) {
			wp_send_json_success(
				array(
					'emails' => array(),
					'total'  => (int) $segment->contact_count,
				)
			);
		}

		$emails = Bcsend_Segment_Engine::get_user_emails_for_segment( $segment );
		$count  = count( $emails );

		// Update the stored contact count so the table stays fresh.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bcsend_segments',
			array( 'contact_count' => $count ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_send_json_success(
			array(
				'emails' => $emails,
				'total'  => $count,
			)
		);
	}

	/**
	 * AJAX: Get audience options (segments) for the composer dropdown.
	 */
	public function ajax_get_audience_options() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		// Smart segments from DB.
		$segments = Bcsend_Segment_Engine::get_segments();
		$options  = array();

		if ( is_array( $segments ) ) {
			foreach ( $segments as $seg ) {
				$options[] = array(
					'id'            => intval( $seg->id ),
					'name'          => $seg->name,
					'type'          => $seg->type,
					'contact_count' => intval( $seg->contact_count ),
					'last_synced'   => $seg->last_synced,
				);
			}
		}

		wp_send_json_success(
			array(
				'segments' => $options,
			)
		);
	}

	/**
	 * AJAX: Get the default email template.
	 */
	public function ajax_get_default_template() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$template_path = BCSEND_PLUGIN_DIR . 'templates/default-email.html';
		$template      = '';

		if ( file_exists( $template_path ) ) {
			$template = file_get_contents( $template_path );
			$template = str_replace( '{{COMPANY_NAME}}', get_bloginfo( 'name' ), $template );
		}

		wp_send_json_success( array( 'template' => $template ) );
	}

	// =========================================================================
	// Standalone Push Notifications
	// =========================================================================

	/**
	 * AJAX: Submit a standalone push notification.
	 */
	public function ajax_push_submit() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$result = Bcsend_Push_Manager::create(
			array(
				'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
				'message'      => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
				'link_url'     => isset( $_POST['link_url'] ) ? esc_url_raw( wp_unslash( $_POST['link_url'] ) ) : '',
				'target_type'  => isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( $_POST['target_type'] ) ) : 'all_users',
				'target_data'  => isset( $_POST['target_data'] ) ? bcsend_sanitize_json_string( wp_unslash( $_POST['target_data'] ), '[]', 'key' ) : null,
				'is_scheduled' => ! empty( $_POST['is_scheduled'] ),
				'scheduled_at' => isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '',
				'tz_offset'    => isset( $_POST['tz_offset'] ) ? (int) $_POST['tz_offset'] : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$redirect = add_query_arg( array( 'page' => 'bcsend-push' ), admin_url( 'admin.php' ) );

		wp_send_json_success(
			array(
				'message'  => __( 'Push notification submitted.', 'beacon-campaign-sender' ),
				'push_id'  => $result,
				'redirect' => $redirect,
			)
		);
	}

	/**
	 * AJAX: Search users for push notification targeting.
	 */
	public function ajax_push_search_users() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) && ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$search  = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$ids_raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids     = array_values( array_filter( array_map( 'absint', $ids_raw ) ) );

		if ( ! empty( $ids ) ) {
			$wp_users = get_users(
				array(
					'include' => $ids,
					'number'  => count( $ids ),
					'fields'  => array( 'ID', 'display_name', 'user_email' ),
				)
			);
		} else {
			if ( strlen( $search ) < 2 ) {
				wp_send_json_success( array( 'users' => array() ) );
			}
			$wp_users = get_users(
				array(
					'search'  => '*' . $search . '*',
					'number'  => 20,
					'orderby' => 'display_name',
					'fields'  => array( 'ID', 'display_name', 'user_email' ),
				)
			);
		}

		$users = array();
		foreach ( $wp_users as $u ) {
			$users[] = array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'email' => $u->user_email,
			);
		}

		wp_send_json_success( array( 'users' => $users ) );
	}

	/**
	 * AJAX: Delete a push notification.
	 */
	public function ajax_push_delete() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$push_id = isset( $_POST['push_id'] ) ? absint( $_POST['push_id'] ) : 0;
		$result  = Bcsend_Push_Manager::delete( $push_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Push notification deleted.', 'beacon-campaign-sender' ) ) );
	}

	/**
	 * AJAX: Cancel a scheduled push notification.
	 */
	public function ajax_push_cancel() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$push_id = isset( $_POST['push_id'] ) ? absint( $_POST['push_id'] ) : 0;
		$result  = Bcsend_Push_Manager::cancel( $push_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Push notification cancelled.', 'beacon-campaign-sender' ) ) );
	}

	// =========================================================================
	// Products & AI Campaign Generation
	// =========================================================================

	/**
	 * AJAX: Search WooCommerce products.
	 */
	public function ajax_search_products() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( $_POST['per_page'] ) ) ) : 10;

		if ( strlen( $search ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Search term must be at least 2 characters.', 'beacon-campaign-sender' ) ) );
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $search,
			'posts_per_page' => $per_page,
			'fields'         => 'ids',
		);

		$query    = new WP_Query( $args );
		$products = array();

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$products[] = array(
				'id'        => $product->get_id(),
				'name'      => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'price'     => $product->get_price(),
				'image'     => wp_get_attachment_url( $product->get_image_id() ),
				'permalink' => $product->get_permalink(),
				'sku'       => $product->get_sku(),
			);
		}

		wp_send_json_success( array( 'products' => $products ) );
	}

	/**
	 * AJAX: Search WooCommerce product categories.
	 */
	public function ajax_search_categories() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( strlen( $search ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Search term must be at least 2 characters.', 'beacon-campaign-sender' ) ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'search'     => $search,
				'hide_empty' => false,
				'number'     => 20,
			)
		);

		$categories = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'count' => $term->count,
				);
			}
		}

		wp_send_json_success( array( 'categories' => $categories ) );
	}

	/**
	 * AJAX: Fetch Zernio profiles.
	 */
	public function ajax_zernio_fetch_profiles() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$api = new Bcsend_Zernio_API();

		if ( ! $api->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Zernio API key not configured.', 'beacon-campaign-sender' ) ) );
		}

		$profiles = $api->list_profiles();

		if ( is_wp_error( $profiles ) ) {
			wp_send_json_error( array( 'message' => $profiles->get_error_message() ) );
		}

		update_option( 'bcsend_zernio_profiles', $profiles, false );

		wp_send_json_success( array( 'profiles' => $profiles ) );
	}

	/**
	 * AJAX: Persist selected Zernio profile outside the full settings save flow.
	 */
	public function ajax_zernio_set_profile() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$profile_id   = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : '';
		$profile_name = isset( $_POST['profile_name'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_name'] ) ) : '';
		$settings     = get_option( 'bcsend_settings', array() );

		$settings['zernio_profile_id'] = $profile_id;

		update_option( 'bcsend_settings', $settings, false );
		update_option( 'bcsend_zernio_profile_name', $profile_name, false );

		wp_send_json_success(
			array(
				'profile_id'   => $profile_id,
				'profile_name' => $profile_name,
				'message'      => __( 'Active Zernio profile saved.', 'beacon-campaign-sender' ),
			)
		);
	}

	/**
	 * AJAX: Sync Zernio accounts.
	 */
	public function ajax_zernio_sync_accounts() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$api = new Bcsend_Zernio_API();

		if ( ! $api->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Zernio API key not configured.', 'beacon-campaign-sender' ) ) );
		}

		$profile_id   = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : '';
		$profile_name = isset( $_POST['profile_name'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_name'] ) ) : '';
		if ( empty( $profile_id ) ) {
			$settings   = get_option( 'bcsend_settings', array() );
			$profile_id = isset( $settings['zernio_profile_id'] ) ? sanitize_text_field( $settings['zernio_profile_id'] ) : '';
		}

		$accounts = $api->list_accounts();

		if ( is_wp_error( $accounts ) ) {
			wp_send_json_error( array( 'message' => $accounts->get_error_message() ) );
		}

		Bcsend_Logger::log(
			'zernio',
			'Raw Zernio accounts response',
			wp_json_encode(
				array(
					'selected_profile_id'   => $profile_id,
					'selected_profile_name' => $profile_name,
					'accounts'              => $accounts,
				)
			)
		);

		$accounts = Bcsend_Zernio_API::filter_accounts_for_profile( $accounts, $profile_id, $profile_name );

		Bcsend_Logger::log(
			'zernio',
			'Filtered Zernio accounts response',
			wp_json_encode(
				array(
					'selected_profile_id'   => $profile_id,
					'selected_profile_name' => $profile_name,
					'accounts'              => $accounts,
				)
			)
		);

		if ( function_exists( 'bcsend_sanitize_zernio_accounts' ) ) {
			$accounts = bcsend_sanitize_zernio_accounts( $accounts );
		}
		update_option( 'bcsend_zernio_accounts', $accounts, false );
		wp_send_json_success(
			array(
				'accounts'   => $accounts,
				'profile_id' => $profile_id,
			)
		);
	}

	/**
	 * AJAX: Create or update the remote Zernio webhook config.
	 */
	public function ajax_zernio_sync_webhook() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$settings = Bcsend_Settings::get_settings();
		if ( empty( $settings['zernio_api_key'] ) || empty( $settings['zernio_webhook_secret'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Zernio API key and webhook secret are required.', 'beacon-campaign-sender' ) ) );
		}

		$result = self::sync_zernio_webhook_settings( $settings );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Webhook synced with Zernio.', 'beacon-campaign-sender' ),
				'url'     => rest_url( 'beacon-campaign-sender/v1/zernio/webhook' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * Create or update the remote Zernio webhook using the provided settings.
	 *
	 * @param array $settings Plaintext settings array.
	 * @return array|WP_Error
	 */
	public static function sync_zernio_webhook_settings( $settings ) {
		if ( empty( $settings['zernio_api_key'] ) || empty( $settings['zernio_webhook_secret'] ) ) {
			return new WP_Error( 'missing_zernio_webhook_settings', __( 'Zernio API key and webhook secret are required.', 'beacon-campaign-sender' ) );
		}

		$api      = new Bcsend_Zernio_API( $settings['zernio_api_key'] );
		$endpoint = rest_url( 'beacon-campaign-sender/v1/zernio/webhook' );
		$payload  = array(
			'name'     => 'Beacon Campaign Sender Social Status',
			'url'      => $endpoint,
			'events'   => array( 'post.scheduled', 'post.published', 'post.failed', 'post.partial', 'post.cancelled', 'post.recycled' ),
			'secret'   => $settings['zernio_webhook_secret'],
			'isActive' => ! empty( $settings['zernio_webhook_enabled'] ),
		);

		$existing   = $api->list_webhooks();
		$webhook_id = '';
		if ( ! is_wp_error( $existing ) ) {
			$items = array();
			if ( isset( $existing['webhooks'] ) && is_array( $existing['webhooks'] ) ) {
				$items = $existing['webhooks'];
			} elseif ( isset( $existing['data'] ) && is_array( $existing['data'] ) ) {
				$items = $existing['data'];
			} elseif ( is_array( $existing ) ) {
				$items = $existing;
			}

			Bcsend_Logger::log(
				'zernio',
				'Zernio webhook settings list response',
				wp_json_encode(
					array(
						'endpoint' => $endpoint,
						'response' => $existing,
					)
				)
			);

			foreach ( $items as $item ) {
				if ( isset( $item['url'] ) && untrailingslashit( $item['url'] ) === untrailingslashit( $endpoint ) ) {
					if ( isset( $item['_id'] ) ) {
						$webhook_id = (string) $item['_id'];
					} elseif ( isset( $item['id'] ) ) {
						$webhook_id = (string) $item['id'];
					}
					break;
				}
			}
		}

		Bcsend_Logger::log(
			'zernio',
			'Zernio webhook sync payload',
			wp_json_encode(
				array(
					'webhook_id' => $webhook_id,
					'payload'    => self::redact_zernio_secret_data( $payload ),
				)
			)
		);

		$result = ! empty( $webhook_id )
			? $api->update_webhook( $webhook_id, $payload )
			: $api->create_webhook( $payload );

		// If update fails because the webhook cannot be updated at that ID, fall
		// back to creating a fresh active webhook with the correct payload.
		if ( is_wp_error( $result ) && ! empty( $webhook_id ) ) {
			$maybe_404 = false !== strpos( $result->get_error_code(), '404' ) || false !== strpos( strtolower( $result->get_error_message() ), '404' );
			if ( $maybe_404 ) {
				Bcsend_Logger::log(
					'zernio',
					'Zernio webhook update failed; falling back to create',
					wp_json_encode(
						array(
							'webhook_id' => $webhook_id,
							'payload'    => self::redact_zernio_secret_data( $payload ),
							'error'      => $result->get_error_message(),
						)
					)
				);
				$result = $api->create_webhook( $payload );
			}
		}

		if ( ! is_wp_error( $result ) ) {
			update_option( 'bcsend_zernio_webhook_url', $endpoint, false );
			Bcsend_Logger::log(
				'zernio',
				'Zernio webhook sync response',
				wp_json_encode( $result )
			);
		}

		return $result;
	}

	/**
	 * AJAX: Clear stored webhook diagnostics.
	 */
	public function ajax_zernio_clear_webhook_diagnostics() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		delete_option( 'bcsend_zernio_webhook_diagnostics' );
		wp_send_json_success( array( 'message' => __( 'Webhook diagnostics cleared.', 'beacon-campaign-sender' ) ) );
	}

	/**
	 * AJAX: Write a local test webhook diagnostic entry.
	 */
	public function ajax_zernio_test_webhook_diagnostics() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$event   = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : 'post.published';
		$payload = Bcsend_Social_Sender::build_test_webhook_payload( $event );

		update_option(
			'bcsend_zernio_webhook_diagnostics',
			array(
				'last_received_at'      => current_time( 'mysql', true ),
				'last_status'           => 'local_test',
				'last_event'            => $event,
				'last_signature_header' => 'local-test',
				'last_error'            => '',
				'last_payload'          => self::summarize_zernio_payload_for_storage( $payload ),
			),
			false
		);

		wp_send_json_success(
			array(
				'message' => __( 'Local webhook diagnostic event recorded.', 'beacon-campaign-sender' ),
				'payload' => $payload,
			)
		);
	}

	/**
	 * Handle inbound Zernio webhook events.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_zernio_webhook( WP_REST_Request $request ) {
		$settings    = Bcsend_Settings::get_settings();
		$secret      = isset( $settings['zernio_webhook_secret'] ) ? (string) $settings['zernio_webhook_secret'] : '';
		$raw_body    = $request->get_body();
		$received_at = current_time( 'mysql', true );

		if ( empty( $secret ) ) {
			update_option(
				'bcsend_zernio_webhook_diagnostics',
				array(
					'last_received_at'      => $received_at,
					'last_status'           => 'rejected_no_secret',
					'last_event'            => '',
					'last_signature_header' => '',
					'last_error'            => 'Webhook secret not configured.',
					'last_payload'          => self::summarize_zernio_payload_for_storage( $raw_body ),
				),
				false
			);
			return new WP_REST_Response( array( 'message' => 'Webhook secret not configured.' ), 403 );
		}

		$headers = array(
			$request->get_header( 'x-zernio-signature' ),
			$request->get_header( 'x-zernio-webhook-signature' ),
			$request->get_header( 'x-webhook-signature' ),
		);
		Bcsend_Logger::log(
			'webhook',
			'Zernio webhook headers received',
			array(
				'x_zernio_signature'         => $request->get_header( 'x-zernio-signature' ),
				'x_zernio_webhook_signature' => $request->get_header( 'x-zernio-webhook-signature' ),
				'x_webhook_signature'        => $request->get_header( 'x-webhook-signature' ),
				'user_agent'                 => $request->get_header( 'user-agent' ),
			)
		);
		$provided_signature = '';
		foreach ( $headers as $header_value ) {
			if ( ! empty( $header_value ) ) {
				$provided_signature = trim( (string) $header_value );
				break;
			}
		}

		if ( empty( $provided_signature ) ) {
			update_option(
				'bcsend_zernio_webhook_diagnostics',
				array(
					'last_received_at'      => $received_at,
					'last_status'           => 'rejected_missing_signature',
					'last_event'            => '',
					'last_signature_header' => '',
					'last_error'            => 'Missing webhook signature.',
					'last_payload'          => self::summarize_zernio_payload_for_storage( $raw_body ),
				),
				false
			);
			return new WP_REST_Response( array( 'message' => 'Missing webhook signature.' ), 401 );
		}

		$computed = hash_hmac( 'sha256', $raw_body, $secret );
		$valid    = hash_equals( $computed, preg_replace( '/^sha256=/', '', $provided_signature ) );

		if ( ! $valid ) {
			Bcsend_Logger::log( 'webhook', 'Rejected Zernio webhook signature', array( 'provided_signature' => $provided_signature ), 'error' );
			update_option(
				'bcsend_zernio_webhook_diagnostics',
				array(
					'last_received_at'      => $received_at,
					'last_status'           => 'rejected_invalid_signature',
					'last_event'            => '',
					'last_signature_header' => $provided_signature,
					'last_error'            => 'Invalid signature.',
					'last_payload'          => self::summarize_zernio_payload_for_storage( $raw_body ),
				),
				false
			);
			return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			update_option(
				'bcsend_zernio_webhook_diagnostics',
				array(
					'last_received_at'      => $received_at,
					'last_status'           => 'rejected_invalid_json',
					'last_event'            => '',
					'last_signature_header' => $provided_signature,
					'last_error'            => 'Invalid JSON payload.',
					'last_payload'          => self::summarize_zernio_payload_for_storage( $raw_body ),
				),
				false
			);
			return new WP_REST_Response( array( 'message' => 'Invalid JSON payload.' ), 400 );
		}

		try {
			$result = Bcsend_Social_Sender::handle_webhook_event( $payload );
			Bcsend_Logger::log( 'webhook', 'Processed Zernio webhook event', self::redact_zernio_secret_data( $payload ) );
			update_option(
				'bcsend_zernio_webhook_diagnostics',
				array(
					'last_received_at'      => $received_at,
					'last_status'           => 'processed',
					'last_event'            => isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '',
					'last_signature_header' => $provided_signature,
					'last_error'            => '',
					'last_payload'          => self::summarize_zernio_payload_for_storage( $payload ),
					'last_result'           => $result,
				),
				false
			);

			return new WP_REST_Response(
				array(
					'ok'     => true,
					'result' => $result,
				),
				200
			);
		} catch ( Throwable $e ) {
			Bcsend_Logger::log(
				'webhook',
				'Zernio webhook processing failed',
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
					'payload' => self::redact_zernio_secret_data( $payload ),
				),
				'error'
			);
			update_option(
				'bcsend_zernio_webhook_diagnostics',
				array(
					'last_received_at'      => $received_at,
					'last_status'           => 'processing_error',
					'last_event'            => isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '',
					'last_signature_header' => $provided_signature,
					'last_error'            => $e->getMessage(),
					'last_payload'          => self::summarize_zernio_payload_for_storage( $payload ),
				),
				false
			);

			return new WP_REST_Response( array( 'message' => 'Webhook processing failed.' ), 500 );
		}
	}

	/**
	 * Redact secrets from Zernio payloads before logging or display.
	 *
	 * @param mixed $data Payload data.
	 * @return mixed
	 */
	private static function redact_zernio_secret_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$redacted = array();

		foreach ( $data as $key => $value ) {
			$key_string = is_string( $key ) ? strtolower( $key ) : $key;

			if ( is_string( $key_string ) && false !== strpos( $key_string, 'secret' ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			$redacted[ $key ] = is_array( $value )
				? self::redact_zernio_secret_data( $value )
				: $value;
		}

		return $redacted;
	}

	/**
	 * Summarize incoming webhook payloads without storing raw bodies.
	 *
	 * @param mixed $payload Raw string or decoded payload array.
	 * @return string
	 */
	private static function summarize_zernio_payload_for_storage( $payload ) {
		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );

			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			} else {
				return wp_json_encode(
					array(
						'format' => 'raw',
						'length' => strlen( $payload ),
						'sha256' => hash( 'sha256', $payload ),
					)
				);
			}
		}

		if ( ! is_array( $payload ) ) {
			return '';
		}

		$summary = array(
			'event'        => isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '',
			'payload_keys' => array_keys( $payload ),
		);

		if ( isset( $payload['post'] ) && is_array( $payload['post'] ) ) {
			$post = $payload['post'];

			$summary['post'] = array(
				'id'        => isset( $post['_id'] ) ? sanitize_text_field( (string) $post['_id'] ) : '',
				'status'    => isset( $post['status'] ) ? sanitize_text_field( (string) $post['status'] ) : '',
				'platforms' => isset( $post['platforms'] ) && is_array( $post['platforms'] ) ? count( $post['platforms'] ) : 0,
			);
		}

		return wp_json_encode( self::redact_zernio_secret_data( $summary ) );
	}

	/**
	 * Redact any previously stored webhook diagnostic payload.
	 *
	 * @return void
	 */
	private static function sanitize_zernio_webhook_diagnostics() {
		$diagnostics = get_option( 'bcsend_zernio_webhook_diagnostics', array() );

		if ( empty( $diagnostics ) || ! is_array( $diagnostics ) || empty( $diagnostics['last_payload'] ) ) {
			return;
		}

		$redacted = self::summarize_zernio_payload_for_storage( $diagnostics['last_payload'] );

		if ( $redacted === $diagnostics['last_payload'] ) {
			return;
		}

		$diagnostics['last_payload'] = $redacted;
		update_option( 'bcsend_zernio_webhook_diagnostics', $diagnostics, false );
	}

	// =========================================================================
	// Templates
	// =========================================================================

	/**
	 * AJAX: Get templates list.
	 */
	public function ajax_get_templates() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_templates';

		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( $_POST['per_page'] ) ) ) : 50;
		$offset   = ( $page - 1 ) * $per_page;

		$total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, thumbnail, created_at FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'items'    => $items ? $items : array(),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * AJAX: Save a template (create or update).
	 */
	public function ajax_save_template() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_templates';

		$id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$html_content = isset( $_POST['html_content'] ) ? bcsend_kses_email( wp_unslash( $_POST['html_content'] ) ) : '';
		$plain_text   = isset( $_POST['plain_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['plain_text'] ) ) : '';
		$thumbnail    = isset( $_POST['thumbnail'] ) ? esc_url_raw( wp_unslash( $_POST['thumbnail'] ) ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Template name is required.', 'beacon-campaign-sender' ) ) );
		}

		$data   = array(
			'name'         => $name,
			'html_content' => $html_content,
			'plain_text'   => $plain_text,
			'thumbnail'    => $thumbnail,
		);
		$format = array( '%s', '%s', '%s', '%s' );

		if ( $id ) {
			$result = $wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) );

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update template.', 'beacon-campaign-sender' ) ) );
			}

			Bcsend_Logger::log( 'template', 'Template updated: ' . $name . ' (ID ' . $id . ')' );
			wp_send_json_success(
				array(
					'message' => __( 'Template updated.', 'beacon-campaign-sender' ),
					'id'      => $id,
				)
			);
		} else {
			$result = $wpdb->insert( $table, $data, $format );

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to create template.', 'beacon-campaign-sender' ) ) );
			}

			$new_id = $wpdb->insert_id;
			Bcsend_Logger::log( 'template', 'Template created: ' . $name . ' (ID ' . $new_id . ')' );
			wp_send_json_success(
				array(
					'message' => __( 'Template created.', 'beacon-campaign-sender' ),
					'id'      => $new_id,
				)
			);
		}
	}

	/**
	 * AJAX: Delete a template.
	 */
	public function ajax_delete_template() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_templates';

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Template ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete template.', 'beacon-campaign-sender' ) ) );
		}

		Bcsend_Logger::log( 'template', 'Template deleted: ID ' . $id );
		wp_send_json_success( array( 'message' => __( 'Template deleted.', 'beacon-campaign-sender' ) ) );
	}

	/**
	 * AJAX: Duplicate a template.
	 */
	public function ajax_duplicate_template() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_templates';

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Template ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$original = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $original ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'beacon-campaign-sender' ) ) );
		}

		$result = $wpdb->insert(
			$table,
			array(
				'name'         => $original['name'] . ' (Copy)',
				'html_content' => $original['html_content'],
				'plain_text'   => $original['plain_text'],
				'thumbnail'    => $original['thumbnail'],
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to duplicate template.', 'beacon-campaign-sender' ) ) );
		}

		$new_id = $wpdb->insert_id;
		Bcsend_Logger::log( 'template', 'Template duplicated: ID ' . $id . ' -> ID ' . $new_id );
		wp_send_json_success(
			array(
				'message' => __( 'Template duplicated.', 'beacon-campaign-sender' ),
				'id'      => $new_id,
			)
		);
	}

	// =========================================================================
	// Dashboard & Analytics
	// =========================================================================

	/**
	 * AJAX: Get dashboard summary data.
	 */
	public function ajax_get_dashboard_data() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'view_bcsend_logs' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$data = bcsend_ability_get_dashboard();

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Get analytics data for charts.
	 */
	public function ajax_get_analytics_data() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'view_bcsend_logs' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$campaigns_table = $wpdb->prefix . 'bcsend_campaigns';
		$logs_table      = $wpdb->prefix . 'bcsend_logs';

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '30';
		$days   = absint( $period );
		if ( $days < 1 || $days > 365 ) {
			$days = 30;
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Campaigns sent per day.
		$campaigns_by_day = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(sent_at) as send_date, COUNT(*) as count FROM $campaigns_table WHERE sent_at >= %s AND status = %s GROUP BY DATE(sent_at) ORDER BY send_date ASC",
				$since,
				'sent'
			),
			ARRAY_A
		);

		// Logs by type.
		$logs_by_type = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COUNT(*) as count FROM $logs_table WHERE created_at >= %s GROUP BY type ORDER BY count DESC",
				$since
			),
			ARRAY_A
		);

		// Error rate by day.
		$errors_by_day = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as log_date, COUNT(*) as count FROM $logs_table WHERE created_at >= %s AND status = %s GROUP BY DATE(created_at) ORDER BY log_date ASC",
				$since,
				'error'
			),
			ARRAY_A
		);

		// Campaigns by status.
		$campaigns_by_status = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM $campaigns_table GROUP BY status ORDER BY count DESC",
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'campaigns_by_day'    => $campaigns_by_day ? $campaigns_by_day : array(),
				'logs_by_type'        => $logs_by_type ? $logs_by_type : array(),
				'errors_by_day'       => $errors_by_day ? $errors_by_day : array(),
				'campaigns_by_status' => $campaigns_by_status ? $campaigns_by_status : array(),
				'period_days'         => $days,
			)
		);
	}

	/*
	================================================================
		Content Library — Posts / Pages search
		================================================================ */

	/**
	 * AJAX: Search WordPress posts, pages, and custom post types.
	 */
	public function ajax_search_posts() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$per_page  = isset( $_POST['per_page'] ) ? max( 1, min( 100, absint( $_POST['per_page'] ) ) ) : 10;

		if ( strlen( $search ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Search term must be at least 2 characters.', 'beacon-campaign-sender' ) ) );
		}

		$allowed_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! isset( $allowed_types[ $post_type ] ) ) {
			$post_type = 'post';
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				's'              => $search,
				'posts_per_page' => $per_page,
			)
		);

		$results = array();
		foreach ( $query->posts as $p ) {
			$thumb     = get_the_post_thumbnail_url( $p->ID, 'medium' );
			$results[] = array(
				'id'        => $p->ID,
				'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'excerpt'   => html_entity_decode( wp_trim_words( get_the_excerpt( $p ), 20, '...' ), ENT_QUOTES, 'UTF-8' ),
				'permalink' => get_permalink( $p ),
				'image'     => $thumb ? $thumb : '',
				'date'      => get_the_date( 'M j, Y', $p ),
				'post_type' => $p->post_type,
			);
		}

		wp_send_json_success( array( 'posts' => $results ) );
	}

	/*
	================================================================
		Content Library — Reusable Snippets
		================================================================ */

	/**
	 * AJAX: Get saved snippets.
	 */
	public function ajax_get_snippets() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_snippets';

		// Create table on-the-fly if it doesn't exist yet (upgrade path).
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			Bcsend_Activator::activate();
		}

		$items = $wpdb->get_results(
			"SELECT id, name, category, html_content, created_at FROM $table ORDER BY created_at DESC",
			ARRAY_A
		);

		wp_send_json_success( array( 'snippets' => $items ? $items : array() ) );
	}

	/**
	 * AJAX: Save a snippet (create or update).
	 */
	public function ajax_save_snippet() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_snippets';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			Bcsend_Activator::activate();
		}

		$id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : 'general';
		$html_content = isset( $_POST['html_content'] ) ? bcsend_kses_email( wp_unslash( $_POST['html_content'] ) ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Snippet name is required.', 'beacon-campaign-sender' ) ) );
		}

		$data   = array(
			'name'         => $name,
			'category'     => $category,
			'html_content' => $html_content,
		);
		$format = array( '%s', '%s', '%s' );

		if ( $id ) {
			$result = $wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) );
			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update snippet.', 'beacon-campaign-sender' ) ) );
			}
			wp_send_json_success(
				array(
					'message' => __( 'Snippet updated.', 'beacon-campaign-sender' ),
					'id'      => $id,
				)
			);
		}

		$result = $wpdb->insert( $table, $data, $format );
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save snippet.', 'beacon-campaign-sender' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Snippet saved.', 'beacon-campaign-sender' ),
				'id'      => $wpdb->insert_id,
			)
		);
	}

	/**
	 * AJAX: Delete a snippet.
	 */
	public function ajax_delete_snippet() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_bcsend_campaigns' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bcsend_snippets';
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Snippet ID is required.', 'beacon-campaign-sender' ) ) );
		}

		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		wp_send_json_success( array( 'message' => __( 'Snippet deleted.', 'beacon-campaign-sender' ) ) );
	}

	/*
	================================================================
		Content Library — System Test
		================================================================ */

	/**
	 * AJAX: Test Content Library features (snippets CRUD, post search, product search).
	 */
	public function ajax_test_content_library() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		global $wpdb;
		$results = array();

		// 1. Snippets table exists.
		$table  = $wpdb->prefix . 'bcsend_snippets';
		$exists = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table );
		if ( ! $exists ) {
			Bcsend_Activator::activate();
			$exists = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table );
		}
		$results['snippets_table'] = array(
			'label'  => 'Snippets table exists',
			'result' => $exists,
		);

		// 2. Save a test snippet.
		$save_ok = false;
		$test_id = 0;
		if ( $exists ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'name'         => '__bcsend_test_snippet__',
					'category'     => 'test',
					'html_content' => '<p>Test snippet content</p>',
				),
				array( '%s', '%s', '%s' )
			);
			if ( false !== $inserted ) {
				$test_id = $wpdb->insert_id;
				$save_ok = true;
			}
		}
		$results['snippet_save'] = array(
			'label'  => 'Save snippet',
			'result' => $save_ok,
		);

		// 3. Read it back.
		$read_ok = false;
		if ( $test_id ) {
			$row     = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $test_id )
			);
			$read_ok = ( null !== $row && $row->name === '__bcsend_test_snippet__' );
		}
		$results['snippet_read'] = array(
			'label'  => 'Read snippet',
			'result' => $read_ok,
		);

		// 4. Delete it.
		$delete_ok = false;
		if ( $test_id ) {
			$deleted   = $wpdb->delete( $table, array( 'id' => $test_id ), array( '%d' ) );
			$delete_ok = ( false !== $deleted );
		}
		$results['snippet_delete'] = array(
			'label'  => 'Delete snippet',
			'result' => $delete_ok,
		);

		// 5. Post search.
		$post_query             = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		$has_posts              = ( $post_query->found_posts > 0 );
		$results['post_search'] = array(
			'label'  => 'Post search (' . $post_query->found_posts . ' published posts found)',
			'result' => true,
		);

		// 6. Product search (WooCommerce).
		if ( class_exists( 'WooCommerce' ) ) {
			$product_query             = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
				)
			);
			$results['product_search'] = array(
				'label'  => 'Product search (' . $product_query->found_posts . ' published products found)',
				'result' => true,
			);
		} else {
			$results['product_search'] = array(
				'label'  => 'Product search (WooCommerce not active)',
				'result' => false,
			);
		}

		// 7. Media library.
		$media_count  = wp_count_attachments( 'image' );
		$total_images = 0;
		if ( $media_count ) {
			foreach ( (array) $media_count as $mime => $count ) {
				if ( 'trash' !== $mime ) {
					$total_images += (int) $count;
				}
			}
		}
		$results['media_library'] = array(
			'label'  => 'Media library (' . $total_images . ' images available)',
			'result' => ( $total_images > 0 ),
		);

		// 8. bcsend_kses_email preserves style tags.
		$test_html             = '<style>body{color:red;}</style><p>Hello</p>';
		$clean_html            = bcsend_kses_email( $test_html );
		$style_ok              = ( false !== strpos( $clean_html, '<style>' ) );
		$results['kses_email'] = array(
			'label'  => 'bcsend_kses_email preserves &lt;style&gt; tags',
			'result' => $style_ok,
		);

		wp_send_json_success( array( 'report' => $results ) );
	}
}

/**
 * Returns the main instance of Bcsend_Plugin.
 *
 * @return Bcsend_Plugin
 */
function bcsend() {
	return Bcsend_Plugin::get_instance();
}

// Initialize on plugins_loaded at priority 11 (after most dependencies).
add_action(
	'plugins_loaded',
	function () {
		bcsend();
	},
	11
);
