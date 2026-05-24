<?php
/**
 * Shared social workflow helpers for Beacon Campaign Sender.
 *
 * Centralizes per-platform rule definitions, transport decoding,
 * server-side link resolution, validation, and draft row syncing.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Social_Workflow
 */
class Bcsend_Social_Workflow {

	/**
	 * Get canonical separator used when appending social link text.
	 *
	 * @return string
	 */
	public static function get_link_separator() {
		return "\n\n";
	}

	/**
	 * Get supported social link modes.
	 *
	 * @return array
	 */
	public static function get_supported_link_modes() {
		return array( 'none', 'product', 'homepage', 'custom', 'link_in_bio' );
	}

	/**
	 * Get server-owned social platform config for JS and PHP consumers.
	 *
	 * @return array
	 */
	public static function get_platform_rules() {
		$platforms = Bcsend_Zernio_API::get_supported_platforms();

		foreach ( $platforms as $slug => $meta ) {
			$platforms[ $slug ] = array(
				'label'         => isset( $meta['label'] ) ? $meta['label'] : ucfirst( $slug ),
				'maxChars'      => isset( $meta['max_chars'] ) ? (int) $meta['max_chars'] : 500,
				'requiresMedia' => 'instagram' === $slug,
			);
		}

		return array(
			'platforms'     => $platforms,
			'linkModes'     => self::get_supported_link_modes(),
			'linkSeparator' => self::get_link_separator(),
			'linkInBioText' => 'Link in bio',
		);
	}

	/**
	 * Get platform metadata keyed by platform slug.
	 *
	 * @return array
	 */
	public static function get_platform_metadata() {
		$rules = self::get_platform_rules();

		return isset( $rules['platforms'] ) && is_array( $rules['platforms'] ) ? $rules['platforms'] : array();
	}

	/**
	 * Normalize social textarea content before sanitizing and storing it.
	 *
	 * @param mixed $content Raw social content.
	 * @return string
	 */
	public static function normalize_post_content( $content ) {
		$content = is_string( $content ) ? $content : '';
		$content = str_replace( array( "\\r\\n", "\\n", "\\r" ), array( "\n", "\n", "\n" ), $content );
		$content = preg_replace( "/\n{3,}/", "\n\n", $content );
		$content = trim( $content );

		// Guard against malformed JS object coercion being persisted as a string.
		if ( '[object Object]' === $content ) {
			return '';
		}

		return $content;
	}

	/**
	 * Normalize and validate social media items.
	 *
	 * @param mixed $items Raw media items.
	 * @return array
	 */
	public static function normalize_media_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '';
			if ( empty( $url ) ) {
				continue;
			}

			$normalized[] = array(
				'id'    => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
				'type'  => isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'image',
				'url'   => $url,
				'alt'   => isset( $item['alt'] ) ? sanitize_text_field( $item['alt'] ) : '',
				'title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'thumb' => isset( $item['thumb'] ) ? esc_url_raw( $item['thumb'] ) : $url,
			);
		}

		return $normalized;
	}

	/**
	 * Decode a JSON object/array transport field.
	 *
	 * @param string $raw     Raw JSON string.
	 * @param mixed  $default Default value when invalid.
	 * @return mixed
	 */
	public static function decode_json_field( $raw, $default ) {
		$decoded = json_decode( $raw, true );

		return is_null( $decoded ) && JSON_ERROR_NONE !== json_last_error() ? $default : $decoded;
	}

	/**
	 * Prepare social draft entries from transport JSON.
	 *
	 * @param string $social_posts_raw       JSON object keyed by platform.
	 * @param string $account_ids_raw        JSON object keyed by platform.
	 * @param string $social_media_items_raw JSON object keyed by platform.
	 * @param string $social_link_modes_raw  JSON object keyed by platform.
	 * @param string $social_link_urls_raw   JSON object keyed by platform.
	 * @param string $social_link_labels_raw JSON object keyed by platform.
	 * @param string $social_platforms_raw   JSON array of selected platforms.
	 * @return array
	 */
	public static function prepare_transport_entries( $social_posts_raw, $account_ids_raw, $social_media_items_raw, $social_link_modes_raw, $social_link_urls_raw, $social_link_labels_raw, $social_platforms_raw ) {
		$social_posts       = self::decode_json_field( $social_posts_raw, array() );
		$account_ids        = self::decode_json_field( $account_ids_raw, array() );
		$social_media_items = self::decode_json_field( $social_media_items_raw, array() );
		$social_link_modes  = self::decode_json_field( $social_link_modes_raw, array() );
		$social_link_urls   = self::decode_json_field( $social_link_urls_raw, array() );
		$social_link_labels = self::decode_json_field( $social_link_labels_raw, array() );
		$social_platforms   = self::decode_json_field( $social_platforms_raw, array() );

		$social_posts       = is_array( $social_posts ) ? $social_posts : array();
		$account_ids        = is_array( $account_ids ) ? $account_ids : array();
		$social_media_items = is_array( $social_media_items ) ? $social_media_items : array();
		$social_link_modes  = is_array( $social_link_modes ) ? $social_link_modes : array();
		$social_link_urls   = is_array( $social_link_urls ) ? $social_link_urls : array();
		$social_link_labels = is_array( $social_link_labels ) ? $social_link_labels : array();
		$social_platforms   = is_array( $social_platforms ) ? $social_platforms : array();

		if ( empty( $social_platforms ) ) {
			$social_platforms = array_unique(
				array_merge(
					array_keys( $social_posts ),
					array_keys( $account_ids ),
					array_keys( $social_media_items ),
					array_keys( $social_link_modes ),
					array_keys( $social_link_urls )
				)
			);
		}

		$entries = array();
		foreach ( $social_platforms as $platform ) {
			$platform = sanitize_key( $platform );
			if ( empty( $platform ) ) {
				continue;
			}

			$link_mode = isset( $social_link_modes[ $platform ] ) ? sanitize_key( $social_link_modes[ $platform ] ) : 'none';
			if ( ! in_array( $link_mode, self::get_supported_link_modes(), true ) ) {
				$link_mode = 'none';
			}

			$entries[ $platform ] = array(
				'platform'    => $platform,
				'account_id'  => isset( $account_ids[ $platform ] ) ? sanitize_text_field( $account_ids[ $platform ] ) : '',
				'content'     => isset( $social_posts[ $platform ] ) ? sanitize_textarea_field( self::normalize_post_content( $social_posts[ $platform ] ) ) : '',
				'media_items' => isset( $social_media_items[ $platform ] ) ? self::normalize_media_items( $social_media_items[ $platform ] ) : array(),
				'link_mode'   => $link_mode,
				'link_url'    => isset( $social_link_urls[ $platform ] ) ? esc_url_raw( $social_link_urls[ $platform ] ) : '',
				'link_label'  => isset( $social_link_labels[ $platform ] ) ? sanitize_text_field( $social_link_labels[ $platform ] ) : '',
			);
		}

		return $entries;
	}

	/**
	 * Extract normalized image-style fallback media from content_library JSON.
	 *
	 * @param string $content_library Content library JSON.
	 * @return array
	 */
	public static function get_content_library_media( $content_library ) {
		if ( empty( $content_library ) || ! is_string( $content_library ) ) {
			return array();
		}

		$decoded = json_decode( $content_library, true );
		if ( ! is_array( $decoded ) || empty( $decoded['images'] ) || ! is_array( $decoded['images'] ) ) {
			return array();
		}

		return self::normalize_media_items( $decoded['images'] );
	}

	/**
	 * Load persisted social child rows for a campaign.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $output      Result type accepted by $wpdb->get_results().
	 * @return array
	 */
	public static function get_campaign_rows( $campaign_id, $output = ARRAY_A ) {
		global $wpdb;

		$campaign_id = (int) $campaign_id;
		if ( $campaign_id <= 0 ) {
			return array();
		}

		$table = $wpdb->prefix . 'bcsend_social_posts';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY platform ASC", $campaign_id ),
			$output
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a normalized link-resolution context for social rows.
	 *
	 * @param string $content_library Content library JSON.
	 * @param int    $product_id      Optional WooCommerce product ID.
	 * @return array
	 */
	public static function get_link_context( $content_library = '', $product_id = 0 ) {
		$context = array(
			'homepage_url' => home_url( '/' ),
			'product_url'  => '',
		);

		if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$context['product_url'] = $product->get_permalink();
			}
		}

		if ( ! empty( $context['product_url'] ) ) {
			return $context;
		}

		if ( empty( $content_library ) || ! is_string( $content_library ) ) {
			return $context;
		}

		$decoded = json_decode( $content_library, true );
		if ( ! is_array( $decoded ) || empty( $decoded['products'] ) || ! is_array( $decoded['products'] ) ) {
			return $context;
		}

		foreach ( $decoded['products'] as $product ) {
			if ( ! is_array( $product ) || empty( $product['permalink'] ) ) {
				continue;
			}

			$context['product_url'] = esc_url_raw( $product['permalink'] );
			if ( ! empty( $context['product_url'] ) ) {
				break;
			}
		}

		return $context;
	}

	/**
	 * Resolve a social link URL server-side.
	 *
	 * @param string $link_mode    Selected link mode.
	 * @param string $incoming_url Incoming URL from transport or storage.
	 * @param array  $link_context Context array from self::get_link_context().
	 * @return string
	 */
	public static function resolve_link_url( $link_mode, $incoming_url, $link_context = array() ) {
		$link_mode    = sanitize_key( $link_mode );
		$incoming_url = esc_url_raw( $incoming_url );
		$link_context = is_array( $link_context ) ? $link_context : array();

		if ( 'homepage' === $link_mode ) {
			return ! empty( $link_context['homepage_url'] ) ? esc_url_raw( $link_context['homepage_url'] ) : home_url( '/' );
		}

		if ( 'product' === $link_mode ) {
			return ! empty( $link_context['product_url'] ) ? esc_url_raw( $link_context['product_url'] ) : '';
		}

		if ( 'custom' === $link_mode ) {
			return $incoming_url;
		}

		return '';
	}

	/**
	 * Build final publishable social content with link handling.
	 *
	 * @param string $content   Base content.
	 * @param string $link_mode Link mode.
	 * @param string $link_url  Resolved/custom URL.
	 * @return string
	 */
	public static function build_publish_content( $content, $link_mode = 'none', $link_url = '' ) {
		$content   = self::normalize_post_content( $content );
		$link_mode = sanitize_key( $link_mode );
		$link_url  = esc_url_raw( $link_url );
		$separator = self::get_link_separator();

		if ( 'link_in_bio' === $link_mode ) {
			$link_text = 'Link in bio';
			if ( false === stripos( $content, $link_text ) ) {
				$content = trim( $content . $separator . $link_text );
			}
			return $content;
		}

		if ( empty( $link_url ) ) {
			return $content;
		}

		if ( false === strpos( $content, $link_url ) ) {
			$content = trim( $content . $separator . $link_url );
		}

		return $content;
	}

	/**
	 * Validate social transport entries for draft warnings or schedule errors.
	 *
	 * @param string $social_posts_raw       JSON object keyed by platform.
	 * @param string $account_ids_raw        JSON object keyed by platform.
	 * @param string $social_media_items_raw JSON object keyed by platform.
	 * @param string $social_link_modes_raw  JSON object keyed by platform.
	 * @param string $social_link_urls_raw   JSON object keyed by platform.
	 * @param string $social_link_labels_raw JSON object keyed by platform.
	 * @param string $social_platforms_raw   JSON array of selected platforms.
	 * @param string $content_library        Campaign content-library JSON.
	 * @param bool   $strict                 Whether to produce blocking errors.
	 * @param int    $product_id             Optional product ID.
	 * @return array
	 */
	public static function validate_transport( $social_posts_raw, $account_ids_raw, $social_media_items_raw, $social_link_modes_raw, $social_link_urls_raw, $social_link_labels_raw, $social_platforms_raw, $content_library, $strict = false, $product_id = 0 ) {
		$entries        = self::prepare_transport_entries( $social_posts_raw, $account_ids_raw, $social_media_items_raw, $social_link_modes_raw, $social_link_urls_raw, $social_link_labels_raw, $social_platforms_raw );
		$fallback_media = self::get_content_library_media( $content_library );
		$link_context   = self::get_link_context( $content_library, $product_id );
		$rules          = self::get_platform_rules()['platforms'];
		$errors         = array();
		$warnings       = array();

		foreach ( $entries as $platform => $entry ) {
			$label           = isset( $rules[ $platform ]['label'] ) ? $rules[ $platform ]['label'] : ucfirst( $platform );
			$resolved_url    = self::resolve_link_url( $entry['link_mode'], $entry['link_url'], $link_context );
			$effective_text  = self::build_publish_content( $entry['content'], $entry['link_mode'], $resolved_url );
			$effective_media = ! empty( $entry['media_items'] ) ? $entry['media_items'] : $fallback_media;
			$target_bucket   = $strict ? 'errors' : 'warnings';
			$max_chars       = isset( $rules[ $platform ]['maxChars'] ) ? (int) $rules[ $platform ]['maxChars'] : 500;
			$requires_media  = ! empty( $rules[ $platform ]['requiresMedia'] );

			if ( empty( $entry['account_id'] ) ) {
				${$target_bucket}[] = sprintf( __( 'Choose a connected account for %s.', 'beacon-campaign-sender' ), $label );
			}

			if ( empty( trim( $entry['content'] ) ) ) {
				${$target_bucket}[] = sprintf( __( 'Add social copy for %s.', 'beacon-campaign-sender' ), $label );
			}

			if ( ! empty( $effective_text ) && mb_strlen( $effective_text ) > $max_chars ) {
				${$target_bucket}[] = sprintf( __( '%s copy exceeds the platform character limit.', 'beacon-campaign-sender' ), $label );
			}

			if ( $requires_media && empty( $effective_media ) ) {
				${$target_bucket}[] = sprintf( __( '%s requires at least one image or video.', 'beacon-campaign-sender' ), $label );
			}

			if ( in_array( $entry['link_mode'], array( 'product', 'homepage', 'custom' ), true ) && empty( $resolved_url ) ) {
				${$target_bucket}[] = sprintf( __( '%s needs a resolved URL for the selected link mode.', 'beacon-campaign-sender' ), $label );
			}
		}

		return array(
			'entries'  => $entries,
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Validate stored social rows for a campaign before scheduling.
	 *
	 * @param object|array $campaign Campaign row object or array.
	 * @param bool         $strict   Whether to produce blocking errors.
	 * @return array
	 */
	public static function validate_campaign_rows( $campaign, $strict = true ) {
		global $wpdb;

		$campaign_id     = 0;
		$content_library = '';
		$product_id      = 0;
		$send_social     = 0;

		if ( is_object( $campaign ) ) {
			$campaign_id     = isset( $campaign->id ) ? (int) $campaign->id : 0;
			$content_library = isset( $campaign->content_library ) ? $campaign->content_library : '';
			$product_id      = isset( $campaign->product_id ) ? (int) $campaign->product_id : 0;
			$send_social     = isset( $campaign->send_social ) ? (int) $campaign->send_social : 0;
		} elseif ( is_array( $campaign ) ) {
			$campaign_id     = isset( $campaign['id'] ) ? (int) $campaign['id'] : 0;
			$content_library = isset( $campaign['content_library'] ) ? $campaign['content_library'] : '';
			$product_id      = isset( $campaign['product_id'] ) ? (int) $campaign['product_id'] : 0;
			$send_social     = isset( $campaign['send_social'] ) ? (int) $campaign['send_social'] : 0;
		}

		if ( empty( $send_social ) || $campaign_id <= 0 ) {
			return array(
				'entries'  => array(),
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		$table = $wpdb->prefix . 'bcsend_social_posts';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY platform ASC", $campaign_id ),
			ARRAY_A
		);

		$social_posts       = array();
		$social_account_ids = array();
		$social_media_items = array();
		$social_link_modes  = array();
		$social_link_urls   = array();
		$social_link_labels = array();
		$social_platforms   = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['platform'] ) ) {
				continue;
			}

			$platform                        = sanitize_key( $row['platform'] );
			$social_platforms[]              = $platform;
			$social_posts[ $platform ]       = isset( $row['content'] ) ? $row['content'] : '';
			$social_account_ids[ $platform ] = isset( $row['account_id'] ) ? $row['account_id'] : '';
			$social_media_items[ $platform ] = ! empty( $row['media_items'] ) ? json_decode( $row['media_items'], true ) : array();
			$social_link_modes[ $platform ]  = isset( $row['link_mode'] ) ? $row['link_mode'] : 'none';
			$social_link_urls[ $platform ]   = isset( $row['link_url'] ) ? $row['link_url'] : '';
			$social_link_labels[ $platform ] = isset( $row['link_label'] ) ? $row['link_label'] : '';
		}

		return self::validate_transport(
			wp_json_encode( $social_posts ),
			wp_json_encode( $social_account_ids ),
			wp_json_encode( $social_media_items ),
			wp_json_encode( $social_link_modes ),
			wp_json_encode( $social_link_urls ),
			wp_json_encode( $social_link_labels ),
			wp_json_encode( array_values( array_unique( $social_platforms ) ) ),
			$content_library,
			$strict,
			$product_id
		);
	}

	/**
	 * Reconcile social draft rows for a campaign.
	 *
	 * @param int    $campaign_id             Campaign ID.
	 * @param int    $send_social             Social enabled flag.
	 * @param string $social_posts_raw        JSON object keyed by platform.
	 * @param string $account_ids_raw         JSON object keyed by platform.
	 * @param string $social_media_items_raw  JSON object keyed by platform with media items.
	 * @param string $social_link_modes_raw   JSON object keyed by platform with link modes.
	 * @param string $social_link_urls_raw    JSON object keyed by platform with link URLs.
	 * @param string $social_link_labels_raw  JSON object keyed by platform with link labels.
	 * @param string $social_platforms_raw    JSON array of selected platforms.
	 * @param string $social_scheduled_at     Optional dedicated social schedule.
	 * @return void
	 */
	public static function sync_draft_rows( $campaign_id, $send_social, $social_posts_raw, $account_ids_raw, $social_media_items_raw, $social_link_modes_raw, $social_link_urls_raw, $social_link_labels_raw, $social_platforms_raw, $social_scheduled_at ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_social_posts';

		if ( ! $send_social ) {
			$wpdb->delete( $table, array( 'campaign_id' => (int) $campaign_id ), array( '%d' ) );
			return;
		}

		$entries = self::prepare_transport_entries(
			$social_posts_raw,
			$account_ids_raw,
			$social_media_items_raw,
			$social_link_modes_raw,
			$social_link_urls_raw,
			$social_link_labels_raw,
			$social_platforms_raw
		);

		$campaign_table = $wpdb->prefix . 'bcsend_campaigns';
		$campaign_row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT product_id, content_library FROM {$campaign_table} WHERE id = %d", (int) $campaign_id ),
			ARRAY_A
		);
		$link_context   = self::get_link_context(
			isset( $campaign_row['content_library'] ) ? $campaign_row['content_library'] : '',
			isset( $campaign_row['product_id'] ) ? (int) $campaign_row['product_id'] : 0
		);

		$keep_ids = array();

		foreach ( $entries as $platform => $entry ) {
			$platform    = sanitize_key( $platform );
			$account_id  = $entry['account_id'];
			$content     = $entry['content'];
			$media_items = $entry['media_items'];
			$link_url    = self::resolve_link_url( $entry['link_mode'], $entry['link_url'], $link_context );

			if ( empty( $platform ) || empty( $account_id ) ) {
				continue;
			}

			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE campaign_id = %d AND platform = %s AND account_id = %s",
					(int) $campaign_id,
					$platform,
					$account_id
				)
			);

			$row        = array(
				'campaign_id' => (int) $campaign_id,
				'platform'    => $platform,
				'account_id'  => $account_id,
				'content'     => $content,
				'media_items' => ! empty( $media_items ) ? wp_json_encode( $media_items ) : null,
				'link_mode'   => $entry['link_mode'],
				'link_url'    => $link_url,
				'link_label'  => $entry['link_label'],
				'status'      => 'draft',
			);
			$row_format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

			if ( ! empty( $social_scheduled_at ) ) {
				$row['scheduled_for'] = $social_scheduled_at;
				$row_format[]         = '%s';
			}

			if ( $existing_id ) {
				$wpdb->update(
					$table,
					$row,
					array( 'id' => (int) $existing_id ),
					$row_format,
					array( '%d' )
				);
				if ( empty( $social_scheduled_at ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET scheduled_for = NULL WHERE id = %d", (int) $existing_id ) );
				}
				$keep_ids[] = (int) $existing_id;
			} else {
				$wpdb->insert( $table, $row, $row_format );
				$keep_ids[] = (int) $wpdb->insert_id;
			}
		}

		$existing_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE campaign_id = %d", (int) $campaign_id )
		);

		foreach ( $existing_rows as $row ) {
			if ( ! in_array( (int) $row->id, $keep_ids, true ) ) {
				$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			}
		}
	}
}
