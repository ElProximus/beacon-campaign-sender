<?php
/**
 * Subscribers admin screen for Beacon Campaign Sender.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Subscribers
 */
class Bcsend_Subscribers {

	/**
	 * Render the subscribers page.
	 *
	 * @return void
	 */
	public function render() {
		if ( isset( $_GET['subscriber_action'], $_GET['_wpnonce'] ) && 'retry' === $_GET['subscriber_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->handle_retry_action();
		}

		$status = isset( $_GET['subscriber_status'] ) ? sanitize_key( wp_unslash( $_GET['subscriber_status'] ) ) : 'all';
		$source = isset( $_GET['subscriber_source'] ) ? sanitize_key( wp_unslash( $_GET['subscriber_source'] ) ) : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$results = $this->get_subscribers( $status, $source, $paged );

		$rows         = $results['rows'];
		$total        = $results['total'];
		$total_pages  = $results['total_pages'];
		$status_value = $status;
		$source_value = $source;
		$sources      = $this->get_sources();

		include plugin_dir_path( __FILE__ ) . 'views/subscribers.php';
	}

	/**
	 * Fetch subscriber rows for display.
	 *
	 * @param string $status Status filter.
	 * @param string $source Source filter.
	 * @param int    $paged  Current page.
	 * @return array
	 */
	private function get_subscribers( $status, $source, $paged ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcsend_subscribers';
		$limit  = 50;
		$offset = ( $paged - 1 ) * $limit;
		$where  = array();
		$args   = array();

		if ( 'all' !== $status && in_array( $status, array( 'pending', 'pending_retry', 'confirmed', 'failed' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		if ( 'all' !== $source && '' !== $source ) {
			$where[] = 'source = %s';
			$args[]  = $source;
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = ! empty( $args ) ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : (int) $wpdb->get_var( $count_sql );

		$query_args  = array_merge( $args, array( $limit, $offset ) );
		$query_sql   = "SELECT * FROM {$table} {$where_sql} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";
		$rows        = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_args ), ARRAY_A );
		$total_pages = max( 1, (int) ceil( $total / $limit ) );

		return array(
			'rows'        => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Fetch distinct subscriber sources for the filter.
	 *
	 * @return array
	 */
	private function get_sources() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_subscribers';
		$rows  = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} ORDER BY source ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? array_filter( array_map( 'sanitize_key', $rows ) ) : array();
	}

	/**
	 * Retry a specific subscriber row.
	 *
	 * @return void
	 */
	private function handle_retry_action() {
		if ( ! current_user_can( 'manage_bcsend' ) ) {
			return;
		}

		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( $_GET['subscriber_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $subscriber_id ) {
			return;
		}

		check_admin_referer( 'bcsend_retry_subscriber_' . $subscriber_id );

		$success = Bcsend_Subscriber_Ingest::trigger_retry_now( $subscriber_id );

		add_settings_error(
			'bcsend_settings',
			'bcsend_subscriber_retry',
			$success ? __( 'Subscriber retry triggered.', 'beacon-campaign-sender' ) : __( 'Unable to retry that subscriber row.', 'beacon-campaign-sender' ),
			$success ? 'updated' : 'error'
		);
	}
}
