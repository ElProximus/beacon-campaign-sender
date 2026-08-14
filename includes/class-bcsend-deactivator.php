<?php
/**
 * Fired during plugin deactivation.
 *
 * Unschedules recurring jobs but leaves data intact so re-activation
 * picks up where it left off.
 *
 * @package Bcsend_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Deactivator
 */
class Bcsend_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @param bool $network_wide True when network-deactivated on multisite.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_single_site();
				restore_current_blog();
			}

			return;
		}

		self::deactivate_single_site();
	}

	/**
	 * Run the per-site deactivation cleanup for the current site.
	 *
	 * Action Scheduler's storage binds to the site that loaded it, so the
	 * as_* calls are skipped for switched sites (their pending bcsend
	 * actions fail harmlessly once the plugin is gone); the wp-cron
	 * fallback events are per-site options and clear correctly either way.
	 */
	public static function deactivate_single_site() {
		// Unschedule Action Scheduler recurring jobs if AS is available.
		if ( ( ! is_multisite() || ! ms_is_switched() ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'bcsend_campaign' );
			as_unschedule_all_actions( 'bcsend_push_batch' );
			as_unschedule_all_actions( 'bcsend_cleanup_logs' );
			as_unschedule_all_actions( 'bcsend_sync_segments' );
			as_unschedule_all_actions( Bcsend_Subscriber_Ingest::RETRY_HOOK );
			as_unschedule_all_actions( 'bcsend_standalone_push' );
			as_unschedule_all_actions( 'bcsend_standalone_push_batch' );
			as_unschedule_all_actions( 'bcsend_run_ai_job' );
		}

		// Clear wp_cron fallback events.
		wp_unschedule_hook( 'bcsend_campaign' ); // Scheduled with array( $campaign_id ); the no-arg clear matches nothing.
		wp_clear_scheduled_hook( 'bcsend_push_batch' );
		wp_clear_scheduled_hook( 'bcsend_cleanup_logs' );
		wp_clear_scheduled_hook( 'bcsend_sync_segments' );
		wp_clear_scheduled_hook( Bcsend_Subscriber_Ingest::RETRY_HOOK );
		wp_unschedule_hook( 'bcsend_standalone_push' ); // Scheduled with array( $push_id ); the no-arg clear matches nothing.
		wp_clear_scheduled_hook( 'bcsend_standalone_push_batch' );
		wp_unschedule_hook( 'bcsend_run_ai_job' ); // Same: single events are scheduled with the job ID argument.
		wp_unschedule_hook( 'bcsend_recover_openai_job' ); // Events carry args; wp_clear_scheduled_hook() with no args matches nothing.

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}
