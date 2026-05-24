<?php
/**
 * Templates controller for Beacon Campaign Sender.
 *
 * Manages saved email templates that can be used as starting
 * points for new campaigns.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Templates
 *
 * @since 1.0.0
 */
class Bcsend_Templates {

	/**
	 * Render the templates page.
	 *
	 * Fetches all templates from the database and includes the view.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$templates = $this->get_templates();

		include plugin_dir_path( __FILE__ ) . 'views/templates.php';
	}

	/**
	 * Get all templates from the database.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of template row objects.
	 */
	private function get_templates() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_templates';

		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY created_at DESC"
		);
	}
}
