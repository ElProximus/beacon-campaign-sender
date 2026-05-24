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
	 */
	public static function deactivate() {
		// Unschedule Action Scheduler recurring jobs if AS is available.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'bcsend_campaign' );
			as_unschedule_all_actions( 'bcsend_push_batch' );
			as_unschedule_all_actions( 'bcsend_cleanup_logs' );
			as_unschedule_all_actions( 'bcsend_sync_segments' );
			as_unschedule_all_actions( Bcsend_Subscriber_Ingest::RETRY_HOOK );
			as_unschedule_all_actions( 'bcsend_standalone_push' );
			as_unschedule_all_actions( 'bcsend_standalone_push_batch' );
		}

		// Clear wp_cron fallback events.
		wp_clear_scheduled_hook( 'bcsend_campaign' );
		wp_clear_scheduled_hook( 'bcsend_push_batch' );
		wp_clear_scheduled_hook( 'bcsend_cleanup_logs' );
		wp_clear_scheduled_hook( 'bcsend_sync_segments' );
		wp_clear_scheduled_hook( Bcsend_Subscriber_Ingest::RETRY_HOOK );
		wp_clear_scheduled_hook( 'bcsend_standalone_push' );
		wp_clear_scheduled_hook( 'bcsend_standalone_push_batch' );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}
