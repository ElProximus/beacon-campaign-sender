<?php
/**
 * Beacon's own push device registry.
 *
 * Beacon historically borrowed the BuddyBoss App's device table for push
 * tokens, which limited push to BuddyBoss sites. This registry makes push
 * work for anyone with a Firebase project:
 *
 *  - web push subscribers (browsers) register here via the public REST route,
 *  - any custom mobile app can POST its FCM token to the same route,
 *  - site owners with existing apps can bulk-import tokens they already hold,
 *  - the BuddyBoss table remains a read-only fallback source where present.
 *
 * Tokens are deduplicated by SHA-256 hash; dead tokens reported by FCM
 * (UNREGISTERED) are marked stale and excluded from future sends.
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Live device registry on a custom table.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolations are the $wpdb->prefix table name only; values use placeholders.

/**
 * Class Bcsend_Devices
 */
class Bcsend_Devices {

	/**
	 * Platforms accepted from registrations and imports.
	 *
	 * @var array
	 */
	public static $platforms = array( 'web', 'android', 'ios', 'app' );

	/**
	 * Get the devices table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'bcsend_user_devices';
	}

	/**
	 * Register REST + AJAX endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_ajax_bcsend_import_push_tokens', array( __CLASS__, 'ajax_import_tokens' ) );
		add_action( 'wp_ajax_bcsend_purge_stale_devices', array( __CLASS__, 'ajax_purge_stale_devices' ) );
	}

	/**
	 * REST routes: public device registration/deregistration.
	 *
	 * The registration route is deliberately public - anonymous web visitors
	 * subscribe to push without an account, and external apps register their
	 * users' tokens. A token is an opaque write-only credential here: the
	 * route stores nothing else, responds identically whether the token was
	 * new or known, and never reads data back out.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'bcsend/v1',
			'/devices',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_register_device' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token'    => array(
							'required' => true,
							'type'     => 'string',
						),
						'platform' => array(
							'required' => false,
							'type'     => 'string',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'rest_deregister_device' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Validate an FCM token's shape.
	 *
	 * @param string $token Raw token.
	 * @return string Sanitized token, or empty string when invalid.
	 */
	private static function sanitize_token( $token ) {
		$token = trim( (string) $token );

		return preg_match( '/^[A-Za-z0-9_:\.\-]{20,4096}$/', $token ) ? $token : '';
	}

	/**
	 * REST: register (or refresh) a device token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_register_device( $request ) {
		$token    = self::sanitize_token( $request->get_param( 'token' ) );
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );

		if ( '' === $token ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		// External apps may authenticate with the site's app key, which lifts
		// the anonymous throttle (they legitimately register many devices).
		$app_key    = (string) $request->get_header( 'x-bcsend-app-key' );
		$is_trusted = is_user_logged_in() || self::verify_app_key( $app_key );

		if ( ! $is_trusted && ! self::allow_anonymous_registration() ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'rate_limited',
				),
				429
			);
		}

		if ( self::at_capacity() ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'capacity',
				),
				503
			);
		}

		if ( ! in_array( $platform, self::$platforms, true ) ) {
			$platform = 'app';
		}

		// Logged-in users (cookie/nonce or app auth) get their user attached
		// so role targeting can reach them; anonymous subscribers store 0.
		self::register( $token, $platform, get_current_user_id() );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * REST: deregister a device token (unsubscribe).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_deregister_device( $request ) {
		$token = self::sanitize_token( $request->get_param( 'token' ) );

		if ( '' !== $token ) {
			self::deregister( $token );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Verify an external app's registration key (timing-safe).
	 *
	 * The key is generated on demand and shown in Settings > Push so a custom
	 * mobile app can register device tokens without a WordPress session.
	 *
	 * @param string $provided Key from the X-Bcsend-App-Key header.
	 * @return bool
	 */
	public static function verify_app_key( $provided ) {
		$provided = trim( (string) $provided );
		$stored   = (string) get_option( 'bcsend_device_app_key', '' );

		return '' !== $provided && '' !== $stored && hash_equals( $stored, $provided );
	}

	/**
	 * Get (creating on first use) the external app registration key.
	 *
	 * @return string
	 */
	public static function get_app_key() {
		$key = (string) get_option( 'bcsend_device_app_key', '' );

		if ( '' === $key ) {
			$key = bin2hex( random_bytes( 24 ) );
			update_option( 'bcsend_device_app_key', $key, false );
		}

		return $key;
	}

	/**
	 * Per-IP throttle for anonymous (browser) registrations.
	 *
	 * Browsers register once per visitor and refresh occasionally, so a small
	 * allowance per window is generous while blocking scripted flooding.
	 *
	 * @return bool True when the request may proceed.
	 */
	private static function allow_anonymous_registration() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return true;
		}

		/**
		 * Filter the anonymous device-registration allowance per 10 minutes.
		 *
		 * @param int $limit Registrations allowed per IP per window.
		 */
		$limit = (int) apply_filters( 'bcsend_device_registration_limit', 10 );
		$key   = 'bcsend_devreg_' . md5( $ip );
		$hits  = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Whether the registry has hit its overall device ceiling.
	 *
	 * A hard stop so a compromised or buggy client cannot fill the table.
	 *
	 * @return bool
	 */
	private static function at_capacity() {
		global $wpdb;

		/**
		 * Filter the maximum number of stored devices.
		 *
		 * @param int $max Maximum device rows.
		 */
		$max = (int) apply_filters( 'bcsend_device_registry_max', 100000 );

		if ( $max <= 0 ) {
			return false;
		}

		$table = self::table();

		// Only ACTIVE rows count against the cap. Stale rows are dead weight
		// awaiting the reaper; counting them would let accumulated junk (or a
		// junk-token flood) permanently lock genuine subscribers out.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ) >= $max;
	}

	/**
	 * Delete stale device rows so they cannot accumulate forever.
	 *
	 * A row goes stale when FCM reports its token dead; nothing ever revives
	 * it (re-registration inserts/updates by token hash and resets status).
	 * Runs from the daily cleanup with an age threshold, and from the admin
	 * "remove stale devices" button with age 0 (all stale rows).
	 *
	 * @param int|null $days Minimum days since the device was last seen;
	 *                       null uses the filterable default (30), 0 deletes
	 *                       every stale row regardless of age.
	 * @return int Number of rows deleted.
	 */
	public static function purge_stale( $days = null ) {
		global $wpdb;

		if ( null === $days ) {
			/**
			 * Filter how many days a stale device row is kept before deletion.
			 *
			 * @param int $days Retention in days.
			 */
			$days = (int) apply_filters( 'bcsend_stale_device_retention_days', 30 );
		}

		$table = self::table();

		if ( $days <= 0 ) {
			return (int) $wpdb->query( "DELETE FROM {$table} WHERE status = 'stale'" );
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'stale' AND last_seen_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * AJAX: delete all stale device rows now (admin recovery button).
	 *
	 * @return void
	 */
	public static function ajax_purge_stale_devices() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Plugin capability registered in Bcsend_Activator.
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$deleted = self::purge_stale( 0 );

		wp_send_json_success(
			array(
				/* translators: %d: number of deleted device rows. */
				'message' => sprintf( __( 'Removed %d stale devices.', 'beacon-campaign-sender' ), $deleted ),
				'deleted' => $deleted,
				'stats'   => self::stats(),
			)
		);
	}

	/**
	 * Register or refresh a token.
	 *
	 * @param string $token    FCM token.
	 * @param string $platform Platform slug.
	 * @param int    $user_id  Owning user (0 for anonymous).
	 * @return bool
	 */
	public static function register( $token, $platform = 'app', $user_id = 0 ) {
		global $wpdb;

		$token = self::sanitize_token( $token );

		if ( '' === $token ) {
			return false;
		}

		$table = self::table();
		$hash  = hash( 'sha256', $token );
		$now   = current_time( 'mysql', true );

		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s", $hash ) );

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array(
					'user_id'      => absint( $user_id ),
					'platform'     => $platform,
					'status'       => 'active',
					'last_seen_at' => $now,
				),
				array( 'id' => (int) $existing_id ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return true;
		}

		return (bool) $wpdb->insert(
			$table,
			array(
				'user_id'      => absint( $user_id ),
				'token'        => $token,
				'token_hash'   => $hash,
				'platform'     => $platform,
				'status'       => 'active',
				'created_at'   => $now,
				'last_seen_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Remove a token (user unsubscribed).
	 *
	 * @param string $token FCM token.
	 * @return void
	 */
	public static function deregister( $token ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'token_hash' => hash( 'sha256', (string) $token ) ), array( '%s' ) );
	}

	/**
	 * Mark a token stale (FCM reported it unregistered/invalid).
	 *
	 * @param string $token FCM token.
	 * @return void
	 */
	public static function mark_stale( $token ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array( 'status' => 'stale' ),
			array( 'token_hash' => hash( 'sha256', (string) $token ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Active device rows for a set of users, shaped like the legacy
	 * BuddyBoss rows ({user_id, device_token}) plus platform.
	 *
	 * @param array $user_ids User IDs.
	 * @return array
	 */
	public static function tokens_for_users( $user_ids ) {
		global $wpdb;

		$user_ids = array_filter( array_map( 'absint', (array) $user_ids ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, token AS device_token, platform FROM {$table} WHERE status = 'active' AND user_id IN ({$placeholders})",
				...$user_ids
			)
		);
	}

	/**
	 * Every active device row, including anonymous web subscribers.
	 *
	 * @return array
	 */
	public static function all_tokens() {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results(
			"SELECT user_id, token AS device_token, platform FROM {$table} WHERE status = 'active'"
		);
	}

	/**
	 * User IDs that have at least one active device.
	 *
	 * @return array
	 */
	public static function user_ids_with_devices() {
		global $wpdb;

		$table = self::table();

		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} WHERE status = 'active' AND user_id > 0" ) );
	}

	/**
	 * Counts for the admin UI.
	 *
	 * @return array {active, web, app, stale}
	 */
	public static function counts() {
		global $wpdb;

		$table = self::table();

		return array(
			'active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ),
			'web'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND platform = 'web'" ),
			'app'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND platform != 'web'" ),
			'stale'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'stale'" ),
		);
	}

	/**
	 * Bulk-import tokens (one per line) for site owners whose existing app
	 * already stores tokens in their own backend.
	 *
	 * @param string $raw      Newline-separated tokens.
	 * @param string $platform Platform slug applied to the batch.
	 * @return array {imported, skipped}
	 */
	public static function import( $raw, $platform = 'app' ) {
		if ( ! in_array( $platform, self::$platforms, true ) ) {
			$platform = 'app';
		}

		$imported = 0;
		$skipped  = 0;

		foreach ( preg_split( '/[\r\n,]+/', (string) $raw ) as $line ) {
			$token = self::sanitize_token( $line );

			if ( '' === $token ) {
				if ( '' !== trim( (string) $line ) ) {
					++$skipped;
				}
				continue;
			}

			if ( self::register( $token, $platform, 0 ) ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * AJAX: bulk token import from the Push Notifications screen.
	 *
	 * @return void
	 */
	public static function ajax_import_tokens() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Plugin capability registered in Bcsend_Activator.
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ) );
		}

		$raw      = isset( $_POST['tokens'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tokens'] ) ) : '';
		$platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : 'app';

		if ( '' === trim( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Paste at least one device token.', 'beacon-campaign-sender' ) ) );
		}

		$result = self::import( $raw, $platform );

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: imported count, 2: skipped count. */
					__( 'Imported %1$d device tokens (%2$d skipped).', 'beacon-campaign-sender' ),
					$result['imported'],
					$result['skipped']
				),
				'imported' => $result['imported'],
				'skipped'  => $result['skipped'],
			)
		);
	}
}
