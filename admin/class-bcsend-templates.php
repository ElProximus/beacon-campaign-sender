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

		$table      = $wpdb->prefix . 'bcsend_templates';
		$default_id = (int) get_option( 'bcsend_default_template_id', 0 );

		// The default template is always listed first.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY ( id = %d ) DESC, created_at DESC",
				$default_id
			)
		);
	}
}
