<?php
/**
 * Subscriber ingest service for Beacon Campaign Sender.
 *
 * Stores subscriber consent evidence locally, writes contacts to Brevo,
 * and retries transient failures through Action Scheduler. This ledger is
 * an audit trail of what Beacon Campaign Sender attempted, not a live mirror of the
 * current Brevo contact state.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Subscriber_Ingest
 */
class Bcsend_Subscriber_Ingest {

	/**
	 * Retry action hook name.
	 *
	 * @var string
	 */
	const RETRY_HOOK = 'bcsend_subscriber_retry_pending';

	/**
	 * Maximum retry attempts before a row is marked failed.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 10;

	/**
	 * Deduplication window in seconds for repeated submissions.
	 *
	 * @var int
	 */
	const DEDUPE_WINDOW = 60;

	/**
	 * Register a subscriber from any source.
	 *
	 * @param array $data Subscriber payload.
	 * @return array
	 */
	public static function register( $data ) {
		$email = self::normalize_email( isset( $data['email'] ) ? $data['email'] : '' );

		if ( empty( $email ) ) {
			return array(
				'success'          => false,
				'status'           => 'failed',
				'email'            => '',
				'brevo_contact_id' => null,
				'subscriber_id'    => 0,
				'list_ids'         => array(),
				'reason'           => 'invalid_email',
				'deduplicated'     => false,
			);
		}

		$source = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : '';

		if ( empty( $source ) ) {
			return array(
				'success'          => false,
				'status'           => 'failed',
				'email'            => $email,
				'brevo_contact_id' => null,
				'subscriber_id'    => 0,
				'list_ids'         => array(),
				'reason'           => 'missing_source',
				'deduplicated'     => false,
			);
		}

		$payload  = array(
			'email'        => $email,
			'first_name'   => isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '',
			'last_name'    => isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '',
			'source'       => $source,
			'consent_text' => isset( $data['consent_text'] ) ? wp_strip_all_tags( (string) $data['consent_text'] ) : '',
			'ip_address'   => isset( $data['ip_address'] ) ? sanitize_text_field( (string) $data['ip_address'] ) : '',
			'user_agent'   => isset( $data['user_agent'] ) ? sanitize_text_field( (string) $data['user_agent'] ) : '',
			'metadata'     => isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? self::sanitize_metadata( $data['metadata'] ) : array(),
		);
		$list_ids = self::resolve_list_ids( isset( $data['list_ids'] ) ? $data['list_ids'] : array() );

		$duplicate = self::find_recent_duplicate( $email, $source );
		if ( $duplicate ) {
			return self::format_result_from_row( $duplicate, true );
		}

		$subscriber_id = self::insert_pending_row( $payload, $list_ids );

		if ( ! $subscriber_id ) {
			return array(
				'success'          => false,
				'status'           => 'failed',
				'email'            => $email,
				'brevo_contact_id' => null,
				'subscriber_id'    => 0,
				'list_ids'         => $list_ids,
				'reason'           => 'storage_failed',
				'deduplicated'     => false,
			);
		}

		return self::send_to_brevo( $subscriber_id, $payload, $list_ids, 0 );
	}

	/**
	 * Retry pending subscriber rows.
	 *
	 * @return void
	 */
	public static function retry_pending() {
		$rows = self::load_retry_batch( 50 );

		foreach ( $rows as $row ) {
			$payload = self::decode_row_payload( $row );

			if ( empty( $payload['email'] ) || empty( $payload['source'] ) ) {
				self::update_row_failed( (int) $row['id'], 'missing_retry_payload', array() );
				continue;
			}

			self::send_to_brevo(
				(int) $row['id'],
				$payload,
				isset( $payload['list_ids'] ) ? $payload['list_ids'] : array(),
				(int) $row['retry_count']
			);
		}
	}

	/**
	 * Trigger an immediate retry for a specific subscriber row.
	 *
	 * @param int $subscriber_id Subscriber row ID.
	 * @return bool
	 */
	public static function trigger_retry_now( $subscriber_id ) {
		global $wpdb;

		$table = self::get_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $subscriber_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return false;
		}

		$payload = self::decode_row_payload( $row );

		if ( empty( $payload['email'] ) || empty( $payload['source'] ) ) {
			return false;
		}

		self::send_to_brevo(
			(int) $row['id'],
			$payload,
			isset( $payload['list_ids'] ) ? $payload['list_ids'] : array(),
			(int) $row['retry_count']
		);

		return true;
	}

	/**
	 * Return subscriber table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'bcsend_subscribers';
	}

	/**
	 * Normalize an email address.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	private static function normalize_email( $email ) {
		$email = strtolower( sanitize_email( (string) $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Sanitize arbitrary subscriber metadata.
	 *
	 * @param array $metadata Raw metadata.
	 * @return array
	 */
	private static function sanitize_metadata( $metadata ) {
		$sanitized = array();

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				continue;
			}

			$sanitized[ $key ] = wp_json_encode( $value );
		}

		return $sanitized;
	}

	/**
	 * Resolve target subscriber list IDs.
	 *
	 * @param mixed $list_ids Optional list IDs override.
	 * @return array
	 */
	private static function resolve_list_ids( $list_ids = array() ) {
		if ( ! empty( $list_ids ) && is_array( $list_ids ) ) {
			$resolved = array_values( array_unique( array_filter( array_map( 'intval', $list_ids ) ) ) );
			if ( ! empty( $resolved ) ) {
				return $resolved;
			}
		}

		return self::get_default_list_ids();
	}

	/**
	 * Get default list IDs from plugin settings.
	 *
	 * @return array
	 */
	private static function get_default_list_ids() {
		$settings = get_option( 'bcsend_settings', array() );
		$list_ids = isset( $settings['default_subscriber_lists'] ) ? $settings['default_subscriber_lists'] : array( 14 );

		if ( is_string( $list_ids ) ) {
			$list_ids = preg_split( '/\s*,\s*/', trim( $list_ids ) );
		}

		if ( ! is_array( $list_ids ) ) {
			return array( 14 );
		}

		$list_ids = array_values( array_unique( array_filter( array_map( 'intval', $list_ids ) ) ) );

		return ! empty( $list_ids ) ? $list_ids : array( 14 );
	}

	/**
	 * Find a recent duplicate submission.
	 *
	 * @param string $email  Email address.
	 * @param string $source Source slug.
	 * @return array|null
	 */
	private static function find_recent_duplicate( $email, $source ) {
		global $wpdb;

		$table  = self::get_table_name();
		$cutoff = current_datetime();
		$cutoff = $cutoff->modify( '-' . self::DEDUPE_WINDOW . ' seconds' );
		$cutoff = $cutoff->format( 'Y-m-d H:i:s' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE email = %s AND source = %s AND submitted_at >= %s ORDER BY id DESC LIMIT 1",
				$email,
				$source,
				$cutoff
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert a pending subscriber row.
	 *
	 * @param array $payload Subscriber payload.
	 * @param array $list_ids Target list IDs.
	 * @return int
	 */
	private static function insert_pending_row( $payload, $list_ids ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::get_table_name(),
			array(
				'email'         => $payload['email'],
				'first_name'    => $payload['first_name'],
				'last_name'     => $payload['last_name'],
				'source'        => $payload['source'],
				'consent_text'  => $payload['consent_text'],
				'ip_address'    => $payload['ip_address'],
				'user_agent'    => $payload['user_agent'],
				'list_ids_json' => wp_json_encode( $list_ids ),
				'status'        => 'pending',
				'submitted_at'  => current_time( 'mysql' ),
				'metadata_json' => wp_json_encode( $payload['metadata'] ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return 0;
		}

		self::log_event(
			'subscriber_ingest',
			'Subscriber ingest queued',
			array(
				'subscriber_id' => (int) $wpdb->insert_id,
				'email'         => $payload['email'],
				'source'        => $payload['source'],
				'list_ids'      => $list_ids,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Send a subscriber payload to Brevo and persist the outcome.
	 *
	 * @param int   $subscriber_id Subscriber row ID.
	 * @param array $payload       Subscriber payload.
	 * @param array $list_ids      Target list IDs.
	 * @param int   $retry_count   Current retry count.
	 * @return array
	 */
	private static function send_to_brevo( $subscriber_id, $payload, $list_ids, $retry_count ) {
		$brevo = new Bcsend_Brevo_API();

		if ( ! $brevo->is_configured() ) {
			self::schedule_retry( $subscriber_id, $retry_count, 'Brevo API key is not configured.', array() );
			return self::format_result(
				$subscriber_id,
				$payload['email'],
				$list_ids,
				'pending',
				null,
				'not_configured',
				false
			);
		}

		$response = $brevo->create_contact( $payload['email'], self::build_attributes( $payload ), $list_ids );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			self::schedule_retry( $subscriber_id, $retry_count, $response->get_error_message(), is_array( $error_data ) ? $error_data : array() );

			return self::format_result(
				$subscriber_id,
				$payload['email'],
				$list_ids,
				'pending',
				null,
				$response->get_error_code(),
				false
			);
		}

		self::update_row_confirmed( $subscriber_id, $response );
		$result = self::format_result(
			$subscriber_id,
			$payload['email'],
			$list_ids,
			'confirmed',
			isset( $response['id'] ) ? (int) $response['id'] : null,
			null,
			false
		);

		do_action( 'bcsend_subscriber_registered', $payload['email'], $payload['source'], $result, $payload );

		return $result;
	}

	/**
	 * Build Brevo contact attributes.
	 *
	 * @param array $payload Subscriber payload.
	 * @return array
	 */
	private static function build_attributes( $payload ) {
		$attributes = array(
			'SOURCE' => $payload['source'],
		);

		if ( '' !== $payload['first_name'] ) {
			$attributes['FIRSTNAME'] = $payload['first_name'];
		}

		if ( '' !== $payload['last_name'] ) {
			$attributes['LASTNAME'] = $payload['last_name'];
		}

		return $attributes;
	}

	/**
	 * Update a row to confirmed after a successful Brevo write.
	 *
	 * @param int   $subscriber_id Subscriber row ID.
	 * @param array $response      Brevo response.
	 * @return void
	 */
	private static function update_row_confirmed( $subscriber_id, $response ) {
		global $wpdb;

		$payload = array(
			'contact_id' => isset( $response['id'] ) ? (int) $response['id'] : null,
			'created'    => isset( $response['id'] ) && (int) $response['id'] > 0,
		);

		$wpdb->update(
			self::get_table_name(),
			array(
				'status'              => 'confirmed',
				'brevo_contact_id'    => isset( $response['id'] ) ? (int) $response['id'] : null,
				'brevo_response_json' => substr( wp_json_encode( $payload ), 0, 500 ),
				'confirmed_at'        => current_time( 'mysql' ),
				'next_retry_at'       => null,
			),
			array( 'id' => (int) $subscriber_id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::log_event(
			'subscriber_confirmed',
			'Subscriber confirmed in Brevo',
			array(
				'subscriber_id'    => (int) $subscriber_id,
				'brevo_contact_id' => isset( $response['id'] ) ? (int) $response['id'] : null,
			)
		);
	}

	/**
	 * Schedule a retry or mark a row failed.
	 *
	 * @param int    $subscriber_id Subscriber row ID.
	 * @param int    $retry_count   Current retry count.
	 * @param string $message       Error message.
	 * @param array  $error_data    Error data.
	 * @return void
	 */
	private static function schedule_retry( $subscriber_id, $retry_count, $message, $error_data ) {
		$next_retry_count = $retry_count + 1;

		if ( $next_retry_count >= self::MAX_RETRIES ) {
			self::update_row_failed( $subscriber_id, $message, $error_data );
			return;
		}

		self::update_row_pending_retry( $subscriber_id, $next_retry_count, $message, $error_data );
	}

	/**
	 * Update a row to pending_retry.
	 *
	 * @param int    $subscriber_id   Subscriber row ID.
	 * @param int    $next_retry_count Retry count to store.
	 * @param string $message         Error message.
	 * @param array  $error_data      Error data.
	 * @return void
	 */
	private static function update_row_pending_retry( $subscriber_id, $next_retry_count, $message, $error_data ) {
		global $wpdb;

		$next_retry_at = self::calculate_next_retry_at( $next_retry_count );
		$stored_error  = substr(
			wp_json_encode(
				array(
					'message' => $message,
					'data'    => $error_data,
				)
			),
			0,
			10000
		);

		$wpdb->update(
			self::get_table_name(),
			array(
				'status'              => 'pending_retry',
				'retry_count'         => (int) $next_retry_count,
				'next_retry_at'       => $next_retry_at,
				'brevo_response_json' => $stored_error,
			),
			array( 'id' => (int) $subscriber_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		self::log_event(
			'subscriber_retry',
			'Subscriber retry scheduled',
			array(
				'subscriber_id' => (int) $subscriber_id,
				'retry_count'   => (int) $next_retry_count,
				'next_retry_at' => $next_retry_at,
				'message'       => $message,
			),
			'warning'
		);
	}

	/**
	 * Update a row to failed after retry exhaustion or malformed payload.
	 *
	 * @param int    $subscriber_id Subscriber row ID.
	 * @param string $message       Error message.
	 * @param array  $error_data    Error data.
	 * @return void
	 */
	private static function update_row_failed( $subscriber_id, $message, $error_data ) {
		global $wpdb;

		$stored_error = substr(
			wp_json_encode(
				array(
					'message' => $message,
					'data'    => $error_data,
				)
			),
			0,
			10000
		);

		$wpdb->update(
			self::get_table_name(),
			array(
				'status'              => 'failed',
				'next_retry_at'       => null,
				'brevo_response_json' => $stored_error,
			),
			array( 'id' => (int) $subscriber_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::log_event(
			'subscriber_failed',
			'Subscriber marked failed',
			array(
				'subscriber_id' => (int) $subscriber_id,
				'message'       => $message,
			),
			'error'
		);

		$row = self::get_row( $subscriber_id );
		if ( is_array( $row ) ) {
			do_action(
				'bcsend_subscriber_failed',
				isset( $row['email'] ) ? $row['email'] : '',
				isset( $row['source'] ) ? $row['source'] : '',
				self::format_result_from_row( $row, false ),
				self::decode_row_payload( $row )
			);
		}
	}

	/**
	 * Load the next batch of retryable rows.
	 *
	 * @param int $limit Batch size.
	 * @return array
	 */
	private static function load_retry_batch( $limit ) {
		global $wpdb;

		$table = self::get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND next_retry_at <= %s AND retry_count < %d ORDER BY next_retry_at ASC LIMIT %d",
				'pending_retry',
				current_time( 'mysql' ),
				self::MAX_RETRIES,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Decode persisted retry payload from a row.
	 *
	 * @param array $row Subscriber row.
	 * @return array
	 */
	private static function decode_row_payload( $row ) {
		$metadata = array();
		$list_ids = array();

		if ( ! empty( $row['metadata_json'] ) ) {
			$decoded  = json_decode( $row['metadata_json'], true );
			$metadata = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! empty( $row['list_ids_json'] ) ) {
			$decoded_lists = json_decode( $row['list_ids_json'], true );
			$list_ids      = is_array( $decoded_lists ) ? array_values( array_map( 'intval', $decoded_lists ) ) : array();
		}

		return array(
			'email'        => isset( $row['email'] ) ? $row['email'] : '',
			'first_name'   => isset( $row['first_name'] ) ? $row['first_name'] : '',
			'last_name'    => isset( $row['last_name'] ) ? $row['last_name'] : '',
			'source'       => isset( $row['source'] ) ? $row['source'] : '',
			'consent_text' => isset( $row['consent_text'] ) ? $row['consent_text'] : '',
			'ip_address'   => isset( $row['ip_address'] ) ? $row['ip_address'] : '',
			'user_agent'   => isset( $row['user_agent'] ) ? $row['user_agent'] : '',
			'metadata'     => $metadata,
			'list_ids'     => $list_ids,
		);
	}

	/**
	 * Calculate next retry timestamp.
	 *
	 * @param int $retry_count Retry count.
	 * @return string
	 */
	private static function calculate_next_retry_at( $retry_count ) {
		$minutes    = max( 5, (int) $retry_count * (int) $retry_count * 5 );
		$next_retry = current_datetime()->modify( '+' . $minutes . ' minutes' );
		return $next_retry->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Fetch a row by ID.
	 *
	 * @param int $subscriber_id Subscriber row ID.
	 * @return array|null
	 */
	private static function get_row( $subscriber_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::get_table_name() . ' WHERE id = %d', (int) $subscriber_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Format a result structure from a subscriber row.
	 *
	 * @param array $row          Subscriber row.
	 * @param bool  $deduplicated Whether result was deduplicated.
	 * @return array
	 */
	private static function format_result_from_row( $row, $deduplicated ) {
		$list_ids = array();

		if ( ! empty( $row['list_ids_json'] ) ) {
			$decoded = json_decode( $row['list_ids_json'], true );
			if ( is_array( $decoded ) ) {
				$list_ids = array_values( array_map( 'intval', $decoded ) );
			}
		}

		return self::format_result(
			(int) $row['id'],
			isset( $row['email'] ) ? $row['email'] : '',
			$list_ids,
			isset( $row['status'] ) ? $row['status'] : 'pending',
			isset( $row['brevo_contact_id'] ) ? (int) $row['brevo_contact_id'] : null,
			null,
			$deduplicated
		);
	}

	/**
	 * Format the public register() result shape.
	 *
	 * @param int         $subscriber_id Subscriber row ID.
	 * @param string      $email         Email address.
	 * @param array       $list_ids      List IDs.
	 * @param string      $status        Internal status.
	 * @param int|null    $contact_id    Brevo contact ID.
	 * @param string|null $reason        Failure reason.
	 * @param bool        $deduplicated  Whether an existing row was reused.
	 * @return array
	 */
	private static function format_result( $subscriber_id, $email, $list_ids, $status, $contact_id, $reason, $deduplicated ) {
		return array(
			'success'          => 'failed' !== $status,
			'status'           => in_array( $status, array( 'confirmed', 'pending_retry' ), true ) ? ( 'confirmed' === $status ? 'confirmed' : 'pending' ) : $status,
			'email'            => $email,
			'brevo_contact_id' => null !== $contact_id ? (int) $contact_id : null,
			'subscriber_id'    => (int) $subscriber_id,
			'list_ids'         => $list_ids,
			'reason'           => $reason,
			'deduplicated'     => (bool) $deduplicated,
		);
	}

	/**
	 * Write a subscriber log entry.
	 *
	 * @param string $type    Log type.
	 * @param string $message Message.
	 * @param array  $payload Payload.
	 * @param string $status  Status.
	 * @return void
	 */
	private static function log_event( $type, $message, $payload, $status = 'success' ) {
		Bcsend_Logger::log( $type, $message, $payload, $status );
	}
}
