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
	 * Admin page slugs that make up the Campaign Manager workspace.
	 *
	 * @var string[]
	 */
	const WORKSPACE_PAGES = array(
		'beacon-campaign-sender',
		'bcsend-composer',
		'bcsend-queue',
	);

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
		add_action( 'current_screen', array( $this, 'suppress_workspace_notices' ), 999 );
		add_action( 'admin_head', array( $this, 'suppress_workspace_notices' ), -9999 );
		add_action( 'admin_notices', array( $this, 'suppress_workspace_notices' ), -9999 );
		add_action( 'all_admin_notices', array( $this, 'suppress_workspace_notices' ), -9999 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
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
			'manage_bcsend',
			'bcsend-templates',
			array( $this, 'render_templates' )
		);

		add_submenu_page(
			'beacon-campaign-sender',
			__( 'Analytics', 'beacon-campaign-sender' ),
			__( 'Analytics', 'beacon-campaign-sender' ),
			'manage_bcsend',
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
	 * Determine whether the current user should get the Beacon workspace shell.
	 *
	 * @return bool
	 */
	private function is_campaign_manager_user() {
		return bcsend_is_campaign_manager_user();
	}

	/**
	 * Get the current Beacon admin page slug.
	 *
	 * @return string
	 */
	private function get_current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Determine whether the current request is one of the workspace pages.
	 *
	 * @return bool
	 */
	private function is_workspace_page() {
		return in_array( $this->get_current_page(), self::WORKSPACE_PAGES, true );
	}

	/**
	 * Add body classes for the Campaign Manager workspace.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		if ( $this->is_campaign_manager_user() && $this->is_workspace_page() ) {
			$classes .= ' bcsend-campaign-user bcsend-focus-mode';
		}

		return $classes;
	}

	/**
	 * Hide the admin bar on Campaign Manager workspace pages.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function maybe_hide_admin_bar( $show ) {
		if ( $this->is_campaign_manager_user() && $this->is_workspace_page() ) {
			return false;
		}

		return $show;
	}

	/**
	 * Suppress generic WordPress/plugin notices in the Campaign Manager workspace.
	 *
	 * @return void
	 */
	public function suppress_workspace_notices() {
		if ( ! $this->is_campaign_manager_user() || ! $this->is_workspace_page() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Render the Campaign Manager workspace header.
	 *
	 * @return void
	 */
	private function render_workspace_header() {
		if ( ! $this->is_campaign_manager_user() || ! $this->is_workspace_page() ) {
			return;
		}

		$current = $this->get_current_page();
		$links   = array(
			'beacon-campaign-sender' => array(
				'label' => __( 'Dashboard', 'beacon-campaign-sender' ),
				'url'   => admin_url( 'admin.php?page=beacon-campaign-sender' ),
			),
			'bcsend-composer'        => array(
				'label' => __( 'Composer', 'beacon-campaign-sender' ),
				'url'   => admin_url( 'admin.php?page=bcsend-composer' ),
			),
			'bcsend-queue'           => array(
				'label' => __( 'Campaign Queue', 'beacon-campaign-sender' ),
				'url'   => admin_url( 'admin.php?page=bcsend-queue' ),
			),
		);

		$status = $this->get_workspace_status();
		?>
		<div class="bcsend-workspace-shell-header">
			<div class="bcsend-workspace-brand">
				<span class="bcsend-workspace-mark" aria-hidden="true">B</span>
				<div>
					<span class="bcsend-workspace-kicker"><?php esc_html_e( 'Beacon Campaign Sender', 'beacon-campaign-sender' ); ?></span>
					<strong><?php esc_html_e( 'Campaign Workspace', 'beacon-campaign-sender' ); ?></strong>
				</div>
			</div>
			<nav class="bcsend-workspace-nav" aria-label="<?php esc_attr_e( 'Campaign workspace navigation', 'beacon-campaign-sender' ); ?>">
				<?php foreach ( $links as $slug => $link ) : ?>
					<a href="<?php echo esc_url( $link['url'] ); ?>" class="<?php echo $slug === $current ? 'is-active' : ''; ?>">
						<?php echo esc_html( $link['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="bcsend-workspace-account">
				<a href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'WP Admin', 'beacon-campaign-sender' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Profile', 'beacon-campaign-sender' ); ?></a>
				<a href="<?php echo esc_url( wp_logout_url( wp_login_url() ) ); ?>"><?php esc_html_e( 'Log Out', 'beacon-campaign-sender' ); ?></a>
			</div>
			<div id="bcsend-workspace-alerts" class="bcsend-workspace-alerts" aria-live="polite">
				<div class="bcsend-workspace-status <?php echo ! empty( $status['ok'] ) ? 'is-ok' : 'is-warning'; ?>">
					<span class="dashicons <?php echo ! empty( $status['ok'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
					<span><?php echo esc_html( $status['message'] ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Build a compact readiness status for the workspace header.
	 *
	 * @return array
	 */
	private function get_workspace_status() {
		if ( ! class_exists( 'Bcsend_Environment' ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Workspace ready', 'beacon-campaign-sender' ),
			);
		}

		$report = Bcsend_Environment::get_instance()->get_report();
		foreach ( $report as $check ) {
			if ( empty( $check['result'] ) && ! empty( $check['label'] ) ) {
				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: %s: environment check label */
						__( 'Needs attention: %s', 'beacon-campaign-sender' ),
						$check['label']
					),
				);
			}
		}

		return array(
			'ok'      => true,
			'message' => __( 'All campaign systems ready', 'beacon-campaign-sender' ),
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
		$admin_css_path = plugin_dir_path( __DIR__ ) . 'assets/css/bcsend-admin.css';
		$admin_js_path  = plugin_dir_path( __DIR__ ) . 'assets/js/bcsend-admin.js';
		$admin_css_ver  = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : $version;
		$admin_js_ver   = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : $version;

		// Global admin styles and scripts for all Beacon Campaign Sender pages.
		wp_enqueue_style(
			'bcsend-admin',
			$plugin_url . 'assets/css/bcsend-admin.css',
			array(),
			$admin_css_ver
		);

		wp_enqueue_script(
			'bcsend-admin',
			$plugin_url . 'assets/js/bcsend-admin.js',
			array( 'jquery' ),
			$admin_js_ver,
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
		$this->render_workspace_header();
		$controller = new Bcsend_Dashboard();
		$controller->render();
	}

	/**
	 * Render the Composer page.
	 *
	 * @since 1.0.0
	 */
	public function render_composer() {
		$this->render_workspace_header();
		$controller = new Bcsend_Composer();
		$controller->render();
	}

	/**
	 * Render the Campaign Queue page.
	 *
	 * @since 1.0.0
	 */
	public function render_queue() {
		$this->render_workspace_header();
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
