<?php
/**
 * Uninstall Beacon Campaign Sender
 *
 * Fired when the plugin is uninstalled via the WordPress admin.
 * Removes all database tables, options, capabilities, transients,
 * and scheduled jobs created by the plugin.
 *
 * @package Bcsend_Plugin
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------
// 1. Drop custom database tables.
// ---------------------------------------------------------------------
$tables = array(
	$wpdb->prefix . 'bcsend_campaigns',
	$wpdb->prefix . 'bcsend_segments',
	$wpdb->prefix . 'bcsend_templates',
	$wpdb->prefix . 'bcsend_logs',
	$wpdb->prefix . 'bcsend_push_notifications',
	$wpdb->prefix . 'bcsend_push_history',
	$wpdb->prefix . 'bcsend_snippets',
	$wpdb->prefix . 'bcsend_email_log',
	$wpdb->prefix . 'bcsend_social_posts',
	$wpdb->prefix . 'bcsend_subscribers',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ---------------------------------------------------------------------
// 2. Delete plugin options.
// ---------------------------------------------------------------------
delete_option( 'bcsend_settings' );
delete_option( 'bcsend_db_version' );
delete_option( 'bcsend_zernio_accounts' );
delete_option( 'bcsend_zernio_profiles' );
delete_option( 'bcsend_zernio_profile_name' );
delete_option( 'bcsend_zernio_webhook_diagnostics' );
delete_option( 'bcsend_zernio_webhook_url' );

// ---------------------------------------------------------------------
// 3. Remove custom capabilities from roles and from individual users.
// ---------------------------------------------------------------------
$bcsend_caps = array(
	'manage_bcsend',
	'edit_bcsend_campaigns',
	'operate_bcsend_campaigns',
	'view_bcsend_logs',
);

foreach ( wp_roles()->roles as $role_slug => $role_details ) {
	$role = get_role( $role_slug );
	if ( ! $role ) {
		continue;
	}

	foreach ( $bcsend_caps as $cap ) {
		$role->remove_cap( $cap );
	}
}

// Strip the per-user campaign caps assigned via the Access settings tab.
$cap_key      = $wpdb->get_blog_prefix() . 'capabilities';
$like_clauses = array();
$query_args   = array( $cap_key );

foreach ( $bcsend_caps as $cap ) {
	$like_clauses[] = 'meta_value LIKE %s';
	$query_args[]   = '%' . $wpdb->esc_like( $cap ) . '%';
}

$user_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND (" . implode( ' OR ', $like_clauses ) . ')',
		$query_args
	)
);

foreach ( $user_ids as $user_id ) {
	$caps = get_user_meta( absint( $user_id ), $cap_key, true );
	if ( ! is_array( $caps ) ) {
		continue;
	}

	foreach ( $bcsend_caps as $cap ) {
		unset( $caps[ $cap ] );
	}

	update_user_meta( absint( $user_id ), $cap_key, $caps );
}

// ---------------------------------------------------------------------
// 4. Clear all transients with bcsend_ prefix.
// ---------------------------------------------------------------------
$transient_keys = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_bcsend\_%'
	    OR option_name LIKE '_transient_timeout_bcsend\_%'"
);

foreach ( $transient_keys as $key ) {
	// Strip the _transient_ or _transient_timeout_ prefix to get the transient name.
	$transient_name = str_replace( '_transient_timeout_', '', $key );
	$transient_name = str_replace( '_transient_', '', $transient_name );
	delete_transient( $transient_name );
}

// ---------------------------------------------------------------------
// 5. Unschedule Action Scheduler jobs (if AS available) or wp_cron.
// ---------------------------------------------------------------------
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'bcsend_campaign' );
	as_unschedule_all_actions( 'bcsend_push_batch' );
	as_unschedule_all_actions( 'bcsend_cleanup_logs' );
	as_unschedule_all_actions( 'bcsend_sync_segments' );
	as_unschedule_all_actions( 'bcsend_subscriber_retry_pending' );
	as_unschedule_all_actions( 'bcsend_standalone_push' );
	as_unschedule_all_actions( 'bcsend_standalone_push_batch' );
} else {
	wp_clear_scheduled_hook( 'bcsend_campaign' );
	wp_clear_scheduled_hook( 'bcsend_push_batch' );
	wp_clear_scheduled_hook( 'bcsend_cleanup_logs' );
	wp_clear_scheduled_hook( 'bcsend_sync_segments' );
	wp_clear_scheduled_hook( 'bcsend_subscriber_retry_pending' );
	wp_clear_scheduled_hook( 'bcsend_standalone_push' );
	wp_clear_scheduled_hook( 'bcsend_standalone_push_batch' );
}
