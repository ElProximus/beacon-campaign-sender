<?php
/**
 * Email log admin page controller.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Email_Log_Admin
 *
 * @since 2.5.0
 */
class Bcsend_Email_Log_Admin {

	/**
	 * Number of email logs per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 30;

	/**
	 * Render the email log admin page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'view_bcsend_logs' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'beacon-campaign-sender' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';

		switch ( $action ) {
			case 'detail':
				$this->render_detail();
				break;

			default:
				$this->render_list();
				break;
		}
	}

	/**
	 * Render the paginated email log list.
	 *
	 * @return void
	 */
	private function render_list() {
		$status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result      = Bcsend_Email_Log::get_emails( $status, $search, $paged, self::PER_PAGE );
		$emails      = $result['items'];
		$total       = $result['total'];
		$total_pages = $result['total_pages'];
		$view_mode   = 'list';

		include plugin_dir_path( __FILE__ ) . 'views/email-log.php';
	}

	/**
	 * Render the email log detail view.
	 *
	 * @return void
	 */
	private function render_detail() {
		$email_id  = isset( $_GET['email_id'] ) ? absint( $_GET['email_id'] ) : 0;
		$email     = $email_id ? Bcsend_Email_Log::get( $email_id ) : null;
		$view_mode = 'detail';

		include plugin_dir_path( __FILE__ ) . 'views/email-log.php';
	}
}
