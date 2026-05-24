<?php
/**
 * Composer controller for Beacon Campaign Sender.
 *
 * Handles the campaign composer page where users create and edit
 * campaigns, generate AI content, and schedule sends.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Composer
 *
 * @since 1.0.0
 */
class Bcsend_Composer {

	/**
	 * Render the composer page.
	 *
	 * Checks for an existing campaign_id in the request to load
	 * a campaign for editing, then includes the view.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$campaign_id     = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$template_id     = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
		$campaign        = null;
		$segments        = $this->get_segments();
		$settings        = Bcsend_Settings::get_settings();
		$brand_voice     = isset( $settings['brand_voice'] ) ? $settings['brand_voice'] : '';
		$env             = Bcsend_Environment::get_instance();
		$social_accounts = get_option( 'bcsend_zernio_accounts', array() );
		$social_posts    = array();
		$template        = null;

		if ( $campaign_id > 0 ) {
			$campaign     = $this->get_campaign( $campaign_id );
			$social_posts = $this->get_social_posts( $campaign_id );
		} elseif ( $template_id > 0 ) {
			$template = $this->get_template( $template_id );
		}

		include plugin_dir_path( __FILE__ ) . 'views/composer.php';
	}

	/**
	 * Get all audience segments for the dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of segment row objects.
	 */
	public function get_segments() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		return $wpdb->get_results( "SELECT id, name, query_type, contact_count FROM {$table} ORDER BY name ASC" );
	}

	/**
	 * Load a campaign from the database by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Campaign ID.
	 *
	 * @return object|null Campaign row object or null.
	 */
	public function get_campaign( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Load social child rows for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array
	 */
	private function get_social_posts( $campaign_id ) {
		return Bcsend_Social_Workflow::get_campaign_rows( $campaign_id, OBJECT );
	}

	/**
	 * Load a saved template from the database by ID.
	 *
	 * @param int $id Template ID.
	 * @return object|null
	 */
	private function get_template( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_templates';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}
}
