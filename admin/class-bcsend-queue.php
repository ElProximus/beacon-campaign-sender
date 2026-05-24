<?php
/**
 * Campaign Queue controller for Beacon Campaign Sender.
 *
 * Manages the campaign queue listing with filtering, pagination,
 * and calendar view support.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Queue
 *
 * @since 1.0.0
 */
class Bcsend_Queue {

	/**
	 * Number of campaigns per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Render the campaign queue page.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$results = $this->get_campaigns( $status, $paged );

		$campaigns   = $results['campaigns'];
		$total       = $results['total'];
		$total_pages = $results['total_pages'];

		// Get campaigns for calendar view (current month).
		$cal_month = isset( $_GET['cal_month'] ) ? absint( $_GET['cal_month'] ) : (int) gmdate( 'n' );
		$cal_year  = isset( $_GET['cal_year'] ) ? absint( $_GET['cal_year'] ) : (int) gmdate( 'Y' );

		$calendar_campaigns = $this->get_calendar_campaigns( $cal_month, $cal_year );

		include plugin_dir_path( __FILE__ ) . 'views/queue.php';
	}

	/**
	 * Get filtered and paginated campaigns.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Status filter (all, draft, approved, scheduled, etc.).
	 * @param int    $paged  Current page number.
	 *
	 * @return array Array with campaigns, total, and total_pages keys.
	 */
	public function get_campaigns( $status = 'all', $paged = 1 ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcsend_campaigns';
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$where = '';
		$args  = array();

		$valid_statuses = array( 'draft', 'approved', 'scheduled', 'sending', 'sent', 'failed', 'cancelled' );

		if ( 'all' !== $status && in_array( $status, $valid_statuses, true ) ) {
			$where  = 'WHERE status = %s';
			$args[] = $status;
		}

		// Total count.
		if ( ! empty( $args ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $args ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		// Fetch campaigns.
		$query = "SELECT c.*, s.name AS segment_name
			FROM {$table} c
			LEFT JOIN {$wpdb->prefix}bcsend_segments s ON c.segment_id = s.id
			{$where}
			ORDER BY c.scheduled_at DESC, c.created_at DESC
			LIMIT %d OFFSET %d";

		$query_args   = $args;
		$query_args[] = self::PER_PAGE;
		$query_args[] = $offset;

		$campaigns = $wpdb->get_results( $wpdb->prepare( $query, $query_args ) );

		return array(
			'campaigns'   => $campaigns ? $campaigns : array(),
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Get campaigns for a specific month for the calendar view.
	 *
	 * @since 1.0.0
	 *
	 * @param int $month Month number (1-12).
	 * @param int $year  Year number.
	 *
	 * @return array Associative array keyed by day number with arrays of campaigns.
	 */
	private function get_calendar_campaigns( $month, $year ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'bcsend_campaigns';
		$start_date = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$end_date   = gmdate( 'Y-m-t 23:59:59', strtotime( $start_date ) );

		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, status, scheduled_at FROM {$table}
				WHERE scheduled_at BETWEEN %s AND %s
				ORDER BY scheduled_at ASC",
				$start_date,
				$end_date
			)
		);

		$by_day = array();
		if ( $campaigns ) {
			foreach ( $campaigns as $campaign ) {
				$day = (int) gmdate( 'j', strtotime( $campaign->scheduled_at ) );
				if ( ! isset( $by_day[ $day ] ) ) {
					$by_day[ $day ] = array();
				}
				$by_day[ $day ][] = $campaign;
			}
		}

		return $by_day;
	}
}
