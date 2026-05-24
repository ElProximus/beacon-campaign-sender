<?php
/**
 * Analytics controller for Beacon Campaign Sender.
 *
 * Fetches campaign performance data from Brevo and the local
 * database for display in charts and tables.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Analytics
 *
 * @since 1.0.0
 */
class Bcsend_Analytics {

	/**
	 * Render the analytics page.
	 *
	 * Gathers analytics data and includes the view template.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		// Allow manual cache refresh via button click.
		$refresh = isset( $_GET['refresh'] ) ? sanitize_text_field( wp_unslash( $_GET['refresh'] ) ) : '';
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( '1' === $refresh && wp_verify_nonce( $nonce, 'bcsend_refresh_analytics' ) ) {
			delete_transient( $this->get_cache_key() );
		}

		$data = $this->get_analytics_data();

		$total_sent        = $data['total_sent'];
		$avg_open_rate     = $data['avg_open_rate'];
		$avg_click_rate    = $data['avg_click_rate'];
		$total_push        = $data['total_push'];
		$top_campaigns     = $data['top_campaigns'];
		$daily_stats       = $data['daily_stats'];
		$audience_growth   = $data['audience_growth'];
		$push_per_campaign = $data['push_per_campaign'];

		include plugin_dir_path( __FILE__ ) . 'views/analytics.php';
	}

	/**
	 * Get analytics data with caching.
	 *
	 * Uses a 1-hour transient cache to reduce API load.
	 *
	 * @since 1.0.0
	 *
	 * @return array Analytics data array.
	 */
	public function get_analytics_data() {
		$cache_key = $this->get_cache_key();
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table     = $wpdb->prefix . 'bcsend_campaigns';
		$log_table = $wpdb->prefix . 'bcsend_logs';

		// Total sent campaigns.
		$total_sent = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'sent' )
		);

		// Aggregate stats from Brevo.
		$avg_open_rate  = 0;
		$avg_click_rate = 0;
		$total_push     = 0;

		$sent_campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, subject, scheduled_at, sent_at, brevo_campaign_id,
					COALESCE(sent_at, scheduled_at) AS activity_at
				FROM {$table}
				WHERE status = %s
				ORDER BY activity_at DESC
				LIMIT 50",
				'sent'
			)
		);

		$brevo             = new Bcsend_Brevo_API();
		$open_rates        = array();
		$click_rates       = array();
		$top_campaigns     = array();
		$push_per_campaign = array();
		$push_totals       = array();

		$push_logs = $wpdb->get_results(
			"SELECT payload FROM {$log_table} WHERE type = 'push_batch'"
		);

		foreach ( $push_logs as $log ) {
			$context = json_decode( $log->payload, true );

			if ( ! is_array( $context ) || empty( $context['campaign_id'] ) ) {
				continue;
			}

			$campaign_id = (int) $context['campaign_id'];
			$sent_count  = isset( $context['sent'] ) ? (int) $context['sent'] : 0;

			if ( ! isset( $push_totals[ $campaign_id ] ) ) {
				$push_totals[ $campaign_id ] = 0;
			}

			$push_totals[ $campaign_id ] += $sent_count;
		}

		if ( $sent_campaigns ) {
			foreach ( $sent_campaigns as $campaign ) {
				$opens     = 0;
				$clicks    = 0;
				$unsubs    = 0;
				$open_pct  = 0;
				$click_pct = 0;

				if ( $brevo->is_configured() && ! empty( $campaign->brevo_campaign_id ) ) {
					$stats = $brevo->get_campaign_stats( (int) $campaign->brevo_campaign_id );
					if ( ! is_wp_error( $stats ) && is_array( $stats ) ) {
						$extracted = Bcsend_Brevo_API::extract_campaign_stats( $stats );

						$open_pct  = $extracted['open_rate'] !== null ? $extracted['open_rate'] : 0;
						$click_pct = $extracted['click_rate'] !== null ? $extracted['click_rate'] : 0;
						$opens     = $extracted['unique_opens'];
						$clicks    = $extracted['unique_clicks'];
						$unsubs    = $extracted['unsubscriptions'];

						$open_rates[]  = $open_pct;
						$click_rates[] = $click_pct;
					}
				}

				$push_count  = isset( $push_totals[ (int) $campaign->id ] ) ? (int) $push_totals[ (int) $campaign->id ] : 0;
				$total_push += $push_count;

				$top_campaigns[] = array(
					'name'         => $campaign->name,
					'sent_date'    => $campaign->activity_at,
					'opens'        => $opens,
					'clicks'       => $clicks,
					'unsubscribes' => $unsubs,
				);

				$push_per_campaign[] = array(
					'name'  => $campaign->name,
					'count' => $push_count,
				);
			}
		}

		if ( ! empty( $open_rates ) ) {
			$avg_open_rate = array_sum( $open_rates ) / count( $open_rates );
		}
		if ( ! empty( $click_rates ) ) {
			$avg_click_rate = array_sum( $click_rates ) / count( $click_rates );
		}

		// Limit top campaigns to best 10 by opens.
		usort(
			$top_campaigns,
			function ( $a, $b ) {
				return $b['opens'] - $a['opens'];
			}
		);
		$top_campaigns = array_slice( $top_campaigns, 0, 10 );

		// Limit push chart to last 10.
		$push_per_campaign = array_slice( $push_per_campaign, 0, 10 );

		// Daily stats for the last 30 days (from local DB).
		$daily_stats     = array();
		$thirty_days_ago = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		$daily_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(COALESCE(sent_at, scheduled_at)) as send_date, COUNT(*) as campaign_count
				FROM {$table}
				WHERE status = %s AND COALESCE(sent_at, scheduled_at) >= %s
				GROUP BY DATE(COALESCE(sent_at, scheduled_at))
				ORDER BY send_date ASC",
				'sent',
				$thirty_days_ago
			)
		);

		if ( $daily_raw ) {
			foreach ( $daily_raw as $row ) {
				$daily_stats[] = array(
					'date'  => $row->send_date,
					'count' => (int) $row->campaign_count,
				);
			}
		}

		// Audience growth (monthly subscriber totals from segments table).
		$audience_growth = array();
		$segment_table   = $wpdb->prefix . 'bcsend_segments';

		$monthly_raw = $wpdb->get_results(
			"SELECT DATE_FORMAT(last_synced, '%Y-%m') as month, SUM(contact_count) as total_contacts
			FROM {$segment_table}
			WHERE last_synced IS NOT NULL
			GROUP BY DATE_FORMAT(last_synced, '%Y-%m')
			ORDER BY month ASC
			LIMIT 12"
		);

		if ( $monthly_raw ) {
			foreach ( $monthly_raw as $row ) {
				$audience_growth[] = array(
					'month' => $row->month,
					'total' => (int) $row->total_contacts,
				);
			}
		}

		$data = array(
			'total_sent'        => $total_sent,
			'avg_open_rate'     => $avg_open_rate,
			'avg_click_rate'    => $avg_click_rate,
			'total_push'        => $total_push,
			'top_campaigns'     => $top_campaigns,
			'daily_stats'       => $daily_stats,
			'audience_growth'   => $audience_growth,
			'push_per_campaign' => $push_per_campaign,
		);

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Build a cache key that rolls when campaign or push log activity changes.
	 *
	 * @return string
	 */
	private function get_cache_key() {
		global $wpdb;

		$campaign_table = $wpdb->prefix . 'bcsend_campaigns';
		$log_table      = $wpdb->prefix . 'bcsend_logs';

		$campaign_stamp = (string) $wpdb->get_var(
			"SELECT MAX(GREATEST(IFNULL(UNIX_TIMESTAMP(sent_at), 0), IFNULL(UNIX_TIMESTAMP(scheduled_at), 0), IFNULL(UNIX_TIMESTAMP(created_at), 0))) FROM {$campaign_table}"
		);
		$log_stamp      = (string) $wpdb->get_var(
			"SELECT MAX(IFNULL(UNIX_TIMESTAMP(created_at), 0)) FROM {$log_table} WHERE type = 'push_batch'"
		);

		return 'bcsend_analytics_data_' . md5( $campaign_stamp . '|' . $log_stamp );
	}
}
