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
	 * Side effects collected during sanitize(), executed once after save.
	 *
	 * sanitize() must stay pure - WordPress can legally run it twice on one
	 * save - so it only records what the save implies here, and
	 * handle_settings_save() (pre_update_option filter, fires exactly once
	 * per save) performs the remote syncs and capability changes.
	 *
	 * @var array
	 */
	private $pending_save_actions = array();

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

		// The Settings page menu is gated on the plugin's own manage_bcsend
		// capability, but options.php enforces manage_options for the group
		// unless told otherwise - without this filter a delegated manager can
		// edit every field yet always gets "Sorry, you are not allowed..."
		// on save.
		add_filter(
			'option_page_capability_' . self::SETTINGS_GROUP,
			static function () {
				return 'manage_bcsend';
			}
		);

		// Post-save side effects. pre_update_option_{option} fires exactly
		// once per save - even when the stored value is unchanged, which
		// matters because the campaign-access list is not part of the option.
		add_filter( 'pre_update_option_' . self::OPTION_NAME, array( $this, 'handle_settings_save' ), 10, 2 );
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
			isset( $input['default_subscriber_lists'] ) ? $input['default_subscriber_lists'] : array()
		);
		$sanitized['subscribe_terms_url']       = isset( $input['subscribe_terms_url'] ) ? esc_url_raw( $input['subscribe_terms_url'] ) : home_url( '/terms-of-service/' );
		$sanitized['subscribe_terms_text']      = isset( $input['subscribe_terms_text'] ) ? sanitize_text_field( $input['subscribe_terms_text'] ) : __( 'By signing up, you agree to our', 'beacon-campaign-sender' );
		$sanitized['subscribe_terms_link_text'] = isset( $input['subscribe_terms_link_text'] ) ? sanitize_text_field( $input['subscribe_terms_link_text'] ) : __( 'Terms of Service', 'beacon-campaign-sender' );
		// Strip tags so custom CSS cannot break out of the <style> wrapper; line breaks are preserved.
		$sanitized['subscribe_custom_css'] = isset( $input['subscribe_custom_css'] ) ? trim( wp_strip_all_tags( (string) $input['subscribe_custom_css'] ) ) : '';
		$sanitized['subscribe_enabled']    = isset( $input['subscribe_enabled'] ) ? 1 : 0;

		// Push settings.
		$sanitized['push_mode']                     = isset( $input['push_mode'] ) && in_array( $input['push_mode'], array( 'auto', 'manual' ), true ) ? $input['push_mode'] : 'auto';
		$sanitized['firebase_service_account_json'] = $this->sanitize_secret_field( $input, $existing, 'firebase_service_account_json', 'textarea' );
		$sanitized['firebase_project_id']           = isset( $input['firebase_project_id'] ) ? sanitize_text_field( $input['firebase_project_id'] ) : '';

		// Web push settings.
		$sanitized['webpush_enabled'] = isset( $input['webpush_enabled'] ) ? 1 : 0;
		$sanitized['webpush_bell']    = isset( $input['webpush_bell'] ) ? 1 : 0;

		$web_config = isset( $input['firebase_web_config'] ) ? trim( (string) wp_unslash( $input['firebase_web_config'] ) ) : '';
		if ( '' !== $web_config && null === json_decode( $web_config, true ) ) {
			$web_config = '';
		}
		$sanitized['firebase_web_config'] = wp_kses( $web_config, array() );
		$sanitized['firebase_vapid_key']  = isset( $input['firebase_vapid_key'] ) ? sanitize_text_field( $input['firebase_vapid_key'] ) : '';

		// Zernio settings.
		$sanitized['zernio_api_key']         = $this->sanitize_secret_field( $input, $existing, 'zernio_api_key' );
		$sanitized['zernio_profile_id']      = isset( $input['zernio_profile_id'] ) ? sanitize_text_field( $input['zernio_profile_id'] ) : '';
		$sanitized['zernio_webhook_secret']  = $this->sanitize_secret_field( $input, $existing, 'zernio_webhook_secret' );
		$sanitized['zernio_webhook_enabled'] = isset( $input['zernio_webhook_enabled'] ) ? 1 : 0;
		$sanitized['zernio_post_mode']       = isset( $input['zernio_post_mode'] ) && in_array( $input['zernio_post_mode'], array( 'single', 'per_platform' ), true ) ? $input['zernio_post_mode'] : 'single';

		// Composer social defaults.
		$sanitized['social_default_enabled']  = isset( $input['social_default_enabled'] ) ? 1 : 0;
		$sanitized['social_default_accounts'] = array();
		if ( isset( $input['social_default_accounts'] ) && is_array( $input['social_default_accounts'] ) ) {
			$default_accounts = array_values(
				array_filter(
					array_map( 'sanitize_text_field', array_map( 'strval', $input['social_default_accounts'] ) )
				)
			);

			// The composer supports one default account per platform - keep
			// the first submitted account for each platform (accounts whose
			// platform is unknown are kept as-is).
			$account_platforms = array();
			foreach ( (array) get_option( 'bcsend_zernio_accounts', array() ) as $zernio_account ) {
				if ( ! is_array( $zernio_account ) || empty( $zernio_account['platform'] ) ) {
					continue;
				}
				foreach ( array( 'id', '_id', 'accountId', 'account_id', 'uuid' ) as $id_key ) {
					if ( isset( $zernio_account[ $id_key ] ) && '' !== (string) $zernio_account[ $id_key ] ) {
						$account_platforms[ (string) $zernio_account[ $id_key ] ] = (string) $zernio_account['platform'];
						break;
					}
				}
			}

			$used_platforms = array();
			foreach ( $default_accounts as $default_account ) {
				$platform = isset( $account_platforms[ $default_account ] ) ? $account_platforms[ $default_account ] : '';

				if ( '' !== $platform && isset( $used_platforms[ $platform ] ) ) {
					continue;
				}

				if ( '' !== $platform ) {
					$used_platforms[ $platform ] = true;
				}

				$sanitized['social_default_accounts'][] = $default_account;
			}
		}

		// Brand Voice.
		$sanitized['brand_voice'] = isset( $input['brand_voice'] ) ? sanitize_textarea_field( $input['brand_voice'] ) : '';

		// The old Base Template setting was retired in favor of the default
		// template on the Templates screen (existing values are migrated to a
		// real template on upgrade).

		// AI settings.
		$sanitized['ai_provider'] = isset( $input['ai_provider'] ) && in_array( $input['ai_provider'], array( 'anthropic', 'openai' ), true )
			? $input['ai_provider']
			: 'anthropic';

		$sanitized['anthropic_api_key'] = $this->sanitize_secret_field( $input, $existing, 'anthropic_api_key' );

		// Model validation is driven by the catalog - the single authority on
		// available models. An unknown submitted value falls back to the
		// previously saved selection (still catalog-validated) rather than
		// silently resetting an admin's choice.
		$saved_anthropic              = isset( $existing['anthropic_model'] ) && in_array( $existing['anthropic_model'], Bcsend_Model_Catalog::allowed_ids( 'anthropic' ), true )
			? $existing['anthropic_model']
			: Bcsend_Model_Catalog::default_model( 'anthropic' );
		$sanitized['anthropic_model'] = isset( $input['anthropic_model'] ) && in_array( $input['anthropic_model'], Bcsend_Model_Catalog::allowed_ids( 'anthropic' ), true )
			? $input['anthropic_model']
			: $saved_anthropic;

		$sanitized['openai_api_key'] = $this->sanitize_secret_field( $input, $existing, 'openai_api_key' );

		$saved_openai              = isset( $existing['openai_model'] ) && in_array( $existing['openai_model'], Bcsend_Model_Catalog::allowed_ids( 'openai' ), true )
			? $existing['openai_model']
			: Bcsend_Model_Catalog::default_model( 'openai' );
		$sanitized['openai_model'] = isset( $input['openai_model'] ) && in_array( $input['openai_model'], Bcsend_Model_Catalog::allowed_ids( 'openai' ), true )
			? $input['openai_model']
			: $saved_openai;

		// Fable -> Opus refusal fallback (default on, disclosed in the UI).
		$sanitized['fable_fallback_enabled'] = isset( $input['fable_fallback_enabled'] ) ? 1 : 0;

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

		// Record (never perform) the save's side effects: remote syncs and
		// capability changes run once in handle_settings_save() after the
		// values are stored. Stashing is idempotent, so a double sanitize
		// pass cannot double-fire anything.
		$this->pending_save_actions = array(
			'brevo'  => array(
				'enabled' => ! empty( $sanitized['smtp_routing_enabled'] ),
				'email'   => $sanitized['brevo_sender_email'],
				'api_key' => $this->get_plain_secret_value( $sanitized['brevo_api_key'] ),
			),
			'zernio' => array(
				'api_key'        => $this->get_plain_secret_value( $sanitized['zernio_api_key'] ),
				'webhook_secret' => $this->get_plain_secret_value( $sanitized['zernio_webhook_secret'] ),
				'enabled'        => ! empty( $sanitized['zernio_webhook_enabled'] ) ? 1 : 0,
			),
		);

		if ( ! empty( $input['campaign_access_save'] ) ) {
			$this->pending_save_actions['campaign_access'] = array(
				'user_ids' => isset( $input['campaign_access_user_ids'] )
					? array_map( 'absint', (array) $input['campaign_access_user_ids'] )
					: array(),
			);
		}

		// Encrypt sensitive fields.
		$sanitized = Bcsend_Encryption::encrypt_settings( $sanitized );

		return $sanitized;
	}

	/**
	 * Execute the side effects of a settings save, exactly once.
	 *
	 * Runs on pre_update_option_bcsend_settings with the sanitized new value
	 * and the stored old value. Remote syncs only fire when the fields they
	 * depend on actually changed, so saving unrelated settings no longer
	 * blocks on Zernio/Brevo HTTP calls.
	 *
	 * @param array $value     New (sanitized, encrypted) settings value.
	 * @param mixed $old_value Previously stored settings value.
	 * @return array The new value, unmodified.
	 */
	public function handle_settings_save( $value, $old_value ) {
		$actions                    = $this->pending_save_actions;
		$this->pending_save_actions = array();

		// A programmatic update_option() that did not pass through sanitize()
		// carries no recorded actions - nothing to do.
		if ( empty( $actions ) ) {
			return $value;
		}

		$old = is_array( $old_value ) ? $old_value : array();

		// Brevo sender-domain verification - only when its inputs changed.
		if ( ! empty( $actions['brevo']['enabled'] ) && ! empty( $actions['brevo']['email'] ) && ! empty( $actions['brevo']['api_key'] ) ) {
			$old_email             = isset( $old['brevo_sender_email'] ) ? $old['brevo_sender_email'] : '';
			$old_key               = ! empty( $old['brevo_api_key'] ) ? (string) Bcsend_Encryption::decrypt( $old['brevo_api_key'] ) : '';
			$routing_newly_enabled = empty( $old['smtp_routing_enabled'] );

			if ( $actions['brevo']['email'] !== $old_email || $actions['brevo']['api_key'] !== $old_key || $routing_newly_enabled ) {
				$this->check_sender_domain_verification( $actions['brevo']['email'], $actions['brevo']['api_key'] );
			}
		}

		// Zernio webhook sync - only when the Zernio fields actually changed.
		if ( ! empty( $actions['zernio']['api_key'] ) && ! empty( $actions['zernio']['webhook_secret'] ) ) {
			$old_api     = ! empty( $old['zernio_api_key'] ) ? (string) Bcsend_Encryption::decrypt( $old['zernio_api_key'] ) : '';
			$old_secret  = ! empty( $old['zernio_webhook_secret'] ) ? (string) Bcsend_Encryption::decrypt( $old['zernio_webhook_secret'] ) : '';
			$old_enabled = ! empty( $old['zernio_webhook_enabled'] ) ? 1 : 0;

			$zernio_changed = $actions['zernio']['api_key'] !== $old_api
				|| $actions['zernio']['webhook_secret'] !== $old_secret
				|| $actions['zernio']['enabled'] !== $old_enabled;

			if ( $zernio_changed ) {
				$webhook_sync = Bcsend_Plugin::sync_zernio_webhook_settings(
					array(
						'zernio_api_key'         => $actions['zernio']['api_key'],
						'zernio_webhook_secret'  => $actions['zernio']['webhook_secret'],
						'zernio_webhook_enabled' => $actions['zernio']['enabled'],
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
		}

		if ( isset( $actions['campaign_access'] ) ) {
			$this->sync_campaign_access_users( $actions['campaign_access']['user_ids'] );
		}

		return $value;
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
		// A stored secret that can no longer be decrypted (AUTH_KEY changed,
		// site moved) counts as absent: the settings page cannot show its
		// "Replace" checkbox, so keeping it would silently discard the new
		// value the user just typed.
		$has_existing = ! empty( $existing[ $field ] ) && ! self::is_unreadable_secret( $existing[ $field ] );
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
	 * Whether a stored secret is encrypted but can no longer be decrypted.
	 *
	 * @since 1.1.2
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function is_unreadable_secret( $value ) {
		return is_string( $value )
			&& '' !== $value
			&& Bcsend_Encryption::is_encrypted( $value )
			&& '' === (string) Bcsend_Encryption::decrypt( $value );
	}

	/**
	 * Human labels of saved secrets that can no longer be decrypted.
	 *
	 * Shown on the Settings page so the user knows exactly which keys to
	 * enter again after a WordPress security-key change or site move.
	 *
	 * @since 1.1.2
	 *
	 * @return string[] Field key => label.
	 */
	public static function get_unreadable_secret_labels() {
		$labels = array(
			'brevo_api_key'                 => __( 'Brevo API key', 'beacon-campaign-sender' ),
			'anthropic_api_key'             => __( 'Anthropic API key', 'beacon-campaign-sender' ),
			'openai_api_key'                => __( 'OpenAI API key', 'beacon-campaign-sender' ),
			'firebase_service_account_json' => __( 'Firebase service account JSON', 'beacon-campaign-sender' ),
			'zernio_api_key'                => __( 'Zernio API key', 'beacon-campaign-sender' ),
			'zernio_webhook_secret'         => __( 'Zernio webhook secret', 'beacon-campaign-sender' ),
		);
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$found  = array();
		foreach ( self::$encrypted_fields as $field ) {
			if ( isset( $stored[ $field ] ) && self::is_unreadable_secret( $stored[ $field ] ) ) {
				$found[ $field ] = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
			}
		}
		return $found;
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
	 * @param array $selected_ids User IDs that should have campaign access.
	 * @return void
	 */
	private function sync_campaign_access_users( $selected_ids ) {
		if ( ! current_user_can( 'manage_bcsend' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Plugin capability registered in Bcsend_Activator.
			return;
		}

		$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_ids ) ) ) );

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
			'webpush_enabled'               => 0,
			'webpush_bell'                  => 0,
			'firebase_web_config'           => '',
			'firebase_vapid_key'            => '',
			'zernio_api_key'                => '',
			'zernio_profile_id'             => '',
			'zernio_webhook_secret'         => '',
			'zernio_webhook_enabled'        => 0,
			'zernio_post_mode'              => 'single',
			'social_default_enabled'        => 0,
			'social_default_accounts'       => array(),
			'brand_voice'                   => '',
			'ai_provider'                   => 'anthropic',
			'anthropic_api_key'             => '',
			'anthropic_model'               => Bcsend_Model_Catalog::default_model( 'anthropic' ),
			'fable_fallback_enabled'        => 1,
			'openai_api_key'                => '',
			'openai_model'                  => Bcsend_Model_Catalog::default_model( 'openai' ),
			'smtp_routing_enabled'          => 0,
			'smtp_force_from'               => 0,
			'abilities_bridge_enabled'      => 0,
			'log_retention_days'            => 30,
			'email_log_detail_level'        => 'minimal',
			'default_subscriber_lists'      => array(),
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
			return array();
		}

		$ints = array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );

		return ! empty( $ints ) ? $ints : array();
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
