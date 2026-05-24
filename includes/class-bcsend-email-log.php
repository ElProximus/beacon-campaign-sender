<?php
/**
 * Transactional email log model for Beacon Campaign Sender.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Email_Log
 *
 * Static utility methods for structured email log storage and retrieval.
 *
 * @since 2.5.0
 */
class Bcsend_Email_Log {

	/**
	 * Table name suffix.
	 *
	 * @var string
	 */
	const TABLE = 'bcsend_email_log';

	/**
	 * Email log detail level.
	 *
	 * @return string
	 */
	public static function get_detail_level() {
		$settings = get_option( 'bcsend_settings', array() );
		$level    = isset( $settings['email_log_detail_level'] ) ? $settings['email_log_detail_level'] : 'minimal';

		return in_array( $level, array( 'minimal', 'full' ), true ) ? $level : 'minimal';
	}

	/**
	 * Whether full email content logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_full_logging_enabled() {
		return 'full' === self::get_detail_level();
	}

	/**
	 * Prepare log data according to the configured privacy mode.
	 *
	 * @param array $data Raw log data.
	 * @return array
	 */
	public static function prepare_log_data( $data ) {
		if ( self::is_full_logging_enabled() ) {
			return $data;
		}

		$data['cc']          = null;
		$data['bcc']         = null;
		$data['body']        = null;
		$data['headers']     = null;
		$data['attachments'] = null;

		return $data;
	}

	/**
	 * Whether a specific row contains enough information for resend/preview.
	 *
	 * @param object|array $email Email log row.
	 * @return bool
	 */
	public static function supports_resend( $email ) {
		$body = is_array( $email ) ? ( isset( $email['body'] ) ? $email['body'] : '' ) : ( isset( $email->body ) ? $email->body : '' );

		return is_string( $body ) && '' !== $body;
	}

	/**
	 * Insert a structured email log row.
	 *
	 * @param array $data Row data.
	 * @return int|false
	 */
	public static function insert( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$defaults = array(
			'to_email'           => null,
			'cc'                 => null,
			'bcc'                => null,
			'subject'            => null,
			'body'               => null,
			'headers'            => null,
			'is_html'            => 0,
			'attachments'        => null,
			'from_name'          => null,
			'from_email'         => null,
			'status'             => 'sent',
			'brevo_message_id'   => null,
			'error_message'      => null,
			'resent_from_log_id' => null,
			'created_at'         => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );
		$row = self::prepare_log_data( $row );

		$inserted = $wpdb->insert(
			$table,
			$row,
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve a single email log row.
	 *
	 * @param int $id Email log ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id )
		);
	}

	/**
	 * Find the most recent matching email log row created after a known ID.
	 *
	 * @param string $to_email Recipient summary string.
	 * @param string $subject  Email subject.
	 * @param int    $after_id Only consider rows with an ID greater than this.
	 * @return object|null
	 */
	public static function find_recent_match( $to_email, $subject, $after_id = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id > %d AND to_email = %s AND subject = %s ORDER BY id DESC LIMIT 1",
				(int) $after_id,
				(string) $to_email,
				(string) $subject
			)
		);
	}

	/**
	 * Retrieve paginated email logs with optional filtering.
	 *
	 * @param string $status   Status filter.
	 * @param string $search   Search term.
	 * @param int    $page     Current page.
	 * @param int    $per_page Items per page.
	 * @return array
	 */
	public static function get_emails( $status = 'all', $search = '', $page = 1, $per_page = 30 ) {
		global $wpdb;

		$table    = $wpdb->prefix . self::TABLE;
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_parts = array( '1=1' );
		$params      = array();

		$valid_statuses = array( 'sent', 'failed', 'fallback_attempted' );
		if ( 'all' !== $status && in_array( $status, $valid_statuses, true ) ) {
			$where_parts[] = 'status = %s';
			$params[]      = $status;
		}

		$search = trim( $search );
		if ( '' !== $search ) {
			$like          = '%' . $wpdb->esc_like( $search ) . '%';
			$where_parts[] = '(to_email LIKE %s OR subject LIKE %s)';
			$params[]      = $like;
			$params[]      = $like;
		}

		$where = implode( ' AND ', $where_parts );

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$where}",
					$params
				)
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		}

		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$items        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$query_params
			)
		);
		$total_pages  = max( 1, (int) ceil( $total / $per_page ) );

		return array(
			'items'       => $items ? $items : array(),
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Delete email logs older than the given retention period.
	 *
	 * @param int $days Days to retain.
	 * @return int
	 */
	public static function delete_old( $days ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$days   = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);

		return $deleted ? (int) $deleted : 0;
	}
}
