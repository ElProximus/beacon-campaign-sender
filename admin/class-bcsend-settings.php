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
		$settings = self::get_settings();
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
		$sanitized['brevo_api_key']             = isset( $input['brevo_api_key'] ) ? sanitize_text_field( $input['brevo_api_key'] ) : '';
		$sanitized['brevo_sender_name']         = isset( $input['brevo_sender_name'] ) ? sanitize_text_field( $input['brevo_sender_name'] ) : '';
		$sanitized['brevo_sender_email']        = isset( $input['brevo_sender_email'] ) ? sanitize_email( $input['brevo_sender_email'] ) : '';
		$sanitized['default_subscriber_lists']  = $this->sanitize_integer_list(
			isset( $input['default_subscriber_lists'] ) ? $input['default_subscriber_lists'] : array( 14 )
		);
		$sanitized['subscribe_terms_url']       = isset( $input['subscribe_terms_url'] ) ? esc_url_raw( $input['subscribe_terms_url'] ) : home_url( '/terms-of-service/' );
		$sanitized['subscribe_terms_text']      = isset( $input['subscribe_terms_text'] ) ? sanitize_text_field( $input['subscribe_terms_text'] ) : __( 'By signing up, you agree to our', 'beacon-campaign-sender' );
		$sanitized['subscribe_terms_link_text'] = isset( $input['subscribe_terms_link_text'] ) ? sanitize_text_field( $input['subscribe_terms_link_text'] ) : __( 'Terms of Service', 'beacon-campaign-sender' );

		// Push settings.
		$sanitized['push_mode']                     = isset( $input['push_mode'] ) && in_array( $input['push_mode'], array( 'auto', 'manual' ), true ) ? $input['push_mode'] : 'auto';
		$sanitized['firebase_service_account_json'] = isset( $input['firebase_service_account_json'] ) ? sanitize_textarea_field( $input['firebase_service_account_json'] ) : '';
		$sanitized['firebase_project_id']           = isset( $input['firebase_project_id'] ) ? sanitize_text_field( $input['firebase_project_id'] ) : '';

		// Zernio settings.
		$sanitized['zernio_api_key']         = isset( $input['zernio_api_key'] ) ? sanitize_text_field( $input['zernio_api_key'] ) : '';
		$sanitized['zernio_profile_id']      = isset( $input['zernio_profile_id'] ) ? sanitize_text_field( $input['zernio_profile_id'] ) : '';
		$sanitized['zernio_webhook_secret']  = isset( $input['zernio_webhook_secret'] ) ? sanitize_text_field( $input['zernio_webhook_secret'] ) : '';
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

		$sanitized['anthropic_api_key'] = isset( $input['anthropic_api_key'] ) ? sanitize_text_field( $input['anthropic_api_key'] ) : '';

		$allowed_models               = array( 'claude-sonnet-4-6', 'claude-opus-4-6', 'claude-haiku-4-5-20251001' );
		$sanitized['anthropic_model'] = isset( $input['anthropic_model'] ) && in_array( $input['anthropic_model'], $allowed_models, true )
			? $input['anthropic_model']
			: 'claude-sonnet-4-6';

		$sanitized['openai_api_key'] = isset( $input['openai_api_key'] ) ? sanitize_text_field( $input['openai_api_key'] ) : '';

		$allowed_openai_models     = array( 'gpt-5.4', 'gpt-5.2', 'gpt-5-mini', 'gpt-4.1-mini' );
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

		// Preserve existing encrypted values if fields were left blank (password fields).
		foreach ( self::$encrypted_fields as $field ) {
			if ( empty( $sanitized[ $field ] ) && ! empty( $existing[ $field ] ) ) {
				$sanitized[ $field ] = $existing[ $field ];
			}
		}

		$effective_api_key = isset( $input['brevo_api_key'] ) ? sanitize_text_field( $input['brevo_api_key'] ) : '';
		if ( '' === $effective_api_key && ! empty( $existing['brevo_api_key'] ) ) {
			$effective_api_key = (string) Bcsend_Encryption::decrypt( $existing['brevo_api_key'] );
		}

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

		$effective_zernio_api_key = isset( $input['zernio_api_key'] ) ? sanitize_text_field( $input['zernio_api_key'] ) : '';
		if ( '' === $effective_zernio_api_key && ! empty( $existing['zernio_api_key'] ) ) {
			$effective_zernio_api_key = (string) Bcsend_Encryption::decrypt( $existing['zernio_api_key'] );
		}

		$effective_zernio_webhook_secret = isset( $input['zernio_webhook_secret'] ) ? sanitize_text_field( $input['zernio_webhook_secret'] ) : '';
		if ( '' === $effective_zernio_webhook_secret && ! empty( $existing['zernio_webhook_secret'] ) ) {
			$effective_zernio_webhook_secret = (string) Bcsend_Encryption::decrypt( $existing['zernio_webhook_secret'] );
		}

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

		return $sanitized;
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
