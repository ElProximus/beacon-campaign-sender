<?php
/**
 * System Test controller for Beacon Campaign Sender.
 *
 * Provides a hidden admin page for verifying database tables,
 * API connections, push notifications, and content generation.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Test
 *
 * @since 1.0.0
 */
class Bcsend_Test {

	/**
	 * Render the system tests page.
	 *
	 * Gathers environment report data and includes the view.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$env         = Bcsend_Environment::get_instance();
		$env_report  = $env->get_report();
		$admin_email = get_option( 'admin_email', '' );

		include plugin_dir_path( __FILE__ ) . 'views/test.php';
	}
}
