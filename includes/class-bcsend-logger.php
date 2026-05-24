<?php
/**
 * Logger for the Beacon Campaign Sender plugin.
 *
 * All log entries are stored in the bcsend_logs table.
 * Sensitive data is automatically redacted before storage.
 *
 * @package Bcsend_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Logger
 */
class Bcsend_Logger {

	/**
	 * Keys whose values must be redacted from logged payloads.
	 *
	 * @var string[]
	 */
	private static $secret_patterns = array(
		'api_key',
		'api-key',
		'x-api-key',
		'authorization',
		'password',
		'secret',
		'token',
		'private_key',
		'client_secret',
	);

	/**
	 * Insert a log entry.
	 *
	 * @param string              $type    Log type (api, email, push, ai, campaign, segment, template, etc.).
	 * @param string|array|object $message Human-readable message or structured log body.
	 * @param string|array|object $payload Optional raw data (JSON, array, object, or string). Secrets are redacted.
	 * @param string              $status  One of: success, error, info, warning. Defaults to 'success'.
	 * @return int|false The inserted row ID or false on failure.
	 */
	public static function log( $type, $message, $payload = '', $status = 'success' ) {
		global $wpdb;

		$table                                     = $wpdb->prefix . 'bcsend_logs';
		list( $safe_message, $normalized_payload ) = self::normalize_entry( $message, $payload );

		// Redact secrets from the payload before storing.
		$safe_payload = self::redact_secrets( $normalized_payload );

		// If the payload is an array or object after redaction, encode it.
		if ( is_array( $safe_payload ) || is_object( $safe_payload ) ) {
			$safe_payload = wp_json_encode( $safe_payload );
		}

		$result = $wpdb->insert(
			$table,
			array(
				'type'       => sanitize_text_field( $type ),
				'message'    => sanitize_text_field( $safe_message ),
				'payload'    => $safe_payload,
				'status'     => sanitize_text_field( $status ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Insert a structured log event with a human-readable message and optional context.
	 *
	 * @param string              $type    Log type.
	 * @param string              $message Human-readable message.
	 * @param string|array|object $context Optional structured context payload.
	 * @param string              $status  One of: success, error, info, warning.
	 * @return int|false The inserted row ID or false on failure.
	 */
	public static function event( $type, $message, $context = array(), $status = 'success' ) {
		return self::log( $type, $message, $context, $status );
	}

	/**
	 * Normalize mixed log message/payload combinations into a string message and mixed payload.
	 *
	 * Some call sites pass a structured array as the second argument, intending it
	 * to be the primary payload. Keep those entries useful instead of degrading the
	 * message into the literal string "Array".
	 *
	 * @param string|array|object $message Message or structured payload.
	 * @param string|array|object $payload Payload value.
	 * @return array{0:string,1:mixed}
	 */
	private static function normalize_entry( $message, $payload ) {
		if ( is_array( $message ) || is_object( $message ) ) {
			if ( '' === $payload || null === $payload || ( is_array( $payload ) && empty( $payload ) ) ) {
				return array( 'Structured log entry', $message );
			}

			return array(
				'Structured log entry',
				array(
					'message' => $message,
					'payload' => $payload,
				),
			);
		}

		return array(
			is_string( $message ) ? $message : strval( $message ),
			$payload,
		);
	}

	/**
	 * Retrieve log entries with pagination and optional filters.
	 *
	 * @param array $args {
	 *     Optional. Arguments for querying logs.
	 *
	 *     @type string $type     Filter by log type.
	 *     @type string $status   Filter by status.
	 *     @type int    $page     Page number (1-based). Default 1.
	 *     @type int    $per_page Items per page. Default 50.
	 * }
	 * @return array {
	 *     @type array $items Array of log row objects.
	 *     @type int   $total Total matching rows.
	 *     @type int   $page  Current page.
	 *     @type int   $pages Total pages.
	 * }
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_logs';

		$defaults = array(
			'type'     => '',
			'status'   => '',
			'page'     => 1,
			'per_page' => 50,
		);

		$args     = wp_parse_args( $args, $defaults );
		$page     = max( 1, intval( $args['page'] ) );
		$per_page = max( 1, min( 200, intval( $args['per_page'] ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['type'] ) ) {
			$where   .= ' AND type = %s';
			$params[] = sanitize_text_field( $args['type'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_text_field( $args['status'] );
		}

		// Count query.
		if ( ! empty( $params ) ) {
			$total = intval(
				$wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				)
			);
		} else {
			$total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Data query.
		$all_params = array_merge( $params, array( $per_page, $offset ) );
		$items      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$all_params
			),
			ARRAY_A
		);

		return array(
			'items'    => $items ? $items : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Delete log entries older than the given number of days.
	 *
	 * @param int $days Number of days to retain.
	 * @return int Number of rows deleted.
	 */
	public static function delete_old_logs( $days = 90 ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcsend_logs';
		$days   = max( 1, intval( $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $deleted ? intval( $deleted ) : 0;
	}

	/**
	 * Truncate the logs table.
	 *
	 * @return bool True on success.
	 */
	public static function clear_logs() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_logs';

		return false !== $wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Recursively redact secret values from data.
	 *
	 * Handles:
	 * - PHP arrays / objects
	 * - JSON strings (decoded, walked, re-encoded)
	 * - Plain strings that look like JSON
	 *
	 * @param mixed $data The data to redact.
	 * @return mixed Data with secret values replaced by '[REDACTED]'.
	 */
	private static function redact_secrets( $data ) {
		// Null, bool, int, float — pass through.
		if ( ! is_string( $data ) && ! is_array( $data ) && ! is_object( $data ) ) {
			return $data;
		}

		// If the payload is a JSON string, decode → walk → re-encode.
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );

			if ( is_array( $decoded ) ) {
				$redacted = self::walk_redact( $decoded );
				return wp_json_encode( $redacted );
			}

			// Plain string — nothing to redact at the key level.
			return $data;
		}

		// Object → cast to array, walk, cast back.
		if ( is_object( $data ) ) {
			$arr      = (array) $data;
			$redacted = self::walk_redact( $arr );
			return (object) $redacted;
		}

		// Array.
		return self::walk_redact( $data );
	}

	/**
	 * Recursively walk an array and redact values whose keys match secret patterns.
	 *
	 * @param array $data The array to walk.
	 * @return array Redacted array.
	 */
	private static function walk_redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => &$value ) {
			// Check if this key matches a secret pattern (case-insensitive).
			$lower_key = strtolower( (string) $key );

			foreach ( self::$secret_patterns as $pattern ) {
				if ( false !== strpos( $lower_key, $pattern ) ) {
					$value = '[REDACTED]';
					continue 2; // Skip to next key — already redacted.
				}
			}

			// Recurse into nested arrays / objects.
			if ( is_array( $value ) ) {
				$value = self::walk_redact( $value );
			} elseif ( is_object( $value ) ) {
				$value = (object) self::walk_redact( (array) $value );
			} elseif ( is_string( $value ) ) {
				// Check if nested value is a JSON string.
				$nested = json_decode( $value, true );
				if ( is_array( $nested ) ) {
					$value = wp_json_encode( self::walk_redact( $nested ) );
				}
			}
		}
		unset( $value );

		return $data;
	}
}
