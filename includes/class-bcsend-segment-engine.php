<?php
/**
 * Segment Engine for Beacon Campaign Sender.
 *
 * Handles segment queries, CRUD operations, and Brevo list synchronization.
 * Supports HPOS-compatible WooCommerce queries and multiple segment types.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Segment_Engine
 *
 * Provides static methods for segment management, user queries, and Brevo sync.
 *
 * @since 1.0.0
 */
class Bcsend_Segment_Engine {

	/**
	 * Get email addresses for a segment based on its query type.
	 *
	 * Dispatches to the appropriate private query method depending on
	 * the segment's query_type field.
	 *
	 * @since 1.0.0
	 *
	 * @param object $segment Segment row object from the database.
	 *
	 * @return array Array of email addresses.
	 */
	public static function get_user_emails_for_segment( $segment ) {
		if ( empty( $segment ) || empty( $segment->query_type ) ) {
			Bcsend_Logger::log(
				'segment',
				'Invalid segment or missing query_type.',
				wp_json_encode(
					array(
						'error' => 'Invalid segment or missing query_type.',
					)
				),
				'error'
			);
			return array();
		}

		$params = ! empty( $segment->query_params ) ? json_decode( $segment->query_params, true ) : array();

		switch ( $segment->query_type ) {
			case 'all_customers':
				return self::query_all_customers();

			case 'by_category':
				$slug = isset( $params['category_slug'] ) ? $params['category_slug'] : '';
				return self::query_by_category( $slug );

			case 'by_product':
				$product_id = isset( $params['product_id'] ) ? (int) $params['product_id'] : 0;
				return self::query_by_product( $product_id );

			case 'never_purchased':
				return self::query_never_purchased();

			case 'inactive':
				$days = isset( $params['days'] ) ? (int) $params['days'] : 90;
				return self::query_inactive( $days );

			case 'new_members':
				$days = isset( $params['days'] ) ? (int) $params['days'] : 30;
				return self::query_new_members( $days );

			case 'app_users':
				return self::query_app_users();

			default:
				Bcsend_Logger::log(
					'segment',
					'Unknown query_type.',
					wp_json_encode(
						array(
							'warning'    => 'Unknown query_type.',
							'query_type' => $segment->query_type,
							'segment_id' => $segment->id,
						)
					),
					'error'
				);
				return array();
		}
	}

	/**
	 * Get user IDs for a segment based on its query type.
	 *
	 * Returns WordPress user IDs instead of email addresses.
	 * For query types that only return emails (e.g. guest checkouts),
	 * this maps emails back to user IDs where possible.
	 *
	 * @since 1.0.0
	 *
	 * @param object $segment Segment row object from the database.
	 *
	 * @return array Array of user IDs.
	 */
	public static function get_user_ids_for_segment( $segment ) {
		global $wpdb;

		$emails = self::get_user_emails_for_segment( $segment );

		if ( empty( $emails ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $emails ), '%s' ) );

		$user_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT DISTINCT ID FROM {$wpdb->users} WHERE user_email IN ({$placeholders})",
				$emails
			)
		);

		return array_map( 'intval', $user_ids );
	}

	/**
	 * Query all customers who have placed at least one order.
	 *
	 * Uses HPOS tables when enabled, falls back to post meta.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_all_customers() {
		global $wpdb;

		$env = Bcsend_Environment::get_instance();

		if ( ! $env->is( 'woocommerce_active' ) ) {
			Bcsend_Logger::log(
				'segment',
				'WooCommerce not active. Cannot query all_customers.',
				wp_json_encode(
					array(
						'warning' => 'WooCommerce not active. Cannot query all_customers.',
					)
				),
				'error'
			);
			return array();
		}

		if ( $env->is( 'hpos_enabled' ) ) {
			$results = $wpdb->get_col(
				"SELECT DISTINCT oa.email
				FROM {$wpdb->prefix}wc_orders AS o
				INNER JOIN {$wpdb->prefix}wc_order_addresses AS oa
					ON o.id = oa.order_id AND oa.address_type = 'billing'
				WHERE o.type = 'shop_order'
					AND o.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
					AND oa.email != ''"
			);
		} else {
			$results = $wpdb->get_col(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->postmeta} AS pm
					ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
				WHERE p.post_type = 'shop_order'
					AND p.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
					AND pm.meta_value != ''"
			);
		}

		Bcsend_Logger::log(
			'segment',
			'Segment query: all_customers',
			wp_json_encode(
				array(
					'query'        => 'all_customers',
					'result_count' => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Query customers who purchased products in a given category.
	 *
	 * Joins orders through order items to products and their term relationships.
	 * HPOS-aware.
	 *
	 * @since 1.0.0
	 *
	 * @param string $category_slug Product category slug.
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_by_category( $category_slug ) {
		global $wpdb;

		$env = Bcsend_Environment::get_instance();

		if ( ! $env->is( 'woocommerce_active' ) ) {
			Bcsend_Logger::log(
				'segment',
				'WooCommerce not active. Cannot query by_category.',
				wp_json_encode(
					array(
						'warning' => 'WooCommerce not active. Cannot query by_category.',
					)
				),
				'error'
			);
			return array();
		}

		if ( empty( $category_slug ) ) {
			Bcsend_Logger::log(
				'segment',
				'Empty category_slug for by_category query.',
				wp_json_encode(
					array(
						'warning' => 'Empty category_slug for by_category query.',
					)
				),
				'error'
			);
			return array();
		}

		$order_items_table    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		if ( $env->is( 'hpos_enabled' ) ) {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT oa.email
					FROM {$wpdb->prefix}wc_orders AS o
					INNER JOIN {$wpdb->prefix}wc_order_addresses AS oa
						ON o.id = oa.order_id AND oa.address_type = 'billing'
					INNER JOIN {$order_items_table} AS oi
						ON o.id = oi.order_id AND oi.order_item_type = 'line_item'
					INNER JOIN {$order_itemmeta_table} AS oim
						ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
					INNER JOIN {$wpdb->term_relationships} AS tr
						ON oim.meta_value = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} AS tt
						ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
					INNER JOIN {$wpdb->terms} AS t
						ON tt.term_id = t.term_id AND t.slug = %s
					WHERE o.type = 'shop_order'
						AND o.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
						AND oa.email != ''",
					$category_slug
				)
			);
		} else {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value
					FROM {$wpdb->posts} AS p
					INNER JOIN {$wpdb->postmeta} AS pm
						ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
					INNER JOIN {$order_items_table} AS oi
						ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
					INNER JOIN {$order_itemmeta_table} AS oim
						ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
					INNER JOIN {$wpdb->term_relationships} AS tr
						ON oim.meta_value = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} AS tt
						ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
					INNER JOIN {$wpdb->terms} AS t
						ON tt.term_id = t.term_id AND t.slug = %s
					WHERE p.post_type = 'shop_order'
						AND p.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
						AND pm.meta_value != ''",
					$category_slug
				)
			);
		}

		Bcsend_Logger::log(
			'segment',
			'Segment query: by_category',
			wp_json_encode(
				array(
					'query'         => 'by_category',
					'category_slug' => $category_slug,
					'result_count'  => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Query customers who purchased a specific product.
	 *
	 * HPOS-aware.
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id WooCommerce product ID.
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_by_product( $product_id ) {
		global $wpdb;

		$env = Bcsend_Environment::get_instance();

		if ( ! $env->is( 'woocommerce_active' ) ) {
			Bcsend_Logger::log(
				'segment',
				'WooCommerce not active. Cannot query by_product.',
				wp_json_encode(
					array(
						'warning' => 'WooCommerce not active. Cannot query by_product.',
					)
				),
				'error'
			);
			return array();
		}

		if ( empty( $product_id ) ) {
			Bcsend_Logger::log(
				'segment',
				'Empty product_id for by_product query.',
				wp_json_encode(
					array(
						'warning' => 'Empty product_id for by_product query.',
					)
				),
				'error'
			);
			return array();
		}

		$order_items_table    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		if ( $env->is( 'hpos_enabled' ) ) {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT oa.email
					FROM {$wpdb->prefix}wc_orders AS o
					INNER JOIN {$wpdb->prefix}wc_order_addresses AS oa
						ON o.id = oa.order_id AND oa.address_type = 'billing'
					INNER JOIN {$order_items_table} AS oi
						ON o.id = oi.order_id AND oi.order_item_type = 'line_item'
					INNER JOIN {$order_itemmeta_table} AS oim
						ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
					WHERE o.type = 'shop_order'
						AND o.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
						AND oa.email != ''
						AND oim.meta_value = %d",
					$product_id
				)
			);
		} else {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value
					FROM {$wpdb->posts} AS p
					INNER JOIN {$wpdb->postmeta} AS pm
						ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
					INNER JOIN {$order_items_table} AS oi
						ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
					INNER JOIN {$order_itemmeta_table} AS oim
						ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
					WHERE p.post_type = 'shop_order'
						AND p.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
						AND pm.meta_value != ''
						AND oim.meta_value = %d",
					$product_id
				)
			);
		}

		Bcsend_Logger::log(
			'segment',
			'Segment query: by_product',
			wp_json_encode(
				array(
					'query'        => 'by_product',
					'product_id'   => $product_id,
					'result_count' => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Query users with the customer role who have never placed an order.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_never_purchased() {
		global $wpdb;

		$env = Bcsend_Environment::get_instance();

		if ( ! $env->is( 'woocommerce_active' ) ) {
			Bcsend_Logger::log(
				'segment',
				'WooCommerce not active. Cannot query never_purchased.',
				wp_json_encode(
					array(
						'warning' => 'WooCommerce not active. Cannot query never_purchased.',
					)
				),
				'error'
			);
			return array();
		}

		if ( $env->is( 'hpos_enabled' ) ) {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT u.user_email
					FROM {$wpdb->users} AS u
					INNER JOIN {$wpdb->usermeta} AS um
						ON u.ID = um.user_id AND um.meta_key = %s
					WHERE um.meta_value LIKE %s
						AND u.ID NOT IN (
							SELECT DISTINCT o.customer_id
							FROM {$wpdb->prefix}wc_orders AS o
							WHERE o.type = 'shop_order'
								AND o.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
								AND o.customer_id > 0
						)",
					$wpdb->prefix . 'capabilities',
					'%"customer"%'
				)
			);
		} else {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT u.user_email
					FROM {$wpdb->users} AS u
					INNER JOIN {$wpdb->usermeta} AS um
						ON u.ID = um.user_id AND um.meta_key = %s
					WHERE um.meta_value LIKE %s
						AND u.ID NOT IN (
							SELECT DISTINCT pm.meta_value
							FROM {$wpdb->posts} AS p
							INNER JOIN {$wpdb->postmeta} AS pm
								ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
							WHERE p.post_type = 'shop_order'
								AND p.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
								AND pm.meta_value > 0
						)",
					$wpdb->prefix . 'capabilities',
					'%"customer"%'
				)
			);
		}

		Bcsend_Logger::log(
			'segment',
			'Segment query: never_purchased',
			wp_json_encode(
				array(
					'query'        => 'never_purchased',
					'result_count' => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Query customers whose most recent order is older than a given number of days.
	 *
	 * @since 1.0.0
	 *
	 * @param int $days Number of days of inactivity.
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_inactive( $days ) {
		global $wpdb;

		$env = Bcsend_Environment::get_instance();

		if ( ! $env->is( 'woocommerce_active' ) ) {
			Bcsend_Logger::log(
				'segment',
				'WooCommerce not active. Cannot query inactive.',
				wp_json_encode(
					array(
						'warning' => 'WooCommerce not active. Cannot query inactive.',
					)
				),
				'error'
			);
			return array();
		}

		$days = max( 1, (int) $days );

		if ( $env->is( 'hpos_enabled' ) ) {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT oa.email
					FROM {$wpdb->prefix}wc_order_addresses AS oa
					INNER JOIN (
						SELECT oa2.email, MAX( o2.date_created_gmt ) AS last_order_date
						FROM {$wpdb->prefix}wc_orders AS o2
						INNER JOIN {$wpdb->prefix}wc_order_addresses AS oa2
							ON o2.id = oa2.order_id AND oa2.address_type = 'billing'
						WHERE o2.type = 'shop_order'
							AND o2.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
							AND oa2.email != ''
						GROUP BY oa2.email
					) AS latest ON oa.email = latest.email
					WHERE latest.last_order_date < DATE_SUB( NOW(), INTERVAL %d DAY )
					GROUP BY oa.email",
					$days
				)
			);
		} else {
			$results = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.meta_value AS email
					FROM {$wpdb->postmeta} AS pm
					INNER JOIN (
						SELECT pm2.meta_value AS email, MAX( p2.post_date_gmt ) AS last_order_date
						FROM {$wpdb->posts} AS p2
						INNER JOIN {$wpdb->postmeta} AS pm2
							ON p2.ID = pm2.post_id AND pm2.meta_key = '_billing_email'
						WHERE p2.post_type = 'shop_order'
							AND p2.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
							AND pm2.meta_value != ''
						GROUP BY pm2.meta_value
					) AS latest ON pm.meta_value = latest.email
					WHERE latest.last_order_date < DATE_SUB( NOW(), INTERVAL %d DAY )
					GROUP BY pm.meta_value",
					$days
				)
			);
		}

		Bcsend_Logger::log(
			'segment',
			'Segment query: inactive',
			wp_json_encode(
				array(
					'query'        => 'inactive',
					'days'         => $days,
					'result_count' => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Query users who registered within a given number of days.
	 *
	 * @since 1.0.0
	 *
	 * @param int $days Number of days since registration.
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_new_members( $days ) {
		global $wpdb;

		$days = max( 1, (int) $days );

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_email
				FROM {$wpdb->users}
				WHERE user_registered > DATE_SUB( NOW(), INTERVAL %d DAY )
					AND user_email != ''",
				$days
			)
		);

		Bcsend_Logger::log(
			'segment',
			'Segment query: new_members',
			wp_json_encode(
				array(
					'query'        => 'new_members',
					'days'         => $days,
					'result_count' => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Query users who have registered push tokens in the BuddyBoss app.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of distinct email addresses.
	 */
	private static function query_app_users() {
		global $wpdb;

		$env = Bcsend_Environment::get_instance();

		if ( ! $env->is( 'buddyboss_present' ) ) {
			Bcsend_Logger::log(
				'segment',
				'BuddyBoss not present. Cannot query app_users.',
				wp_json_encode(
					array(
						'warning' => 'BuddyBoss not present. Cannot query app_users.',
					)
				),
				'error'
			);
			return array();
		}

		$devices_table = $wpdb->prefix . 'bbapp_user_devices';

		$results = $wpdb->get_col(
			"SELECT DISTINCT u.user_email
			FROM {$devices_table} AS pt
			INNER JOIN {$wpdb->users} AS u ON pt.user_id = u.ID
			WHERE u.user_email != ''
				AND pt.device_token != ''"
		);

		Bcsend_Logger::log(
			'segment',
			'Segment query: app_users',
			wp_json_encode(
				array(
					'query'        => 'app_users',
					'result_count' => count( $results ),
				)
			)
		);

		return $results;
	}

	/**
	 * Synchronize a segment's contacts to a Brevo contact list.
	 *
	 * Creates the Brevo list if one does not exist, then pushes all
	 * segment emails to the list and updates the local record.
	 *
	 * @since 1.0.0
	 *
	 * @param int $segment_id Segment ID.
	 *
	 * @return array|WP_Error Result array with keys: synced_count, list_id. WP_Error on failure.
	 */
	public static function sync_to_brevo( $segment_id ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'bcsend_segments';
		$segment = self::get_segment( $segment_id );

		if ( empty( $segment ) ) {
			return new WP_Error( 'segment_not_found', 'Segment not found.' );
		}

		$emails = self::get_user_emails_for_segment( $segment );

		Bcsend_Logger::log(
			'segment_sync',
			'Segment sync started',
			wp_json_encode(
				array(
					'segment_id'  => $segment_id,
					'email_count' => count( $emails ),
				)
			)
		);

		$brevo = new Bcsend_Brevo_API();

		if ( ! $brevo->is_configured() ) {
			return new WP_Error( 'brevo_not_configured', 'Brevo API is not configured.' );
		}

		$list_id = ! empty( $segment->brevo_list_id ) ? (int) $segment->brevo_list_id : 0;

		// Create a Brevo list if one does not exist for this segment.
		if ( empty( $list_id ) ) {
			$list_name = 'BCSEND: ' . $segment->name;
			$response  = $brevo->create_or_update_contact_list( $list_name );

			if ( is_wp_error( $response ) ) {
				Bcsend_Logger::log(
					'segment_sync',
					'Failed to create Brevo list.',
					wp_json_encode(
						array(
							'error'      => 'Failed to create Brevo list.',
							'segment_id' => $segment_id,
							'details'    => $response->get_error_message(),
						)
					),
					'error'
				);
				return $response;
			}

			$list_id = isset( $response['id'] ) ? (int) $response['id'] : 0;

			if ( empty( $list_id ) ) {
				return new WP_Error( 'brevo_list_creation_failed', 'Brevo list creation returned no ID.' );
			}

			$wpdb->update(
				$table,
				array( 'brevo_list_id' => $list_id ),
				array( 'id' => $segment_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		// Normalize fresh emails to lowercase for comparison.
		$fresh_emails = array_map( 'strtolower', $emails );

		// Get current contacts on the Brevo list to detect removals.
		$current_emails = $brevo->get_list_contacts( $list_id );
		$stale_emails   = array();

		if ( ! is_wp_error( $current_emails ) && ! empty( $current_emails ) ) {
			$fresh_set    = array_flip( $fresh_emails );
			$stale_emails = array_filter(
				$current_emails,
				function ( $email ) use ( $fresh_set ) {
					return ! isset( $fresh_set[ $email ] );
				}
			);
		}

		// Remove stale contacts from the list.
		if ( ! empty( $stale_emails ) ) {
			$batch_size = 150;
			$batches    = array_chunk( array_values( $stale_emails ), $batch_size );

			foreach ( $batches as $batch ) {
				$response = $brevo->remove_contacts_from_list( $list_id, $batch );

				if ( is_wp_error( $response ) ) {
					Bcsend_Logger::log(
						'segment_sync',
						'Failed to remove stale contacts from Brevo list.',
						wp_json_encode(
							array(
								'segment_id' => $segment_id,
								'list_id'    => $list_id,
								'removed'    => count( $batch ),
								'details'    => $response->get_error_message(),
							)
						),
						'error'
					);
				}
			}

			Bcsend_Logger::log(
				'segment_sync',
				'Removed stale contacts from Brevo list.',
				wp_json_encode(
					array(
						'segment_id'    => $segment_id,
						'list_id'       => $list_id,
						'removed_count' => count( $stale_emails ),
					)
				)
			);
		}

		// Push fresh contacts to the list.
		if ( ! empty( $fresh_emails ) ) {
			$batch_size = 150;
			$batches    = array_chunk( $fresh_emails, $batch_size );

			foreach ( $batches as $batch ) {
				$response = $brevo->add_contacts_to_list( $list_id, $batch );

				if ( is_wp_error( $response ) ) {
					Bcsend_Logger::log(
						'segment_sync',
						'Failed to add contacts to Brevo list.',
						wp_json_encode(
							array(
								'segment_id' => $segment_id,
								'list_id'    => $list_id,
								'details'    => $response->get_error_message(),
							)
						),
						'error'
					);
					return $response;
				}
			}
		}

		// Update local segment record.
		$wpdb->update(
			$table,
			array(
				'contact_count' => count( $emails ),
				'last_synced'   => current_time( 'mysql', true ),
			),
			array( 'id' => $segment_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		Bcsend_Logger::log(
			'segment_sync',
			'Segment synced to Brevo',
			wp_json_encode(
				array(
					'segment_id'   => $segment_id,
					'synced_count' => count( $emails ),
					'list_id'      => $list_id,
					'status'       => 'success',
				)
			)
		);

		return array(
			'synced_count' => count( $emails ),
			'list_id'      => $list_id,
		);
	}

	/**
	 * Synchronize all smart segments to Brevo.
	 *
	 * Iterates over all segments with type 'smart' and syncs each.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of results keyed by segment ID.
	 */
	public static function sync_all() {
		// Sync Brevo lists into local segments (inbound).
		self::sync_from_brevo();

		$segments = self::get_segments( 'smart' );
		$results  = array();

		foreach ( $segments as $segment ) {
			$result                  = self::sync_to_brevo( $segment->id );
			$results[ $segment->id ] = $result;

			if ( is_wp_error( $result ) ) {
				Bcsend_Logger::log(
					'segment_sync',
					'Segment sync event',
					wp_json_encode(
						array(
							'segment_id' => $segment->id,
							'error'      => $result->get_error_message(),
						)
					),
					'error'
				);
			}
		}

		Bcsend_Logger::log(
			'segment_sync',
			'All segments synced',
			wp_json_encode(
				array(
					'action'        => 'sync_all',
					'total'         => count( $segments ),
					'success_count' => count(
						array_filter(
							$results,
							function ( $r ) {
								return ! is_wp_error( $r );
							}
						)
					),
				)
			)
		);

		return $results;
	}

	/**
	 * Sync Brevo contact lists into local segments.
	 *
	 * Fetches all lists from the Brevo API and ensures each one has a
	 * corresponding local segment of type 'brevo_list'. Creates missing
	 * segments, updates stale names/counts, and removes orphaned rows
	 * whose Brevo list no longer exists.
	 *
	 * @since 2.2.0
	 *
	 * @return array The raw Brevo lists array, or empty array on failure.
	 */
	public static function sync_from_brevo() {
		global $wpdb;

		$brevo = new Bcsend_Brevo_API();
		if ( ! $brevo->is_configured() ) {
			return array();
		}

		$lists = $brevo->get_lists();
		if ( is_wp_error( $lists ) ) {
			Bcsend_Logger::log(
				'segment_sync',
				'Failed to fetch Brevo lists for sync.',
				wp_json_encode( array( 'error' => $lists->get_error_message() ) ),
				'error'
			);
			return array();
		}

		$table = $wpdb->prefix . 'bcsend_segments';

		// Build lookup of existing brevo_list segments keyed by brevo_list_id.
		$existing_segments = self::get_segments( 'brevo_list' );
		$by_brevo_id       = array();
		foreach ( $existing_segments as $seg ) {
			if ( ! empty( $seg->brevo_list_id ) ) {
				$by_brevo_id[ (int) $seg->brevo_list_id ] = $seg;
			}
		}

		$live_brevo_ids = array();
		$now            = current_time( 'mysql', true );

		foreach ( $lists as $list ) {
			$list_id   = isset( $list['id'] ) ? (int) $list['id'] : 0;
			$list_name = isset( $list['name'] ) ? sanitize_text_field( $list['name'] ) : '';
			$sub_count = Bcsend_Brevo_API::extract_subscriber_count( $list );

			if ( empty( $list_id ) ) {
				continue;
			}

			$live_brevo_ids[] = $list_id;

			if ( isset( $by_brevo_id[ $list_id ] ) ) {
				$seg = $by_brevo_id[ $list_id ];
				// Update if name or subscriber count changed.
				if ( $seg->name !== $list_name || (int) $seg->contact_count !== $sub_count ) {
					$wpdb->update(
						$table,
						array(
							'name'          => $list_name,
							'contact_count' => $sub_count,
							'last_synced'   => $now,
						),
						array( 'id' => (int) $seg->id ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
				}
			} else {
				// Insert new segment for this Brevo list.
				$wpdb->insert(
					$table,
					array(
						'name'          => $list_name,
						'type'          => 'brevo_list',
						'brevo_list_id' => $list_id,
						'query_type'    => '',
						'query_params'  => '',
						'contact_count' => $sub_count,
						'last_synced'   => $now,
					),
					array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
				);
			}
		}

		// Remove orphaned brevo_list segments whose Brevo list no longer exists.
		foreach ( $by_brevo_id as $brevo_id => $seg ) {
			if ( ! in_array( $brevo_id, $live_brevo_ids, true ) ) {
				$wpdb->delete( $table, array( 'id' => (int) $seg->id ), array( '%d' ) );
				Bcsend_Logger::log(
					'segment_sync',
					'Removed orphaned brevo_list segment.',
					wp_json_encode(
						array(
							'segment_id'    => $seg->id,
							'brevo_list_id' => $brevo_id,
						)
					)
				);
			}
		}

		$orphaned = count( $by_brevo_id ) - count( array_intersect( array_keys( $by_brevo_id ), $live_brevo_ids ) );

		Bcsend_Logger::log(
			'segment_sync',
			'Brevo list sync completed.',
			wp_json_encode(
				array(
					'action'   => 'sync_from_brevo',
					'imported' => count( $lists ),
					'orphaned' => $orphaned,
				)
			)
		);

		return $lists;
	}

	/**
	 * Retrieve segments from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $type Optional segment type filter ('smart' or 'static').
	 *
	 * @return array Array of segment row objects.
	 */
	public static function get_segments( $type = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		if ( null !== $type ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE type = %s ORDER BY name ASC",
					$type
				)
			);
		} else {
			$results = $wpdb->get_results(
				"SELECT * FROM {$table} ORDER BY name ASC"
			);
		}

		return $results ? $results : array();
	}

	/**
	 * Retrieve a single segment by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Segment ID.
	 *
	 * @return object|null Segment row object or null.
	 */
	public static function get_segment( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				(int) $id
			)
		);
	}

	/**
	 * Create a new segment.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Segment data with keys: name, type, query_type, query_params.
	 *
	 * @return int|WP_Error New segment ID on success, WP_Error on failure.
	 */
	public static function create_segment( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		$insert_data = array(
			'name'         => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'type'         => isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'smart',
			'query_type'   => isset( $data['query_type'] ) ? sanitize_text_field( $data['query_type'] ) : '',
			'query_params' => isset( $data['query_params'] ) ? wp_json_encode( $data['query_params'] ) : '{}',
		);

		$formats = array( '%s', '%s', '%s', '%s' );

		$inserted = $wpdb->insert( $table, $insert_data, $formats );

		if ( false === $inserted ) {
			Bcsend_Logger::log(
				'segment',
				'Failed to create segment.',
				wp_json_encode(
					array(
						'error'   => 'Failed to create segment.',
						'details' => $wpdb->last_error,
					)
				),
				'error'
			);
			return new WP_Error( 'segment_insert_failed', 'Failed to create segment: ' . $wpdb->last_error );
		}

		$new_id = (int) $wpdb->insert_id;

		Bcsend_Logger::log(
			'segment',
			'Segment created',
			wp_json_encode(
				array(
					'action'     => 'created',
					'segment_id' => $new_id,
					'name'       => $insert_data['name'],
				)
			)
		);

		return $new_id;
	}

	/**
	 * Update an existing segment.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $id   Segment ID.
	 * @param array $data Segment data to update.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update_segment( $id, $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		$update_data = array();
		$formats     = array();

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$formats[]           = '%s';
		}

		if ( isset( $data['type'] ) ) {
			$update_data['type'] = sanitize_text_field( $data['type'] );
			$formats[]           = '%s';
		}

		if ( isset( $data['query_type'] ) ) {
			$update_data['query_type'] = sanitize_text_field( $data['query_type'] );
			$formats[]                 = '%s';
		}

		if ( isset( $data['query_params'] ) ) {
			$update_data['query_params'] = wp_json_encode( $data['query_params'] );
			$formats[]                   = '%s';
		}

		if ( empty( $update_data ) ) {
			return new WP_Error( 'segment_no_data', 'No data provided for update.' );
		}

		$updated = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			Bcsend_Logger::log(
				'segment',
				'Failed to update segment.',
				wp_json_encode(
					array(
						'error'      => 'Failed to update segment.',
						'segment_id' => $id,
						'details'    => $wpdb->last_error,
					)
				),
				'error'
			);
			return new WP_Error( 'segment_update_failed', 'Failed to update segment: ' . $wpdb->last_error );
		}

		Bcsend_Logger::log(
			'segment',
			'Segment updated',
			wp_json_encode(
				array(
					'action'     => 'updated',
					'segment_id' => $id,
				)
			)
		);

		return true;
	}

	/**
	 * Delete a segment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Segment ID.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function delete_segment( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcsend_segments';

		$deleted = $wpdb->delete(
			$table,
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			Bcsend_Logger::log(
				'segment',
				'Failed to delete segment.',
				wp_json_encode(
					array(
						'error'      => 'Failed to delete segment.',
						'segment_id' => $id,
						'details'    => $wpdb->last_error,
					)
				),
				'error'
			);
			return new WP_Error( 'segment_delete_failed', 'Failed to delete segment: ' . $wpdb->last_error );
		}

		Bcsend_Logger::log(
			'segment',
			'Segment deleted',
			wp_json_encode(
				array(
					'action'     => 'deleted',
					'segment_id' => $id,
				)
			)
		);

		return true;
	}
}
