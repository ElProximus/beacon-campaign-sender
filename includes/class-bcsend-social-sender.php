<?php
/**
 * Social sender for Beacon Campaign Sender.
 *
 * Sends campaign social rows to Zernio and updates local status tracking.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Social_Sender
 */
class Bcsend_Social_Sender {

	/**
	 * Send social posts for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array
	 */
	public static function send_for_campaign( $campaign_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_social_posts';
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d AND status IN ('draft', 'pending', 'failed')",
				(int) $campaign_id
			)
		);

		if ( empty( $posts ) ) {
			return array(
				'sent'    => 0,
				'failed'  => 0,
				'skipped' => 0,
				'partial' => 0,
			);
		}

		$api = new Bcsend_Zernio_API();
		if ( ! $api->is_configured() ) {
			return array(
				'sent'    => 0,
				'failed'  => count( $posts ),
				'skipped' => 0,
				'partial' => 0,
			);
		}

		$sent             = 0;
		$failed           = 0;
		$skipped          = 0;
		$partial          = 0;
		$campaign_context = self::get_campaign_link_context( $campaign_id );
		$post_mode        = self::get_campaign_social_post_mode( $campaign_id );

		if ( 'single' === $post_mode ) {
			return self::send_single_post_for_campaign( $campaign_id, $posts, $api, $table, $campaign_context );
		}

		foreach ( $posts as $post ) {
			$media_items = ! empty( $post->media_items ) ? json_decode( $post->media_items, true ) : array();
			if ( ! is_array( $media_items ) ) {
				$media_items = array();
			}

			if ( empty( $media_items ) ) {
				$media_items = self::get_campaign_fallback_media_items( $campaign_id );
			}

			$payload = Bcsend_Zernio_API::build_post_payload(
				array(
					'accounts'      => array(
						array(
							'platform'   => $post->platform,
							'account_id' => $post->account_id,
						),
					),
					'content'       => Bcsend_Social_Workflow::build_publish_content(
						$post->content,
						isset( $post->link_mode ) ? $post->link_mode : 'none',
						Bcsend_Social_Workflow::resolve_link_url(
							isset( $post->link_mode ) ? $post->link_mode : 'none',
							isset( $post->link_url ) ? $post->link_url : '',
							$campaign_context
						)
					),
					'scheduled_for' => $post->scheduled_for,
					'media_items'   => $media_items,
				)
			);

			$validation = $api->validate_post_length(
				array(
					'text'     => isset( $payload['content'] ) ? (string) $payload['content'] : '',
					'platform' => $post->platform,
				)
			);

			if ( is_wp_error( $validation ) ) {
				$validation = $api->validate_post( $payload );
			}

			if ( is_wp_error( $validation ) ) {
				self::update_failed_row( $table, $post->id, $validation->get_error_message() );
				++$failed;
				continue;
			}

			$result = $api->create_post( $payload );

			if ( is_wp_error( $result ) ) {
				$error_data       = $result->get_error_data();
				$status_code      = is_array( $error_data ) && isset( $error_data['status_code'] ) ? (int) $error_data['status_code'] : 0;
				$response_body    = is_array( $error_data ) && isset( $error_data['response'] ) && is_array( $error_data['response'] ) ? $error_data['response'] : array();
				$existing_post_id = isset( $response_body['details']['existingPostId'] ) ? (string) $response_body['details']['existingPostId'] : '';

				// Zernio returned 409 because it already has this content in
				// flight (from a previous attempt that may have timed out
				// locally). The existingPostId links us to the original
				// Zernio post — adopt it so the webhook can reconcile the
				// final status. This is the recovery path for timed-out
				// creates, not a failure.
				if ( 409 === $status_code && ! empty( $existing_post_id ) ) {
					Bcsend_Logger::log(
						'social_send',
						'Zernio 409 duplicate — linking row to existing post',
						wp_json_encode(
							array(
								'campaign_id'    => (int) $campaign_id,
								'row_id'         => (int) $post->id,
								'existingPostId' => $existing_post_id,
							)
						)
					);

					$wpdb->update(
						$table,
						array(
							'status'         => 'publishing',
							'zernio_post_id' => $existing_post_id,
							'last_error'     => '',
						),
						array( 'id' => (int) $post->id ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);

					++$sent;
					continue;
				}

				// Capture enough context to correlate with a later webhook
				// event, since a timed-out create may still publish remotely
				// without us ever seeing the zernio_post_id.
				Bcsend_Logger::log(
					'social_send',
					'Zernio create_post failed — may still publish remotely',
					wp_json_encode(
						array(
							'campaign_id'    => (int) $campaign_id,
							'row_id'         => (int) $post->id,
							'platform'       => isset( $post->platform ) ? $post->platform : '',
							'account_id'     => isset( $post->account_id ) ? $post->account_id : '',
							'content_head'   => mb_substr( isset( $payload['content'] ) ? (string) $payload['content'] : '', 0, 120 ),
							'content_length' => mb_strlen( isset( $payload['content'] ) ? (string) $payload['content'] : '' ),
							'media_count'    => isset( $payload['mediaItems'] ) && is_array( $payload['mediaItems'] ) ? count( $payload['mediaItems'] ) : 0,
							'status_code'    => $status_code,
							'error'          => $result->get_error_message(),
							'error_code'     => $result->get_error_code(),
							'attempted_at'   => current_time( 'mysql', true ),
						)
					),
					'error'
				);

				self::update_failed_row( $table, $post->id, $result->get_error_message() );
				++$failed;
				continue;
			}

			Bcsend_Logger::log(
				'social_send',
				'Zernio create_post succeeded',
				wp_json_encode(
					array(
						'campaign_id'    => (int) $campaign_id,
						'row_ids'        => array( (int) $post->id ),
						'zernio_post_id' => isset( $result['post']['_id'] ) ? (string) $result['post']['_id'] : '',
						'status'         => isset( $result['post']['status'] ) ? (string) $result['post']['status'] : '',
						'account_count'  => 1,
						'mode'           => 'per_platform',
					)
				)
			);

			// Zernio always returns { post: { _id: "..." } }.
			$zernio_post_id = isset( $result['post']['_id'] )
				? (string) $result['post']['_id']
				: '';

			$new_status = self::map_remote_status( $result, ! empty( $post->scheduled_for ) );

			$update_data   = array(
				'status'         => $new_status,
				'zernio_post_id' => $zernio_post_id,
				'last_error'     => '',
			);
			$update_format = array( '%s', '%s', '%s' );

			if ( in_array( $new_status, array( 'published', 'sent' ), true ) ) {
				$update_data['published_at'] = current_time( 'mysql', true );
				$update_format[]             = '%s';
			}

			$wpdb->update(
				$table,
				$update_data,
				array( 'id' => $post->id ),
				$update_format,
				array( '%d' )
			);

			if ( 'failed' === $new_status ) {
				++$failed;
			} elseif ( 'partial' === $new_status ) {
				++$partial;
			} elseif ( 'scheduled' === $new_status || 'published' === $new_status || 'sent' === $new_status ) {
				++$sent;
			} else {
				++$skipped;
			}
		}

		return array(
			'sent'    => $sent,
			'failed'  => $failed,
			'skipped' => $skipped,
			'partial' => $partial,
		);
	}

	/**
	 * Send all selected social rows as one Zernio post.
	 *
	 * @param int               $campaign_id      Campaign ID.
	 * @param array<object>     $posts            Social rows.
	 * @param Bcsend_Zernio_API $api              Zernio API client.
	 * @param string            $table            Social table name.
	 * @param array             $campaign_context Link resolution context.
	 * @return array
	 */
	private static function send_single_post_for_campaign( $campaign_id, $posts, $api, $table, $campaign_context ) {
		global $wpdb;

		$first = reset( $posts );
		if ( false === $first ) {
			return array(
				'sent'    => 0,
				'failed'  => 0,
				'skipped' => 0,
				'partial' => 0,
			);
		}

		$media_items = ! empty( $first->media_items ) ? json_decode( $first->media_items, true ) : array();
		if ( ! is_array( $media_items ) ) {
			$media_items = array();
		}

		if ( empty( $media_items ) ) {
			$media_items = self::get_campaign_fallback_media_items( $campaign_id );
		}

		$accounts = array();
		$row_ids  = array();
		foreach ( $posts as $post ) {
			$accounts[] = array(
				'platform'   => $post->platform,
				'account_id' => $post->account_id,
			);
			$row_ids[]  = (int) $post->id;
		}

		$payload = Bcsend_Zernio_API::build_post_payload(
			array(
				'accounts'      => $accounts,
				'content'       => Bcsend_Social_Workflow::build_publish_content(
					$first->content,
					isset( $first->link_mode ) ? $first->link_mode : 'none',
					Bcsend_Social_Workflow::resolve_link_url(
						isset( $first->link_mode ) ? $first->link_mode : 'none',
						isset( $first->link_url ) ? $first->link_url : '',
						$campaign_context
					)
				),
				'scheduled_for' => $first->scheduled_for,
				'media_items'   => $media_items,
			)
		);

		$strictest_platform = self::get_strictest_platform( $accounts );
		$validation         = $api->validate_post_length(
			array(
				'text'     => isset( $payload['content'] ) ? (string) $payload['content'] : '',
				'platform' => $strictest_platform,
			)
		);

		if ( is_wp_error( $validation ) ) {
			$validation = $api->validate_post( $payload );
		}

		if ( is_wp_error( $validation ) ) {
			foreach ( $row_ids as $row_id ) {
				self::update_failed_row( $table, $row_id, $validation->get_error_message() );
			}
			return array(
				'sent'    => 0,
				'failed'  => count( $row_ids ),
				'skipped' => 0,
				'partial' => 0,
			);
		}

		$result = $api->create_post( $payload );

		if ( is_wp_error( $result ) ) {
			$error_data       = $result->get_error_data();
			$status_code      = is_array( $error_data ) && isset( $error_data['status_code'] ) ? (int) $error_data['status_code'] : 0;
			$response_body    = is_array( $error_data ) && isset( $error_data['response'] ) && is_array( $error_data['response'] ) ? $error_data['response'] : array();
			$existing_post_id = isset( $response_body['details']['existingPostId'] ) ? (string) $response_body['details']['existingPostId'] : '';

			if ( 409 === $status_code && ! empty( $existing_post_id ) ) {
				foreach ( $row_ids as $row_id ) {
					$wpdb->update(
						$table,
						array(
							'status'         => 'publishing',
							'zernio_post_id' => $existing_post_id,
							'last_error'     => '',
						),
						array( 'id' => $row_id ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);
				}

				Bcsend_Logger::log(
					'social_send',
					'Zernio 409 duplicate linked to grouped rows',
					wp_json_encode(
						array(
							'campaign_id'    => (int) $campaign_id,
							'row_ids'        => $row_ids,
							'existingPostId' => $existing_post_id,
							'mode'           => 'single',
						)
					)
				);

				return array(
					'sent'    => count( $row_ids ),
					'failed'  => 0,
					'skipped' => 0,
					'partial' => 0,
				);
			}

			Bcsend_Logger::log(
				'social_send',
				'Zernio grouped create_post failed',
				wp_json_encode(
					array(
						'campaign_id'    => (int) $campaign_id,
						'row_ids'        => $row_ids,
						'content_head'   => mb_substr( isset( $payload['content'] ) ? (string) $payload['content'] : '', 0, 120 ),
						'content_length' => mb_strlen( isset( $payload['content'] ) ? (string) $payload['content'] : '' ),
						'media_count'    => isset( $payload['mediaItems'] ) && is_array( $payload['mediaItems'] ) ? count( $payload['mediaItems'] ) : 0,
						'account_count'  => count( $accounts ),
						'status_code'    => $status_code,
						'error'          => $result->get_error_message(),
						'error_code'     => $result->get_error_code(),
						'attempted_at'   => current_time( 'mysql', true ),
						'mode'           => 'single',
					)
				),
				'error'
			);

			foreach ( $row_ids as $row_id ) {
				self::update_failed_row( $table, $row_id, $result->get_error_message() );
			}

			return array(
				'sent'    => 0,
				'failed'  => count( $row_ids ),
				'skipped' => 0,
				'partial' => 0,
			);
		}

		$zernio_post_id = isset( $result['post']['_id'] ) ? (string) $result['post']['_id'] : '';
		$new_status     = self::map_remote_status( $result, ! empty( $first->scheduled_for ) );
		$update_data    = array(
			'status'         => $new_status,
			'zernio_post_id' => $zernio_post_id,
			'last_error'     => '',
		);
		$update_format  = array( '%s', '%s', '%s' );

		if ( in_array( $new_status, array( 'published', 'sent' ), true ) ) {
			$update_data['published_at'] = current_time( 'mysql', true );
			$update_format[]             = '%s';
		}

		foreach ( $row_ids as $row_id ) {
			$wpdb->update( $table, $update_data, array( 'id' => $row_id ), $update_format, array( '%d' ) );
		}

		Bcsend_Logger::log(
			'social_send',
			'Zernio grouped create_post succeeded',
			wp_json_encode(
				array(
					'campaign_id'    => (int) $campaign_id,
					'row_ids'        => $row_ids,
					'zernio_post_id' => $zernio_post_id,
					'status'         => isset( $result['post']['status'] ) ? (string) $result['post']['status'] : '',
					'account_count'  => count( $accounts ),
					'mode'           => 'single',
				)
			)
		);

		if ( 'failed' === $new_status ) {
			return array(
				'sent'    => 0,
				'failed'  => count( $row_ids ),
				'skipped' => 0,
				'partial' => 0,
			);
		}

		if ( 'partial' === $new_status ) {
			return array(
				'sent'    => 0,
				'failed'  => 0,
				'skipped' => 0,
				'partial' => count( $row_ids ),
			);
		}

		if ( in_array( $new_status, array( 'scheduled', 'published', 'sent' ), true ) ) {
			return array(
				'sent'    => count( $row_ids ),
				'failed'  => 0,
				'skipped' => 0,
				'partial' => 0,
			);
		}

		return array(
			'sent'    => 0,
			'failed'  => 0,
			'skipped' => count( $row_ids ),
			'partial' => 0,
		);
	}

	/**
	 * Get the saved social post mode for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	private static function get_campaign_social_post_mode( $campaign_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_campaigns';
		$mode  = $wpdb->get_var(
			$wpdb->prepare( "SELECT social_post_mode FROM {$table} WHERE id = %d", (int) $campaign_id )
		);

		return in_array( $mode, array( 'single', 'per_platform' ), true ) ? $mode : 'single';
	}

	/**
	 * Find the selected platform with the smallest character limit.
	 *
	 * @param array $accounts Account target pairs.
	 * @return string
	 */
	private static function get_strictest_platform( $accounts ) {
		$strictest = '';
		$limit     = PHP_INT_MAX;

		foreach ( $accounts as $account ) {
			$platform = isset( $account['platform'] ) ? sanitize_key( $account['platform'] ) : '';
			if ( empty( $platform ) ) {
				continue;
			}

			$platform_limit = Bcsend_Zernio_API::get_platform_char_limit( $platform );
			if ( $platform_limit < $limit ) {
				$limit     = $platform_limit;
				$strictest = $platform;
			}
		}

		return $strictest ? $strictest : 'twitter';
	}

	/**
	 * Fall back to Content Library images when a social row has no saved media.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array
	 */
	private static function get_campaign_fallback_media_items( $campaign_id ) {
		global $wpdb;

		$campaigns_table = $wpdb->prefix . 'bcsend_campaigns';
		$content_library = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content_library FROM {$campaigns_table} WHERE id = %d",
				(int) $campaign_id
			)
		);

		if ( empty( $content_library ) ) {
			return array();
		}

		$decoded = json_decode( $content_library, true );
		if ( ! is_array( $decoded ) || empty( $decoded['images'] ) || ! is_array( $decoded['images'] ) ) {
			return array();
		}

		$media_items = array();
		foreach ( $decoded['images'] as $image ) {
			if ( empty( $image['url'] ) ) {
				continue;
			}

			$media_items[] = array(
				'type' => 'image',
				'url'  => esc_url_raw( $image['url'] ),
			);
		}

		return $media_items;
	}

	/**
	 * Get server-side link resolution context for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array
	 */
	private static function get_campaign_link_context( $campaign_id ) {
		global $wpdb;

		$campaigns_table = $wpdb->prefix . 'bcsend_campaigns';
		$campaign        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT product_id, content_library FROM {$campaigns_table} WHERE id = %d",
				(int) $campaign_id
			),
			ARRAY_A
		);

		return Bcsend_Social_Workflow::get_link_context(
			isset( $campaign['content_library'] ) ? $campaign['content_library'] : '',
			isset( $campaign['product_id'] ) ? (int) $campaign['product_id'] : 0
		);
	}

	/**
	 * Refresh tracked social rows from Zernio for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array
	 */
	public static function refresh_campaign_statuses( $campaign_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_social_posts';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d AND zernio_post_id IS NOT NULL AND zernio_post_id != ''",
				(int) $campaign_id
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$api = new Bcsend_Zernio_API();
		if ( ! $api->is_configured() ) {
			return array();
		}

		$updates = array();

		foreach ( $rows as $row ) {
			$remote = $api->get_post( $row->zernio_post_id );
			if ( is_wp_error( $remote ) ) {
				continue;
			}

			$status    = self::map_remote_status( $remote, ! empty( $row->scheduled_for ) );
			$updates[] = array(
				'id'     => (int) $row->id,
				'status' => $status,
			);

			$update_data   = array(
				'status'     => $status,
				'last_error' => '',
			);
			$update_format = array( '%s', '%s' );

			if ( in_array( $status, array( 'published', 'sent' ), true ) && empty( $row->published_at ) ) {
				$update_data['published_at'] = current_time( 'mysql', true );
				$update_format[]             = '%s';
			}

			$wpdb->update(
				$table,
				$update_data,
				array( 'id' => (int) $row->id ),
				$update_format,
				array( '%d' )
			);
		}

		self::rollup_campaign_status( $campaign_id );

		return $updates;
	}

	/**
	 * Roll up child social rows into campaign social_status.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	public static function rollup_campaign_status( $campaign_id ) {
		global $wpdb;

		$social_table    = $wpdb->prefix . 'bcsend_social_posts';
		$campaigns_table = $wpdb->prefix . 'bcsend_campaigns';
		$rows            = $wpdb->get_results(
			$wpdb->prepare( "SELECT status FROM {$social_table} WHERE campaign_id = %d", (int) $campaign_id )
		);

		if ( empty( $rows ) ) {
			$wpdb->update(
				$campaigns_table,
				array( 'social_status' => 'skipped' ),
				array( 'id' => (int) $campaign_id ),
				array( '%s' ),
				array( '%d' )
			);
			return 'skipped';
		}

		$statuses = wp_list_pluck( $rows, 'status' );
		$final    = 'scheduled';

		if ( array_intersect( array( 'failed', 'partial' ), $statuses ) ) {
			$final = in_array( 'partial', $statuses, true ) || ( in_array( 'failed', $statuses, true ) && count( array_unique( $statuses ) ) > 1 )
				? 'partial'
				: 'failed';
		} elseif ( array_intersect( array( 'sending', 'pending' ), $statuses ) ) {
			$final = 'sending';
		} elseif ( array_intersect( array( 'published', 'sent' ), $statuses ) ) {
			$final = 'sent';
		} elseif ( in_array( 'cancelled', $statuses, true ) ) {
			$final = 'cancelled';
		} elseif ( in_array( 'scheduled', $statuses, true ) ) {
			$final = 'scheduled';
		} else {
			$final = 'draft';
		}

		$wpdb->update(
			$campaigns_table,
			array( 'social_status' => $final ),
			array( 'id' => (int) $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $final;
	}

	/**
	 * Process an incoming webhook event payload.
	 *
	 * @param array $payload Decoded webhook payload.
	 * @return array
	 */
	public static function handle_webhook_event( $payload ) {
		global $wpdb;

		$event = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';

		// Zernio webhook payload: { event, post: { _id, status, platforms: [...] } }.
		$post_data = isset( $payload['post'] ) && is_array( $payload['post'] ) ? $payload['post'] : array();

		// Post ID is always at post._id.
		$post_id = isset( $post_data['_id'] ) ? (string) $post_data['_id'] : '';

		if ( empty( $post_id ) ) {
			return array(
				'updated' => 0,
				'reason'  => 'missing_post_id',
			);
		}

		$table = $wpdb->prefix . 'bcsend_social_posts';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE zernio_post_id = %s", $post_id )
		);

		if ( empty( $rows ) ) {
			// No local row stores this zernio_post_id. Likely a post we
			// created when the create_post call timed out locally, so we
			// never recorded the ID. Log the full payload so we can identify
			// matchable fields (accountId, content, createdAt) for future
			// reconciliation.
			Bcsend_Logger::log(
				'social_send',
				'Zernio webhook event for untracked post',
				wp_json_encode(
					array(
						'event'        => $event,
						'post_id'      => $post_id,
						'full_payload' => $payload,
					)
				),
				'warning'
			);

			return array(
				'updated' => 0,
				'reason'  => 'post_not_tracked',
				'post_id' => $post_id,
			);
		}

		// Matched at least one row — log for visibility.
		Bcsend_Logger::log(
			'social_send',
			'Zernio webhook event matched local row',
			wp_json_encode(
				array(
					'event'        => $event,
					'post_id'      => $post_id,
					'matched_rows' => count( $rows ),
				)
			)
		);

		$status  = self::map_event_to_status( $event, $post_data );
		$updated = 0;

		// Extract error details from first platform entry (Zernio error fields:
		// errorMessage, errorCategory, errorSource on platforms[]).
		$error = '';
		if ( isset( $post_data['platforms'][0] ) && is_array( $post_data['platforms'][0] ) ) {
			$plat = $post_data['platforms'][0];
			if ( ! empty( $plat['errorMessage'] ) ) {
				$parts = array( sanitize_text_field( (string) $plat['errorMessage'] ) );
				if ( ! empty( $plat['errorCategory'] ) ) {
					$parts[] = '[' . sanitize_key( $plat['errorCategory'] ) . ']';
				}
				if ( ! empty( $plat['errorSource'] ) ) {
					$parts[] = '(' . sanitize_key( $plat['errorSource'] ) . ')';
				}
				$error = implode( ' ', $parts );
			}
		}

		foreach ( $rows as $row ) {
			$update_data   = array(
				'status'     => $status,
				'last_error' => $error,
			);
			$update_format = array( '%s', '%s' );

			if ( in_array( $status, array( 'published', 'sent' ), true ) ) {
				$update_data['published_at'] = current_time( 'mysql', true );
				$update_format[]             = '%s';
			}

			$wpdb->update(
				$table,
				$update_data,
				array( 'id' => (int) $row->id ),
				$update_format,
				array( '%d' )
			);
			++$updated;

			if ( ! empty( $row->campaign_id ) ) {
				self::rollup_campaign_status( (int) $row->campaign_id );
			}
		}

		return array(
			'updated' => $updated,
			'status'  => $status,
			'post_id' => $post_id,
		);
	}

	/**
	 * Build a simple local test webhook payload for diagnostics.
	 *
	 * @param string $event   Event name.
	 * @param string $post_id Post ID.
	 * @return array
	 */
	public static function build_test_webhook_payload( $event = 'post.published', $post_id = 'test_post_123' ) {
		$status = 'published';
		if ( false !== strpos( $event, 'failed' ) ) {
			$status = 'failed';
		} elseif ( false !== strpos( $event, 'scheduled' ) ) {
			$status = 'scheduled';
		}

		return array(
			'event' => $event,
			'post'  => array(
				'_id'       => $post_id,
				'status'    => $status,
				'platforms' => array(
					array(
						'platform' => 'linkedin',
						'status'   => $status,
					),
				),
			),
		);
	}

	/**
	 * Mark a row as failed and log the error.
	 *
	 * @param string $table   Social posts table.
	 * @param int    $row_id  Local row ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function update_failed_row( $table, $row_id, $message ) {
		global $wpdb;

		$wpdb->update(
			$table,
			array(
				'status'     => 'failed',
				'last_error' => $message,
			),
			array( 'id' => $row_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log(
			'social_send',
			'Social post failed',
			array(
				'row_id' => (int) $row_id,
				'error'  => $message,
			),
			'error'
		);
	}

	/**
	 * Map a Zernio response to a local status.
	 *
	 * @param array $result       API result.
	 * @param bool  $is_scheduled Whether the post was scheduled.
	 * @return string
	 */
	private static function map_remote_status( $result, $is_scheduled ) {
		// Zernio always returns { post: { _id, status, platforms: [{ status }] } }.
		$post_status     = isset( $result['post']['status'] )
			? strtolower( (string) $result['post']['status'] )
			: '';
		$platform_status = isset( $result['post']['platforms'][0]['status'] )
			? strtolower( (string) $result['post']['platforms'][0]['status'] )
			: '';

		if ( in_array( $post_status, array( 'publishing', 'published', 'failed', 'partial', 'cancelled' ), true ) ) {
			return $post_status;
		}

		if ( 'scheduled' === $post_status || ( 'pending' === $platform_status && $is_scheduled ) ) {
			return 'scheduled';
		}

		if ( 'published' === $platform_status ) {
			return 'published';
		}

		if ( 'pending' === $platform_status ) {
			return 'pending';
		}

		// A draft response means Zernio accepted the record but did not create a
		// real scheduled/published handoff. Keep this non-terminal so it is not
		// treated as delivered.
		if ( 'draft' === $post_status ) {
			return $is_scheduled ? 'scheduled' : 'pending';
		}

		return $is_scheduled ? 'scheduled' : 'pending';
	}

	/**
	 * Map webhook event names to local statuses.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event payload.
	 * @return string
	 */
	private static function map_event_to_status( $event, $data ) {
		$event = strtolower( (string) $event );

		if ( false !== strpos( $event, 'published' ) ) {
			return 'published';
		}
		if ( false !== strpos( $event, 'failed' ) ) {
			return 'failed';
		}
		if ( false !== strpos( $event, 'partial' ) ) {
			return 'partial';
		}
		if ( false !== strpos( $event, 'cancelled' ) ) {
			return 'cancelled';
		}
		if ( false !== strpos( $event, 'scheduled' ) ) {
			return 'scheduled';
		}

		if ( isset( $data['platforms'][0]['status'] ) && is_scalar( $data['platforms'][0]['status'] ) ) {
			return strtolower( sanitize_text_field( (string) $data['platforms'][0]['status'] ) );
		}

		if ( isset( $data['status'] ) && is_scalar( $data['status'] ) ) {
			return strtolower( sanitize_text_field( (string) $data['status'] ) );
		}

		return 'pending';
	}
}
