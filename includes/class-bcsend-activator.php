<?php
/**
 * Fired during plugin activation.
 *
 * Creates database tables, sets default options, and registers capabilities.
 *
 * @package Bcsend_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Activator
 */
class Bcsend_Activator {

	/**
	 * Activate the plugin.
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();
		self::migrate_settings();
		self::register_capabilities();
		flush_rewrite_rules();
	}

	/**
	 * Create database tables using dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// -----------------------------------------------------------------
		// Campaigns table.
		// -----------------------------------------------------------------
		$table_campaigns = $wpdb->prefix . 'bcsend_campaigns';
		$sql_campaigns   = "CREATE TABLE $table_campaigns (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			subject varchar(255) DEFAULT NULL,
			preview_text varchar(255) DEFAULT NULL,
			html_content longtext DEFAULT NULL,
			plain_text longtext DEFAULT NULL,
			push_title varchar(50) DEFAULT NULL,
			push_message text DEFAULT NULL,
			send_email tinyint(1) NOT NULL DEFAULT 1,
			send_push tinyint(1) NOT NULL DEFAULT 1,
			send_social tinyint(1) NOT NULL DEFAULT 0,
			reply_to varchar(255) DEFAULT NULL,
			segment_id bigint(20) unsigned DEFAULT NULL,
			push_segment_id bigint(20) unsigned DEFAULT NULL,
			push_target_type varchar(32) DEFAULT 'all_users',
			push_target_data text DEFAULT NULL,
			product_id bigint(20) unsigned DEFAULT NULL,
			scheduled_at datetime DEFAULT NULL,
			social_scheduled_at datetime DEFAULT NULL,
			social_post_mode varchar(20) NOT NULL DEFAULT 'single',
			status varchar(20) NOT NULL DEFAULT 'draft',
			email_status varchar(20) DEFAULT NULL,
			push_status varchar(20) DEFAULT NULL,
			social_status varchar(20) DEFAULT NULL,
			last_error text DEFAULT NULL,
			attempt_count tinyint(3) unsigned DEFAULT 0,
			brevo_campaign_id bigint(20) unsigned DEFAULT NULL,
			social_post_id varchar(255) DEFAULT NULL,
			send_config_snapshot longtext DEFAULT NULL,
			content_library longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			approved_at datetime DEFAULT NULL,
			queued_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at),
			KEY segment_id (segment_id),
			KEY push_segment_id (push_segment_id),
			KEY product_id (product_id)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Segments table.
		// -----------------------------------------------------------------
		$table_segments = $wpdb->prefix . 'bcsend_segments';
		$sql_segments   = "CREATE TABLE $table_segments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			type varchar(20) NOT NULL,
			brevo_list_id bigint(20) unsigned DEFAULT NULL,
			query_type varchar(50) DEFAULT NULL,
			query_params text DEFAULT NULL,
			contact_count int(10) unsigned DEFAULT 0,
			last_synced datetime DEFAULT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Templates table.
		// -----------------------------------------------------------------
		$table_templates = $wpdb->prefix . 'bcsend_templates';
		$sql_templates   = "CREATE TABLE $table_templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			html_content longtext DEFAULT NULL,
			plain_text longtext DEFAULT NULL,
			thumbnail text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Logs table.
		// -----------------------------------------------------------------
		$table_logs = $wpdb->prefix . 'bcsend_logs';
		$sql_logs   = "CREATE TABLE $table_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			message text DEFAULT NULL,
			payload longtext DEFAULT NULL,
			status varchar(20) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Push notifications table (standalone push, separate from campaigns).
		// -----------------------------------------------------------------
		$table_push = $wpdb->prefix . 'bcsend_push_notifications';
		$sql_push   = "CREATE TABLE $table_push (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(50) NOT NULL,
			message text NOT NULL,
			link_url varchar(2048) DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			is_scheduled tinyint(1) NOT NULL DEFAULT 0,
			scheduled_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			target_type varchar(20) NOT NULL DEFAULT 'all_users',
			target_data longtext DEFAULT NULL,
			sent_by bigint(20) unsigned NOT NULL DEFAULT 0,
			total_tokens int(10) unsigned DEFAULT 0,
			sent_count int(10) unsigned DEFAULT 0,
			failed_count int(10) unsigned DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at),
			KEY sent_by (sent_by)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Push delivery history (per-device tracking).
		// -----------------------------------------------------------------
		$table_push_history = $wpdb->prefix . 'bcsend_push_history';
		$sql_push_history   = "CREATE TABLE $table_push_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			push_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			device_token_hash varchar(64) NOT NULL DEFAULT '',
			platform varchar(10) NOT NULL DEFAULT '',
			status tinyint(1) NOT NULL DEFAULT 0,
			error_message text DEFAULT NULL,
			fcm_response text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY push_id (push_id),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Content snippets table (reusable email blocks).
		// -----------------------------------------------------------------
		$table_snippets = $wpdb->prefix . 'bcsend_snippets';
		$sql_snippets   = "CREATE TABLE $table_snippets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			category varchar(100) DEFAULT 'general',
			html_content longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Email log table (transactional email history).
		// -----------------------------------------------------------------
		$table_email_log = $wpdb->prefix . 'bcsend_email_log';
		$sql_email_log   = "CREATE TABLE $table_email_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			to_email text DEFAULT NULL,
			cc text DEFAULT NULL,
			bcc text DEFAULT NULL,
			subject varchar(255) DEFAULT NULL,
			body longtext DEFAULT NULL,
			headers text DEFAULT NULL,
			is_html tinyint(1) NOT NULL DEFAULT 0,
			attachments text DEFAULT NULL,
			from_name varchar(255) DEFAULT NULL,
			from_email varchar(255) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'sent',
			brevo_message_id varchar(100) DEFAULT NULL,
			error_message text DEFAULT NULL,
			resent_from_log_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY to_email (to_email(191)),
			KEY resent_from_log_id (resent_from_log_id)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Social posts table.
		// -----------------------------------------------------------------
		$table_social = $wpdb->prefix . 'bcsend_social_posts';
		$sql_social   = "CREATE TABLE $table_social (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned DEFAULT NULL,
			zernio_post_id varchar(255) DEFAULT NULL,
			platform varchar(50) NOT NULL,
			account_id varchar(255) NOT NULL,
			content text DEFAULT NULL,
			media_items longtext DEFAULT NULL,
			link_mode varchar(32) DEFAULT NULL,
			link_url text DEFAULT NULL,
			link_label varchar(190) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			scheduled_for datetime DEFAULT NULL,
			published_at datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id),
			KEY account_id (account_id),
			KEY status (status)
		) $charset_collate;";

		// -----------------------------------------------------------------
		// Subscribers table (ingest ledger + retry queue).
		// -----------------------------------------------------------------
		$table_subscribers = $wpdb->prefix . 'bcsend_subscribers';
		$sql_subscribers   = "CREATE TABLE $table_subscribers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(254) NOT NULL,
			first_name varchar(100) DEFAULT NULL,
			last_name varchar(100) DEFAULT NULL,
			source varchar(64) NOT NULL,
			consent_text text DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			list_ids_json longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			brevo_contact_id bigint(20) unsigned DEFAULT NULL,
			brevo_response_json longtext DEFAULT NULL,
			retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			next_retry_at datetime DEFAULT NULL,
			submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			confirmed_at datetime DEFAULT NULL,
			metadata_json longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_email (email),
			KEY idx_source (source),
			KEY idx_status (status),
			KEY idx_next_retry (next_retry_at),
			KEY idx_submitted (submitted_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_campaigns );
		dbDelta( $sql_segments );
		dbDelta( $sql_templates );
		dbDelta( $sql_logs );
		dbDelta( $sql_push );
		dbDelta( $sql_push_history );
		dbDelta( $sql_snippets );
		dbDelta( $sql_email_log );
		dbDelta( $sql_social );
		dbDelta( $sql_subscribers );

		update_option( 'bcsend_db_version', BCSEND_VERSION );
	}

	/**
	 * Set default plugin options.
	 */
	private static function set_default_options() {
		$defaults = array(
			'brevo_api_key'                 => '',
			'anthropic_api_key'             => '',
			'anthropic_model'               => 'claude-sonnet-4-6',
			'ai_provider'                   => 'anthropic',
			'openai_api_key'                => '',
			'openai_model'                  => 'gpt-5.4',
			'brevo_sender_name'             => get_bloginfo( 'name' ),
			'brevo_sender_email'            => get_option( 'admin_email' ),
			'push_mode'                     => 'auto',
			'firebase_service_account_json' => '',
			'zernio_api_key'                => '',
			'zernio_profile_id'             => '',
			'zernio_webhook_secret'         => '',
			'zernio_webhook_enabled'        => 0,
			'zernio_post_mode'              => 'single',
			'log_retention_days'            => 30,
			'email_log_detail_level'        => 'minimal',
			'default_subscriber_lists'      => array( 14 ),
		);

		if ( ! get_option( 'bcsend_settings' ) ) {
			add_option( 'bcsend_settings', $defaults );
		}
	}

	/**
	 * Migrate legacy settings keys to the canonical push_mode key.
	 *
	 * @since 1.1.0
	 */
	private static function migrate_settings() {
		$settings = get_option( 'bcsend_settings', array() );
		$changed  = false;
		$defaults = array(
			'ai_provider'              => 'anthropic',
			'openai_api_key'           => '',
			'openai_model'             => 'gpt-5.4',
			'zernio_api_key'           => '',
			'zernio_profile_id'        => '',
			'zernio_webhook_secret'    => '',
			'zernio_webhook_enabled'   => 0,
			'zernio_post_mode'         => 'single',
			'email_log_detail_level'   => 'minimal',
			'default_subscriber_lists' => array( 14 ),
		);

		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
				$changed          = true;
			}
		}

		// Migrate push_source → push_mode.
		if ( isset( $settings['push_source'] ) && ! isset( $settings['push_mode'] ) ) {
			$value = $settings['push_source'];
			// Map old values to new.
			if ( 'buddyboss' === $value ) {
				$settings['push_mode'] = 'auto';
			} else {
				$settings['push_mode'] = $value; // 'auto' or 'manual' carry over.
			}
			unset( $settings['push_source'] );
			$changed = true;
		}

		// Migrate push_method → push_mode (even older key).
		if ( isset( $settings['push_method'] ) && ! isset( $settings['push_mode'] ) ) {
			$settings['push_mode'] = 'auto';
			unset( $settings['push_method'] );
			$changed = true;
		}

		foreach ( array( 'zernio_api_key', 'zernio_webhook_secret' ) as $secret_key ) {
			if ( ! empty( $settings[ $secret_key ] ) && ! Bcsend_Encryption::is_encrypted( $settings[ $secret_key ] ) ) {
				$settings[ $secret_key ] = Bcsend_Encryption::encrypt( $settings[ $secret_key ] );
				$changed                 = true;
			}
		}

		if ( $changed ) {
			update_option( 'bcsend_settings', $settings );
		}
	}

	/**
	 * Public wrapper for migrate_settings so it can be called externally.
	 *
	 * @since 1.1.0
	 */
	public static function migrate_settings_public() {
		self::migrate_settings();
	}

	/**
	 * Public wrapper for capability registration so upgrades self-heal.
	 *
	 * @return void
	 */
	public static function register_capabilities_public() {
		self::register_capabilities();
	}

	/**
	 * Ensure campaign schema includes required upgraded columns.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_upgrade_schema_public() {
		global $wpdb;

		$table       = $wpdb->prefix . 'bcsend_campaigns';
		$product_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'product_id' ) );

		if ( empty( $product_col ) ) {
			self::create_tables();
		}

		// v2.0.0: Add send_push column.
		$send_email_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'send_email' ) );

		if ( empty( $send_email_col ) ) {
			self::create_tables();
		}

		$send_push_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'send_push' ) );

		if ( empty( $send_push_col ) ) {
			self::create_tables();
		}

		// v2.0.2: Add reply_to column.
		$reply_to_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'reply_to' ) );

		if ( empty( $reply_to_col ) ) {
			self::create_tables();
		}

		// v2.2.0: Add content_library column.
		$cl_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'content_library' ) );

		if ( empty( $cl_col ) ) {
			self::create_tables();
		}

		$send_social_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'send_social' ) );
		if ( empty( $send_social_col ) ) {
			self::create_tables();
		}

		$push_segment_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'push_segment_id' ) );
		if ( empty( $push_segment_col ) ) {
			self::create_tables();
		}

		$push_target_type_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'push_target_type' ) );
		if ( empty( $push_target_type_col ) ) {
			self::create_tables();
		}

		$social_status_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'social_status' ) );
		if ( empty( $social_status_col ) ) {
			self::create_tables();
		}

		$social_post_mode_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'social_post_mode' ) );
		if ( empty( $social_post_mode_col ) ) {
			self::create_tables();
		}

		$email_log_table  = $wpdb->prefix . 'bcsend_email_log';
		$email_log_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $email_log_table ) );

		if ( empty( $email_log_exists ) ) {
			self::create_tables();
		}

		$social_posts_table  = $wpdb->prefix . 'bcsend_social_posts';
		$social_posts_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $social_posts_table ) );

		if ( empty( $social_posts_exists ) ) {
			self::create_tables();
		}

		$link_mode_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$social_posts_table} LIKE %s", 'link_mode' ) );
		if ( empty( $link_mode_col ) ) {
			self::create_tables();
		}

		$link_url_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$social_posts_table} LIKE %s", 'link_url' ) );
		if ( empty( $link_url_col ) ) {
			self::create_tables();
		}

		$link_label_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$social_posts_table} LIKE %s", 'link_label' ) );
		if ( empty( $link_label_col ) ) {
			self::create_tables();
		}

		$wpdb->query( "UPDATE {$social_posts_table} SET link_mode = 'none' WHERE link_mode IS NULL OR link_mode = ''" );

		$subscribers_table  = $wpdb->prefix . 'bcsend_subscribers';
		$subscribers_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subscribers_table ) );

		if ( empty( $subscribers_exists ) ) {
			self::create_tables();
		}
	}

	/**
	 * Register custom capabilities on the administrator role.
	 */
	private static function register_capabilities() {
		$admin_role = get_role( 'administrator' );

		// No-op when the admin role already carries the caps, so the self-heal
		// on every admin_init stays cheap.
		if ( $admin_role && ! $admin_role->has_cap( 'operate_bcsend_campaigns' ) ) {
			$admin_role->add_cap( 'manage_bcsend' );
			$admin_role->add_cap( 'edit_bcsend_campaigns' );
			$admin_role->add_cap( 'operate_bcsend_campaigns' );
			$admin_role->add_cap( 'view_bcsend_logs' );
		}
	}
}
