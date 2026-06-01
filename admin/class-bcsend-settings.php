<?php
/**
 * Settings controller for Beacon Campaign Sender.
 *
 * Handles the plugin settings page including reading, saving,
 * encrypting, and decrypting sensitive configuration values.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Settings
 *
 * @since 1.0.0
 */
class Bcsend_Settings {

	/**
	 * Option name in wp_options.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'bcsend_settings';

	/**
	 * Settings group used by the Settings API.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'bcsend_settings_group';

	/**
	 * Fields that require encryption before storage.
	 *
	 * @var array
	 */
	private static $encrypted_fields = array(
		'brevo_api_key',
		'anthropic_api_key',
		'openai_api_key',
		'firebase_service_account_json',
		'zernio_api_key',
		'zernio_webhook_secret',
	);

	/**
	 * Register settings with WordPress Settings API.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * Loads current settings, decrypts sensitive values for display,
	 * and includes the settings view template.
	 *
	 * @since 1.0.0
	 */
	public function render() {
		$settings     = self::get_settings();
		$access_users = self::get_campaign_access_users();
		include plugin_dir_path( __FILE__ ) . 'views/settings.php';
	}

	/**
	 * Sanitize settings input before saving.
	 *
	 * Sanitizes each field according to its type and encrypts
	 * sensitive fields before storage.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Raw settings input from the form.
	 *
	 * @return array Sanitized and encrypted settings.
	 */
	public function sanitize( $input ) {
		$sanitized = array();
		$existing  = get_option( self::OPTION_NAME, array() );

		// Brevo settings.
		$sanitized['brevo_api_key']             = $this->sanitize_secret_field( $input, $existing, 'brevo_api_key' );
		$sanitized['brevo_sender_name']         = isset( $input['brevo_sender_name'] ) ? sanitize_text_field( $input['brevo_sender_name'] ) : '';
		$sanitized['brevo_sender_email']        = isset( $input['brevo_sender_email'] ) ? sanitize_email( $input['brevo_sender_email'] ) : '';
		$sanitized['reply_to_email']            = isset( $input['reply_to_email'] ) ? sanitize_email( $input['reply_to_email'] ) : '';
		$sanitized['default_subscriber_lists']  = $this->sanitize_integer_list(
			isset( $input['default_subscriber_lists'] ) ? $input['default_subscriber_lists'] : array( 14 )
		);
		$sanitized['subscribe_terms_url']       = isset( $input['subscribe_terms_url'] ) ? esc_url_raw( $input['subscribe_terms_url'] ) : home_url( '/terms-of-service/' );
		$sanitized['subscribe_terms_text']      = isset( $input['subscribe_terms_text'] ) ? sanitize_text_field( $input['subscribe_terms_text'] ) : __( 'By signing up, you agree to our', 'beacon-campaign-sender' );
		$sanitized['subscribe_terms_link_text'] = isset( $input['subscribe_terms_link_text'] ) ? sanitize_text_field( $input['subscribe_terms_link_text'] ) : __( 'Terms of Service', 'beacon-campaign-sender' );
		// Strip tags so custom CSS cannot break out of the <style> wrapper; line breaks are preserved.
		$sanitized['subscribe_custom_css']      = isset( $input['subscribe_custom_css'] ) ? trim( wp_strip_all_tags( (string) $input['subscribe_custom_css'] ) ) : '';
		$sanitized['subscribe_enabled']         = isset( $input['subscribe_enabled'] ) ? 1 : 0;

		// Push settings.
		$sanitized['push_mode']                     = isset( $input['push_mode'] ) && in_array( $input['push_mode'], array( 'auto', 'manual' ), true ) ? $input['push_mode'] : 'auto';
		$sanitized['firebase_service_account_json'] = $this->sanitize_secret_field( $input, $existing, 'firebase_service_account_json', 'textarea' );
		$sanitized['firebase_project_id']           = isset( $input['firebase_project_id'] ) ? sanitize_text_field( $input['firebase_project_id'] ) : '';

		// Zernio settings.
		$sanitized['zernio_api_key']         = $this->sanitize_secret_field( $input, $existing, 'zernio_api_key' );
		$sanitized['zernio_profile_id']      = isset( $input['zernio_profile_id'] ) ? sanitize_text_field( $input['zernio_profile_id'] ) : '';
		$sanitized['zernio_webhook_secret']  = $this->sanitize_secret_field( $input, $existing, 'zernio_webhook_secret' );
		$sanitized['zernio_webhook_enabled'] = isset( $input['zernio_webhook_enabled'] ) ? 1 : 0;
		$sanitized['zernio_post_mode']       = isset( $input['zernio_post_mode'] ) && in_array( $input['zernio_post_mode'], array( 'single', 'per_platform' ), true ) ? $input['zernio_post_mode'] : 'single';

		// Brand Voice.
		$sanitized['brand_voice'] = isset( $input['brand_voice'] ) ? sanitize_textarea_field( $input['brand_voice'] ) : '';

		// Base Template.
		$sanitized['base_template'] = isset( $input['base_template'] ) ? bcsend_kses_email( $input['base_template'] ) : '';

		// AI settings.
		$sanitized['ai_provider'] = isset( $input['ai_provider'] ) && in_array( $input['ai_provider'], array( 'anthropic', 'openai' ), true )
			? $input['ai_provider']
			: 'anthropic';

		$sanitized['anthropic_api_key'] = $this->sanitize_secret_field( $input, $existing, 'anthropic_api_key' );

		$allowed_models               = array( 'claude-opus-4-8', 'claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001', 'claude-opus-4-6' );
		$sanitized['anthropic_model'] = isset( $input['anthropic_model'] ) && in_array( $input['anthropic_model'], $allowed_models, true )
			? $input['anthropic_model']
			: 'claude-sonnet-4-6';

		$sanitized['openai_api_key'] = $this->sanitize_secret_field( $input, $existing, 'openai_api_key' );

		$allowed_openai_models     = array( 'gpt-5.5', 'gpt-5.4', 'gpt-5.2', 'gpt-5-mini' );
		$sanitized['openai_model'] = isset( $input['openai_model'] ) && in_array( $input['openai_model'], $allowed_openai_models, true )
			? $input['openai_model']
			: 'gpt-5.4';

		// SMTP routing.
		$sanitized['smtp_routing_enabled'] = isset( $input['smtp_routing_enabled'] ) ? 1 : 0;
		$sanitized['smtp_force_from']      = isset( $input['smtp_force_from'] ) ? 1 : 0;

		// Abilities Bridge.
		$sanitized['abilities_bridge_enabled'] = isset( $input['abilities_bridge_enabled'] ) ? 1 : 0;

		// Logs.
		$sanitized['log_retention_days'] = isset( $input['log_retention_days'] ) ? absint( $input['log_retention_days'] ) : 30;
		if ( 0 === $sanitized['log_retention_days'] ) {
			$sanitized['log_retention_days'] = 30;
		}
		$sanitized['email_log_detail_level'] = isset( $input['email_log_detail_level'] ) && in_array( $input['email_log_detail_level'], array( 'minimal', 'full' ), true )
			? $input['email_log_detail_level']
			: 'minimal';

		$effective_api_key = $this->get_plain_secret_value( $sanitized['brevo_api_key'] );

		if ( ! empty( $sanitized['smtp_routing_enabled'] ) && ! empty( $sanitized['brevo_sender_email'] ) && ! empty( $effective_api_key ) ) {
			$existing_email   = isset( $existing['brevo_sender_email'] ) ? $existing['brevo_sender_email'] : '';
			$existing_key_enc = isset( $existing['brevo_api_key'] ) ? $existing['brevo_api_key'] : '';
			$existing_key     = ! empty( $existing_key_enc ) ? (string) Bcsend_Encryption::decrypt( $existing_key_enc ) : '';

			$email_changed         = $sanitized['brevo_sender_email'] !== $existing_email;
			$key_changed           = $effective_api_key !== $existing_key;
			$routing_newly_enabled = empty( $existing['smtp_routing_enabled'] ) && ! empty( $sanitized['smtp_routing_enabled'] );

			if ( $email_changed || $key_changed || $routing_newly_enabled ) {
				$this->check_sender_domain_verification( $sanitized['brevo_sender_email'], $effective_api_key );
			}
		}

		$effective_zernio_api_key         = $this->get_plain_secret_value( $sanitized['zernio_api_key'] );
		$effective_zernio_webhook_secret  = $this->get_plain_secret_value( $sanitized['zernio_webhook_secret'] );

		if ( ! empty( $effective_zernio_api_key ) && ! empty( $effective_zernio_webhook_secret ) ) {
			$webhook_sync = Bcsend_Plugin::sync_zernio_webhook_settings(
				array(
					'zernio_api_key'         => $effective_zernio_api_key,
					'zernio_webhook_secret'  => $effective_zernio_webhook_secret,
					'zernio_webhook_enabled' => ! empty( $sanitized['zernio_webhook_enabled'] ) ? 1 : 0,
				)
			);

			if ( is_wp_error( $webhook_sync ) ) {
				add_settings_error(
					'bcsend_settings',
					'bcsend_zernio_webhook_sync_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Settings saved, but Zernio webhook sync failed: %s', 'beacon-campaign-sender' ),
						$webhook_sync->get_error_message()
					),
					'error'
				);
			} else {
				add_settings_error(
					'bcsend_settings',
					'bcsend_zernio_webhook_sync_success',
					__( 'Settings saved and Zernio webhook synced successfully.', 'beacon-campaign-sender' ),
					'success'
				);
			}
		}

		// Encrypt sensitive fields.
		$sanitized = Bcsend_Encryption::encrypt_settings( $sanitized );

		if ( ! empty( $input['campaign_access_save'] ) ) {
			$this->sync_campaign_access_users( $input );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a saved secret field using explicit replacement intent.
	 *
	 * @param array  $input    Raw settings input.
	 * @param array  $existing Existing stored settings.
	 * @param string $field    Secret field key.
	 * @param string $type     Sanitizer type: text or textarea.
	 * @return string
	 */
	private function sanitize_secret_field( $input, $existing, $field, $type = 'text' ) {
		$has_existing = ! empty( $existing[ $field ] );
		$replace_key  = 'replace_' . $field;
		$should_save  = ! empty( $input[ $replace_key ] ) || ! $has_existing;

		if ( ! $should_save ) {
			return $has_existing ? $existing[ $field ] : '';
		}

		$value = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
		$value = 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

		if ( '' === trim( $value ) && $has_existing ) {
			return $existing[ $field ];
		}

		return $value;
	}

	/**
	 * Return a plaintext secret from a sanitized value.
	 *
	 * @param string $value Plaintext or encrypted value.
	 * @return string
	 */
	private function get_plain_secret_value( $value ) {
		return ! empty( $value ) ? (string) Bcsend_Encryption::decrypt( $value ) : '';
	}

	/**
	 * Get users that should appear on the campaign access settings tab.
	 *
	 * @return array
	 */
	public static function get_campaign_access_users() {
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all_with_meta',
			)
		);

		$rows = array();
		foreach ( $users as $user ) {
			$is_full_access = self::user_has_full_plugin_access( $user );
			$is_eligible    = self::user_is_campaign_access_eligible( $user );
			$is_assigned    = self::user_has_campaign_access( $user );

			if ( ! $is_full_access && ! $is_eligible && ! $is_assigned ) {
				continue;
			}

			$rows[] = array(
				'id'             => (int) $user->ID,
				'name'           => $user->display_name ? $user->display_name : $user->user_login,
				'email'          => $user->user_email,
				'roles'          => self::format_user_roles( $user ),
				'is_full_access' => $is_full_access,
				'is_eligible'    => $is_eligible,
				'is_assigned'    => $is_assigned,
			);
		}

		return $rows;
	}

	/**
	 * Determine whether a user has full Beacon admin access.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	private static function user_has_full_plugin_access( $user ) {
		return user_can( $user, 'manage_bcsend' ) || user_can( $user, 'manage_options' );
	}

	/**
	 * Determine whether a user can be assigned campaign access.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	private static function user_is_campaign_access_eligible( $user ) {
		return ! self::user_has_full_plugin_access( $user )
			&& user_can( $user, 'edit_posts' )
			&& user_can( $user, 'upload_files' );
	}

	/**
	 * Determine whether a user has assigned campaign access.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	private static function user_has_campaign_access( $user ) {
		return user_can( $user, 'edit_bcsend_campaigns' ) && user_can( $user, 'operate_bcsend_campaigns' );
	}

	/**
	 * Format a user's roles for display.
	 *
	 * @param WP_User $user User object.
	 * @return string
	 */
	private static function format_user_roles( $user ) {
		$role_names = wp_roles()->role_names;
		$labels     = array();

		foreach ( (array) $user->roles as $role ) {
			$labels[] = isset( $role_names[ $role ] ) ? translate_user_role( $role_names[ $role ] ) : $role;
		}

		return ! empty( $labels ) ? implode( ', ', $labels ) : __( 'No role', 'beacon-campaign-sender' );
	}

	/**
	 * Add or remove per-user campaign capabilities from the Access settings tab.
	 *
	 * @param array $input Raw settings input.
	 * @return void
	 */
	private function sync_campaign_access_users( $input ) {
		if ( ! current_user_can( 'manage_bcsend' ) ) {
			return;
		}

		$selected_ids = isset( $input['campaign_access_user_ids'] )
			? array_map( 'absint', (array) $input['campaign_access_user_ids'] )
			: array();
		$selected_ids = array_values( array_unique( array_filter( $selected_ids ) ) );

		$updated_count = 0;
		foreach ( self::get_campaign_access_users() as $row ) {
			$user = get_userdata( $row['id'] );
			if ( ! $user || $row['is_full_access'] ) {
				continue;
			}

			$should_have_access = $row['is_eligible'] && in_array( $row['id'], $selected_ids, true );
			$has_access         = $row['is_assigned'];

			if ( $should_have_access && ! $has_access ) {
				$user->add_cap( 'edit_bcsend_campaigns' );
				$user->add_cap( 'operate_bcsend_campaigns' );
				++$updated_count;
			} elseif ( ! $should_have_access && $has_access ) {
				$user->remove_cap( 'edit_bcsend_campaigns' );
				$user->remove_cap( 'operate_bcsend_campaigns' );
				++$updated_count;
			}
		}

		add_settings_error(
			'bcsend_settings',
			'bcsend_campaign_access_saved',
			sprintf(
				/* translators: %d: number of users updated */
				_n( 'Campaign access saved. %d user updated.', 'Campaign access saved. %d users updated.', $updated_count, 'beacon-campaign-sender' ),
				$updated_count
			),
			'success'
		);
	}

	/**
	 * Get decrypted plugin settings.
	 *
	 * Retrieves settings from the database and decrypts all
	 * sensitive fields for use in code.
	 *
	 * @since 1.0.0
	 *
	 * @return array Decrypted settings array.
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );

		$defaults = array(
			'brevo_api_key'                 => '',
			'brevo_sender_name'             => '',
			'brevo_sender_email'            => '',
			'reply_to_email'                => '',
			'push_mode'                     => 'auto',
			'firebase_service_account_json' => '',
			'firebase_project_id'           => '',
			'zernio_api_key'                => '',
			'zernio_profile_id'             => '',
			'zernio_webhook_secret'         => '',
			'zernio_webhook_enabled'        => 0,
			'zernio_post_mode'              => 'single',
			'brand_voice'                   => '',
			'base_template'                 => '',
			'ai_provider'                   => 'anthropic',
			'anthropic_api_key'             => '',
			'anthropic_model'               => 'claude-sonnet-4-6',
			'openai_api_key'                => '',
			'openai_model'                  => 'gpt-5.4',
			'smtp_routing_enabled'          => 0,
			'smtp_force_from'               => 0,
			'abilities_bridge_enabled'      => 0,
			'log_retention_days'            => 30,
			'email_log_detail_level'        => 'minimal',
			'default_subscriber_lists'      => array( 14 ),
			'subscribe_terms_url'           => home_url( '/terms-of-service/' ),
			'subscribe_terms_text'          => __( 'By signing up, you agree to our', 'beacon-campaign-sender' ),
			'subscribe_terms_link_text'     => __( 'Terms of Service', 'beacon-campaign-sender' ),
			'subscribe_custom_css'          => '',
			'subscribe_enabled'             => 1,
		);

		$settings = wp_parse_args( $settings, $defaults );

		// Decrypt sensitive fields for display/use.
		foreach ( self::$encrypted_fields as $field ) {
			if ( ! empty( $settings[ $field ] ) ) {
				$settings[ $field ] = (string) Bcsend_Encryption::decrypt( $settings[ $field ] );
			}
		}

		return $settings;
	}

	/**
	 * Sanitize an integer list from text or array input.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private function sanitize_integer_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', trim( $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array( 14 );
		}

		$ints = array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );

		return ! empty( $ints ) ? $ints : array( 14 );
	}

	/**
	 * Cache whether the configured sender domain is verified in Brevo.
	 *
	 * @param string $sender_email Sender email address.
	 * @param string $api_key      Plaintext Brevo API key.
	 * @return void
	 */
	private function check_sender_domain_verification( $sender_email, $api_key ) {
		$sender_email = strtolower( trim( $sender_email ) );
		if ( ! is_email( $sender_email ) ) {
			return;
		}

		$domain = substr( strrchr( $sender_email, '@' ), 1 );
		if ( empty( $domain ) ) {
			return;
		}

		$brevo   = new Bcsend_Brevo_API( $api_key );
		$senders = $brevo->get_senders();

		if ( is_wp_error( $senders ) ) {
			return;
		}

		$verified_domains = array();

		foreach ( $senders as $sender ) {
			if ( empty( $sender['email'] ) || ! is_email( $sender['email'] ) ) {
				continue;
			}

			$sender_domain = substr( strrchr( strtolower( $sender['email'] ), '@' ), 1 );
			if ( ! empty( $sender_domain ) ) {
				$verified_domains[] = $sender_domain;
			}
		}

		$verified_domains = array_unique( $verified_domains );
		$status           = in_array( $domain, $verified_domains, true )
			? 'verified'
			: 'unverified:' . $domain;

		set_transient( Bcsend_Smtp::DOMAIN_STATUS_TRANSIENT, $status, 12 * HOUR_IN_SECONDS );
	}
}
