<?php
/**
 * Dashboard controller for Beacon Campaign Sender.
 *
 * Fetches summary data including scheduled campaigns, recent sends,
 * segment counts, and environment status for the admin dashboard.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Dashboard
 *
 * @since 1.0.0
 */
class Bcsend_Dashboard {

	/**
	 * Render the dashboard page.
	 *
	 * Gathers all dashboard data and includes the view template.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$next_campaign    = $this->get_next_scheduled_campaign();
		$recent_campaigns = $this->get_recent_campaigns();
		$segment_stats    = $this->get_segment_stats();
		$env              = Bcsend_Environment::get_instance();
		$env_report       = $env->get_report();

		include plugin_dir_path( __FILE__ ) . 'views/dashboard.php';
	}

	/**
	 * Get the next scheduled campaign.
	 *
	 * @since 1.0.0
	 *
	 * @return object|null Campaign row object or null if none scheduled.
	 */
	private function get_next_scheduled_campaign() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY scheduled_at ASC LIMIT 1",
				'scheduled'
			)
		);
	}

	/**
	 * Get recent sent campaigns with Brevo statistics.
	 *
	 * Uses a 30-minute transient cache to avoid excessive API calls.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of campaign objects with stats.
	 */
	private function get_recent_campaigns() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';

		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, COALESCE(sent_at, scheduled_at) AS activity_at
				FROM {$table}
				WHERE status = %s
				ORDER BY activity_at DESC
				LIMIT 5",
				'sent'
			)
		);

		if ( empty( $campaigns ) ) {
			return array();
		}

		$cache_key = $this->get_recent_campaign_cache_key();
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			// Merge cached stats into campaign objects.
			foreach ( $campaigns as &$campaign ) {
				$cid = (int) $campaign->id;
				if ( isset( $cached[ $cid ] ) ) {
					$campaign->open_rate  = $cached[ $cid ]['open_rate'];
					$campaign->click_rate = $cached[ $cid ]['click_rate'];
				} else {
					$campaign->open_rate  = null;
					$campaign->click_rate = null;
				}
			}
			return $campaigns;
		}

		// Fetch stats from Brevo for each campaign.
		$brevo      = new Bcsend_Brevo_API();
		$stats_data = array();

		foreach ( $campaigns as &$campaign ) {
			$campaign->open_rate  = null;
			$campaign->click_rate = null;

			if ( ! empty( $campaign->brevo_campaign_id ) && $brevo->is_configured() ) {
				$stats = $brevo->get_campaign_stats( (int) $campaign->brevo_campaign_id );
				if ( ! is_wp_error( $stats ) && is_array( $stats ) ) {
					$extracted = Bcsend_Brevo_API::extract_campaign_stats( $stats );

					$campaign->open_rate  = $extracted['open_rate'];
					$campaign->click_rate = $extracted['click_rate'];

					$stats_data[ (int) $campaign->id ] = array(
						'open_rate'  => $campaign->open_rate,
						'click_rate' => $campaign->click_rate,
					);
				}
			}
		}

		set_transient( $cache_key, $stats_data, 30 * MINUTE_IN_SECONDS );

		return $campaigns;
	}

	/**
	 * Build a cache key that rolls when the set of recent sent campaigns changes.
	 *
	 * @return string
	 */
	private function get_recent_campaign_cache_key() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';
		$stamp = (string) $wpdb->get_var(
			"SELECT MAX(GREATEST(IFNULL(UNIX_TIMESTAMP(sent_at), 0), IFNULL(UNIX_TIMESTAMP(scheduled_at), 0), IFNULL(UNIX_TIMESTAMP(created_at), 0))) FROM {$table} WHERE status = 'sent'"
		);

		return 'bcsend_recent_campaign_stats_' . md5( $stamp );
	}

	/**
	 * Get smart segment statistics.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array with 'count' and 'last_sync' keys.
	 */
	private function get_segment_stats() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$last_sync = $wpdb->get_var( "SELECT MAX(last_synced) FROM {$table}" );

		return array(
			'count'     => $count,
			'last_sync' => $last_sync,
		);
	}
}
