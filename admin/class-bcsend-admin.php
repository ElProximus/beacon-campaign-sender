<?php
/**
 * Main admin controller for Beacon Campaign Sender.
 *
 * Registers menu pages, enqueues admin assets, and delegates
 * page rendering to individual controller classes.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Admin
 *
 * @since 1.0.0
 */
class Bcsend_Admin {

	/**
	 * Constructor.
	 *
	 * Hooks into WordPress admin initialization.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
	}

	/**
	 * Run admin_init tasks.
	 *
	 * Delegates settings registration to the Bcsend_Settings controller.
	 *
	 * @since 1.0.0
	 */
	public function admin_init() {
		$settings = new Bcsend_Settings();
		$settings->register();
	}

	/**
	 * Register admin menu and submenu pages.
	 *
	 * @since 1.0.0
	 */
	public function add_menu_pages() {
		add_menu_page(
			__( 'Beacon Campaign Sender', 'beacon-campaign-sender' ),
			__( 'Beacon Campaign Sender', 'beacon-campaign-sender' ),
			'edit_bcsend_campaigns',
			'beacon-campaign-sender',
			array( $this, 'render_dashboard' ),
			'dashicons-megaphone',
			30
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Dashboard', 'beacon-campaign-sender' ),
			__( 'Dashboard', 'beacon-campaign-sender' ),
			'edit_bcsend_campaigns',
			'beacon-campaign-sender',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Composer', 'beacon-campaign-sender' ),
			__( 'Composer', 'beacon-campaign-sender' ),
			'edit_bcsend_campaigns',
			'bcsend-composer',
			array( $this, 'render_composer' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Campaign Queue', 'beacon-campaign-sender' ),
			__( 'Campaign Queue', 'beacon-campaign-sender' ),
			'edit_bcsend_campaigns',
			'bcsend-queue',
			array( $this, 'render_queue' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Push Notifications', 'beacon-campaign-sender' ),
			__( 'Push Notifications', 'beacon-campaign-sender' ),
			'manage_bcsend',
			'bcsend-push',
			array( $this, 'render_push' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Audiences', 'beacon-campaign-sender' ),
			__( 'Audiences', 'beacon-campaign-sender' ),
			'manage_bcsend',
			'bcsend-audiences',
			array( $this, 'render_audiences' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Templates', 'beacon-campaign-sender' ),
			__( 'Templates', 'beacon-campaign-sender' ),
			'edit_bcsend_campaigns',
			'bcsend-templates',
			array( $this, 'render_templates' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Analytics', 'beacon-campaign-sender' ),
			__( 'Analytics', 'beacon-campaign-sender' ),
			'edit_bcsend_campaigns',
			'bcsend-analytics',
			array( $this, 'render_analytics' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Subscribers', 'beacon-campaign-sender' ),
			__( 'Subscribers', 'beacon-campaign-sender' ),
			'manage_bcsend',
			'bcsend-subscribers',
			array( $this, 'render_subscribers' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Settings', 'beacon-campaign-sender' ),
			__( 'Settings', 'beacon-campaign-sender' ),
			'manage_bcsend',
			'bcsend-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Logs', 'beacon-campaign-sender' ),
			__( 'Logs', 'beacon-campaign-sender' ),
			'view_bcsend_logs',
			'bcsend-logs',
			array( $this, 'render_logs' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Email Log', 'beacon-campaign-sender' ),
			__( 'Email Log', 'beacon-campaign-sender' ),
			'view_bcsend_logs',
			'bcsend-email-log',
			array( $this, 'render_email_log' )
		);

		// Hidden test page.
		add_submenu_page(
			'',
			__( 'System Tests', 'beacon-campaign-sender' ),
			__( 'System Tests', 'beacon-campaign-sender' ),
			'manage_bcsend',
			'bcsend-tests',
			array( $this, 'render_tests' )
		);
	}

	/**
	 * Render the subscribers page.
	 *
	 * @return void
	 */
	public function render_subscribers() {
		$controller = new Bcsend_Subscribers();
		$controller->render();
	}

	/**
	 * Enqueue admin scripts and styles for Beacon Campaign Sender pages only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook ) {
		if ( false === strpos( $hook, 'beacon-campaign-sender' ) && false === strpos( $hook, 'bcsend-' ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( __DIR__ );
		$version    = defined( 'BCSEND_VERSION' ) ? BCSEND_VERSION : '1.0.0';

		// Global admin styles and scripts for all Beacon Campaign Sender pages.
		wp_enqueue_style(
			'bcsend-admin',
			$plugin_url . 'assets/css/bcsend-admin.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'bcsend-admin',
			$plugin_url . 'assets/js/bcsend-admin.js',
			array( 'jquery' ),
			$version,
			true
		);

		$settings                  = Bcsend_Settings::get_settings();
		$social_config             = Bcsend_Social_Workflow::get_platform_rules();
		$social_config['postMode'] = isset( $settings['zernio_post_mode'] ) && in_array( $settings['zernio_post_mode'], array( 'single', 'per_platform' ), true )
			? $settings['zernio_post_mode']
			: 'single';

		wp_localize_script(
			'bcsend-admin',
			'bcsendAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'bcsend_nonce' ),
				'adminEmail'   => wp_get_current_user()->user_email,
				'siteUrl'      => home_url( '/' ),
				'queueUrl'     => admin_url( 'admin.php?page=bcsend-queue' ),
				'socialConfig' => $social_config,
				'strings'      => array(
					'confirmDelete'  => __( 'Are you sure you want to delete this item?', 'beacon-campaign-sender' ),
					'saving'         => __( 'Saving...', 'beacon-campaign-sender' ),
					'saved'          => __( 'Saved successfully.', 'beacon-campaign-sender' ),
					'error'          => __( 'An error occurred. Please try again.', 'beacon-campaign-sender' ),
					'loading'        => __( 'Loading...', 'beacon-campaign-sender' ),
					'testSuccess'    => __( 'Connection successful!', 'beacon-campaign-sender' ),
					'testFailed'     => __( 'Connection failed.', 'beacon-campaign-sender' ),
					'generating'     => __( 'Generating content...', 'beacon-campaign-sender' ),
					'generated'      => __( 'Content generated successfully.', 'beacon-campaign-sender' ),
					'noResults'      => __( 'No results found.', 'beacon-campaign-sender' ),
					'selectSegment'  => __( 'Select an audience segment', 'beacon-campaign-sender' ),
					'requiredFields' => __( 'Please fill in all required fields.', 'beacon-campaign-sender' ),
				),
			)
		);

		// Page-specific assets.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		switch ( $page ) {
			case 'bcsend-composer':
				$composer_css_path = plugin_dir_path( __DIR__ ) . 'assets/css/composer.css';
				$composer_js_path  = plugin_dir_path( __DIR__ ) . 'assets/js/bcsend-composer.js';
				$composer_version  = file_exists( $composer_js_path ) ? (string) filemtime( $composer_js_path ) : $version;
				$composer_css_ver  = file_exists( $composer_css_path ) ? (string) filemtime( $composer_css_path ) : $version;
				wp_enqueue_media();
				wp_enqueue_style(
					'bcsend-composer',
					$plugin_url . 'assets/css/composer.css',
					array( 'bcsend-admin' ),
					$composer_css_ver
				);
				wp_enqueue_script(
					'bcsend-composer',
					$plugin_url . 'assets/js/bcsend-composer.js',
					array( 'bcsend-admin', 'jquery' ),
					$composer_version,
					true
				);
				break;

			case 'bcsend-analytics':
				wp_enqueue_style(
					'bcsend-analytics',
					$plugin_url . 'assets/css/analytics.css',
					array( 'bcsend-admin' ),
					$version
				);
				wp_enqueue_script(
					'chart-js',
					$plugin_url . 'assets/vendor/chart.min.js',
					array(),
					'4.5.1',
					true
				);
				wp_enqueue_script(
					'bcsend-analytics',
					$plugin_url . 'assets/js/bcsend-analytics.js',
					array( 'bcsend-admin', 'jquery', 'chart-js' ),
					$version,
					true
				);
				break;

			case 'bcsend-queue':
				wp_enqueue_script(
					'bcsend-queue',
					$plugin_url . 'assets/js/bcsend-queue.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-push':
				wp_enqueue_script(
					'bcsend-push',
					$plugin_url . 'assets/js/bcsend-push.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-audiences':
				wp_enqueue_script(
					'bcsend-audiences',
					$plugin_url . 'assets/js/bcsend-audiences.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-templates':
				wp_enqueue_script(
					'bcsend-templates',
					$plugin_url . 'assets/js/bcsend-templates.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-logs':
				wp_enqueue_script(
					'bcsend-logs',
					$plugin_url . 'assets/js/bcsend-logs.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-email-log':
				wp_enqueue_script(
					'bcsend-email-log',
					$plugin_url . 'assets/js/bcsend-email-log.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-tests':
				wp_enqueue_script(
					'bcsend-tests',
					$plugin_url . 'assets/js/bcsend-tests.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;

			case 'bcsend-settings':
				wp_enqueue_script(
					'bcsend-settings',
					$plugin_url . 'assets/js/bcsend-settings.js',
					array( 'bcsend-admin', 'jquery' ),
					$version,
					true
				);
				break;
		}
	}

	/**
	 * Render the Dashboard page.
	 *
	 * @since 1.0.0
	 */
	public function render_dashboard() {
		$controller = new Bcsend_Dashboard();
		$controller->render();
	}

	/**
	 * Render the Composer page.
	 *
	 * @since 1.0.0
	 */
	public function render_composer() {
		$controller = new Bcsend_Composer();
		$controller->render();
	}

	/**
	 * Render the Campaign Queue page.
	 *
	 * @since 1.0.0
	 */
	public function render_queue() {
		$controller = new Bcsend_Queue();
		$controller->render();
	}

	/**
	 * Render the Push Notifications page.
	 *
	 * @since 2.2.0
	 */
	public function render_push() {
		$controller = new Bcsend_Push_Admin();
		$controller->render();
	}

	/**
	 * Render the Audiences page.
	 *
	 * @since 1.0.0
	 */
	public function render_audiences() {
		$controller = new Bcsend_Audiences();
		$controller->render();
	}

	/**
	 * Render the Templates page.
	 *
	 * @since 1.0.0
	 */
	public function render_templates() {
		$controller = new Bcsend_Templates();
		$controller->render();
	}

	/**
	 * Render the Analytics page.
	 *
	 * @since 1.0.0
	 */
	public function render_analytics() {
		$controller = new Bcsend_Analytics();
		$controller->render();
	}

	/**
	 * Render the Settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_settings() {
		$controller = new Bcsend_Settings();
		$controller->render();
	}

	/**
	 * Render the Logs page.
	 *
	 * @since 1.0.0
	 */
	public function render_logs() {
		$controller = new Bcsend_Logs();
		$controller->render();
	}

	/**
	 * Render the Email Log page.
	 *
	 * @since 2.5.0
	 */
	public function render_email_log() {
		$controller = new Bcsend_Email_Log_Admin();
		$controller->render();
	}

	/**
	 * Render the System Tests page.
	 *
	 * @since 1.0.0
	 */
	public function render_tests() {
		$controller = new Bcsend_Test();
		$controller->render();
	}
}
