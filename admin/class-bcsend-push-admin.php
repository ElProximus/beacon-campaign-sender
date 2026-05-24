<?php
/**
 * Push Notifications admin page controller.
 *
 * Routes to list, compose, or detail views based on URL parameters.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Push_Admin
 *
 * @since 2.2.0
 */
class Bcsend_Push_Admin {

	/**
	 * Render the push notifications admin page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'beacon-campaign-sender' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';

		switch ( $action ) {
			case 'new':
				$this->render_compose();
				break;

			case 'detail':
				$this->render_detail();
				break;

			default:
				$this->render_list();
				break;
		}
	}

	/**
	 * Render the push notifications list view.
	 *
	 * @return void
	 */
	private function render_list() {
		$status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result      = Bcsend_Push_Manager::get_pushes( $status, $paged );
		$pushes      = $result['items'];
		$total       = $result['total'];
		$total_pages = $result['total_pages'];

		include plugin_dir_path( __FILE__ ) . 'views/push-list.php';
	}

	/**
	 * Render the push notification composer.
	 *
	 * @return void
	 */
	private function render_compose() {
		$roles     = wp_roles()->get_names();
		$timezones = timezone_identifiers_list();
		$wp_tz     = wp_timezone_string();

		include plugin_dir_path( __FILE__ ) . 'views/push-compose.php';
	}

	/**
	 * Render the push notification detail/history view.
	 *
	 * @return void
	 */
	private function render_detail() {
		$push_id = isset( $_GET['push_id'] ) ? absint( $_GET['push_id'] ) : 0;
		$push    = $push_id ? Bcsend_Push_Manager::get_push( $push_id ) : null;

		if ( ! $push ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Push notification not found.', 'beacon-campaign-sender' ) . '</p></div></div>';
			return;
		}

		$paged   = isset( $_GET['hist_page'] ) ? max( 1, absint( $_GET['hist_page'] ) ) : 1;
		$history = Bcsend_Push_Manager::get_history( $push_id, $paged );

		include plugin_dir_path( __FILE__ ) . 'views/push-detail.php';
	}
}
