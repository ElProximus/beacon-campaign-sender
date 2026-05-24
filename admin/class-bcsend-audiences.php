<?php
/**
 * Audiences controller for Beacon Campaign Sender.
 *
 * Manages Brevo contact lists and smart segments for campaign targeting.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Audiences
 *
 * @since 1.0.0
 */
class Bcsend_Audiences {

	/**
	 * Render the audiences page.
	 *
	 * Fetches smart segments from the database and includes the view.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$segments = $this->get_segments();
		$env      = Bcsend_Environment::get_instance();

		include plugin_dir_path( __FILE__ ) . 'views/audiences.php';
	}

	/**
	 * Get all smart segments from the database.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of segment row objects.
	 */
	private function get_segments() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY name ASC"
		);
	}
}
