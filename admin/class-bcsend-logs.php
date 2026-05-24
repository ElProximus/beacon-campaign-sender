<?php
/**
 * Logs controller for Beacon Campaign Sender.
 *
 * Manages the log viewer page with filtering, pagination,
 * and log cleanup functionality.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Logs
 *
 * @since 1.0.0
 */
class Bcsend_Logs {

	/**
	 * Number of log entries per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Render the logs page.
	 *
	 * Reads filter parameters and fetches log entries for display.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$type   = isset( $_GET['log_type'] ) ? sanitize_text_field( wp_unslash( $_GET['log_type'] ) ) : 'all';
		$status = isset( $_GET['log_status'] ) ? sanitize_text_field( wp_unslash( $_GET['log_status'] ) ) : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$results     = $this->get_logs( $type, $status, $paged );
		$logs        = $results['logs'];
		$total       = $results['total'];
		$total_pages = $results['total_pages'];

		include plugin_dir_path( __FILE__ ) . 'views/logs.php';
	}

	/**
	 * Get filtered and paginated log entries.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type   Log type filter (all, api_call, push, generation, error).
	 * @param string $status Log status filter (all, success, error).
	 * @param int    $paged  Current page number.
	 *
	 * @return array Array with logs, total, and total_pages keys.
	 */
	private function get_logs( $type = 'all', $status = 'all', $paged = 1 ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcsend_logs';
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$where_parts = array();
		$args        = array();

		$valid_types = array( 'api_call', 'push', 'generation', 'error' );
		if ( 'all' !== $type && in_array( $type, $valid_types, true ) ) {
			$where_parts[] = 'type = %s';
			$args[]        = $type;
		}

		$valid_statuses = array( 'success', 'error' );
		if ( 'all' !== $status && in_array( $status, $valid_statuses, true ) ) {
			$where_parts[] = 'status = %s';
			$args[]        = $status;
		}

		$where = '';
		if ( ! empty( $where_parts ) ) {
			$where = 'WHERE ' . implode( ' AND ', $where_parts );
		}

		// Total count.
		$count_query = "SELECT COUNT(*) FROM {$table} {$where}";
		if ( ! empty( $args ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_query, $args ) );
		} else {
			$total = (int) $wpdb->get_var( $count_query );
		}

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		// Fetch logs.
		$query        = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_args   = $args;
		$query_args[] = self::PER_PAGE;
		$query_args[] = $offset;

		$logs = $wpdb->get_results( $wpdb->prepare( $query, $query_args ) );

		return array(
			'logs'        => $logs ? $logs : array(),
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}
}
