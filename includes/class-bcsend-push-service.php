<?php
/**
 * Firebase Cloud Messaging (FCM) push notification service for Beacon Campaign Sender.
 *
 * Handles push notification delivery via FCM v1 API with support for
 * auto-detection of BuddyBoss App Firebase credentials or manual configuration.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Push_Service
 *
 * Provides methods for sending push notifications to mobile app users via FCM.
 *
 * @since 1.0.0
 */
class Bcsend_Push_Service {

	/**
	 * Maximum number of retry attempts for transient failures.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Maximum number of tokens to process per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 500;

	/**
	 * Firebase project ID.
	 *
	 * @var string
	 */
	private $project_id;

	/**
	 * Parsed service account JSON credentials.
	 *
	 * @var array
	 */
	private $service_account;

	/**
	 * Source of push configuration ('auto' for BuddyBoss, 'manual' for settings).
	 *
	 * @var string
	 */
	private $push_source;

	/**
	 * Constructor.
	 *
	 * Auto-detects BuddyBoss Firebase credentials or falls back to manual configuration.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->project_id      = '';
		$this->service_account = array();
		$this->push_source     = '';

		// Attempt auto-detection from BuddyBoss App.
		if ( $this->detect_buddyboss_credentials() ) {
			return;
		}

		// Fall back to manual configuration.
		$this->load_manual_credentials();
	}

	/**
	 * Attempt to detect Firebase credentials from BuddyBoss App settings.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if BuddyBoss credentials were found and loaded.
	 */
	private function detect_buddyboss_credentials() {
		if ( ! Bcsend_Environment::get_instance()->is( 'buddyboss_present' ) ) {
			return false;
		}

		// Prefer BuddyBoss's own Firebase/Configure APIs when available.
		$file_path = $this->get_buddyboss_admin_key_path();
		if ( ! empty( $file_path ) ) {
			$parsed = $this->load_service_account_from_file( $file_path );

			if ( is_array( $parsed ) && isset( $parsed['project_id'], $parsed['private_key'], $parsed['client_email'] ) ) {
				$this->service_account = $parsed;
				$this->project_id      = $parsed['project_id'];
				$this->push_source     = 'auto';
				return true;
			}
		}

		// Fall back to older guessed option keys for legacy compatibility.
		$option_keys = array(
			'bbapp_firebase_settings',
			'bb_app_settings',
			'bb_app_firebase',
		);

		foreach ( $option_keys as $option_key ) {
			$bb_settings = get_option( $option_key, array() );

			if ( empty( $bb_settings ) ) {
				continue;
			}

			// BuddyBoss may store the service account JSON as a string or nested array.
			$service_account_json = '';

			if ( is_array( $bb_settings ) ) {
				if ( isset( $bb_settings['service_account_json'] ) ) {
					$service_account_json = $bb_settings['service_account_json'];
				} elseif ( isset( $bb_settings['firebase_service_account'] ) ) {
					$service_account_json = $bb_settings['firebase_service_account'];
				} elseif ( isset( $bb_settings['server_key_json'] ) ) {
					$service_account_json = $bb_settings['server_key_json'];
				}
			} elseif ( is_string( $bb_settings ) ) {
				$service_account_json = $bb_settings;
			}

			if ( empty( $service_account_json ) ) {
				continue;
			}

			$parsed = is_string( $service_account_json )
				? json_decode( $service_account_json, true )
				: $service_account_json;

			if ( is_array( $parsed ) && isset( $parsed['project_id'], $parsed['private_key'], $parsed['client_email'] ) ) {
				$this->service_account = $parsed;
				$this->project_id      = $parsed['project_id'];
				$this->push_source     = 'auto';
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the BuddyBoss Firebase admin key file path using BuddyBoss APIs.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_buddyboss_admin_key_path() {
		if ( class_exists( 'BuddyBossApp\\Notification\\Services\\Firebase' ) ) {
			$firebase = \BuddyBossApp\Notification\Services\Firebase::instance();

			if ( is_object( $firebase ) && method_exists( $firebase, 'get_admin_key' ) ) {
				$file_path = $firebase->get_admin_key();

				if ( is_string( $file_path ) && '' !== $file_path ) {
					return $file_path;
				}
			}
		}

		if ( class_exists( 'BuddyBossApp\\Admin\\Configure' ) ) {
			$config = \BuddyBossApp\Admin\Configure::instance();

			if ( is_object( $config ) && method_exists( $config, 'option' ) ) {
				$json_file = $config->option( 'push.firebase_admin_key' );

				if ( is_string( $json_file ) && '' !== $json_file ) {
					if ( function_exists( 'bbapp_get_upload_full_path' ) ) {
						$file_path = bbapp_get_upload_full_path( $json_file );
						if ( is_string( $file_path ) && '' !== $file_path ) {
							return $file_path;
						}
					}

					return $json_file;
				}
			}
		}

		return '';
	}

	/**
	 * Load and decode a Firebase service account JSON file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to the JSON file.
	 * @return array
	 */
	private function load_service_account_from_file( $file_path ) {
		$file_path = is_string( $file_path ) ? trim( $file_path ) : '';

		if ( '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}

		$json_content = file_get_contents( $file_path );
		if ( false === $json_content || '' === $json_content ) {
			return array();
		}

		$parsed = json_decode( $json_content, true );

		return is_array( $parsed ) ? $parsed : array();
	}

	/**
	 * Load Firebase credentials from manual plugin settings.
	 *
	 * @since 1.0.0
	 */
	private function load_manual_credentials() {
		$settings = get_option( 'bcsend_settings', array() );

		$encrypted_json = isset( $settings['firebase_service_account_json'] ) ? $settings['firebase_service_account_json'] : '';
		$project_id     = isset( $settings['firebase_project_id'] ) ? $settings['firebase_project_id'] : '';

		if ( empty( $encrypted_json ) || empty( $project_id ) ) {
			return;
		}

		$decrypted_json = Bcsend_Encryption::decrypt( $encrypted_json );

		if ( empty( $decrypted_json ) ) {
			return;
		}

		$parsed = json_decode( $decrypted_json, true );

		if ( ! is_array( $parsed ) || ! isset( $parsed['private_key'], $parsed['client_email'] ) ) {
			return;
		}

		$this->service_account = $parsed;
		$this->project_id      = sanitize_text_field( $project_id );
		$this->push_source     = 'manual';
	}

	/**
	 * Check whether the push service is configured with valid credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if project ID and service account are set.
	 */
	public function is_configured() {
		return ! empty( $this->project_id ) && ! empty( $this->service_account );
	}

	/**
	 * Get the current push configuration source.
	 *
	 * @since 1.0.0
	 *
	 * @return string 'auto', 'manual', or empty string if not configured.
	 */
	public function get_push_source() {
		return $this->push_source;
	}

	/**
	 * Base64url encode data for JWT construction.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data Data to encode.
	 *
	 * @return string Base64url-encoded string.
	 */
	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Obtain a Google OAuth2 access token for FCM.
	 *
	 * Generates a JWT signed with the service account private key,
	 * exchanges it for an access token, and caches the result for 55 minutes.
	 *
	 * @since 1.0.0
	 *
	 * @return string|WP_Error Access token string or WP_Error on failure.
	 */
	private function get_access_token() {
		// Check cached token first.
		$cached_token = get_transient( 'bcsend_fcm_access_token' );

		if ( false !== $cached_token ) {
			return $cached_token;
		}

		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'push_not_configured',
				'Firebase push service is not configured.'
			);
		}

		$client_email = $this->service_account['client_email'];
		$private_key  = $this->service_account['private_key'];
		$token_uri    = isset( $this->service_account['token_uri'] )
			? $this->service_account['token_uri']
			: 'https://oauth2.googleapis.com/token';

		$now = time();

		// Build JWT header.
		$header = self::base64url_encode(
			wp_json_encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		);

		// Build JWT claims.
		$claims = self::base64url_encode(
			wp_json_encode(
				array(
					'iss'   => $client_email,
					'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
					'aud'   => $token_uri,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		// Sign the JWT.
		$signing_input = $header . '.' . $claims;
		$signature     = '';

		$sign_result = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( false === $sign_result ) {
			return new WP_Error(
				'push_jwt_error',
				'Failed to sign JWT for Firebase authentication.'
			);
		}

		$jwt = $signing_input . '.' . self::base64url_encode( $signature );

		// Exchange JWT for access token.
		$response = wp_remote_post(
			$token_uri,
			array(
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			Bcsend_Logger::log(
				'api_call',
				'FCM auth token request failed',
				wp_json_encode(
					array(
						'service' => 'fcm_auth',
						'error'   => $response->get_error_message(),
					)
				),
				'error'
			);
			return $response;
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$raw_body     = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $raw_body, true );

		if ( 200 !== $code || ! isset( $decoded_body['access_token'] ) ) {
			$error_message = isset( $decoded_body['error_description'] )
				? $decoded_body['error_description']
				: 'Failed to obtain FCM access token';

			Bcsend_Logger::log(
				'api_call',
				'FCM auth token exchange failed',
				wp_json_encode(
					array(
						'service'     => 'fcm_auth',
						'status_code' => $code,
						'error'       => $error_message,
					)
				),
				'error'
			);

			return new WP_Error(
				'push_auth_error',
				sprintf( '%s (HTTP %d)', $error_message, $code ),
				array(
					'status_code' => $code,
					'response'    => $decoded_body,
				)
			);
		}

		$access_token = $decoded_body['access_token'];

		// Cache for 55 minutes (token expires in 60).
		set_transient( 'bcsend_fcm_access_token', $access_token, 55 * MINUTE_IN_SECONDS );

		return $access_token;
	}

	/**
	 * Send a push notification to a single device token.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token   FCM device token.
	 * @param string $title   Notification title.
	 * @param string $message Notification body.
	 *
	 * @return true|string|WP_Error True on success, 'invalid_token' for unregistered tokens, or WP_Error.
	 */
	/**
	 * Build the FCM v1 message payload matching BuddyBoss App format.
	 *
	 * Includes notification (title/body), platform-specific sound and
	 * badge settings for iOS (APNs) and Android, plus a data payload
	 * for deep-linking from the notification tap.
	 *
	 * @param string $token   FCM device token.
	 * @param string $title   Notification title.
	 * @param string $message Notification body.
	 * @param int    $user_id Optional user ID for badge count.
	 *
	 * @return array FCM v1 message payload.
	 */
	private function build_message_payload( $token, $title, $message, $user_id = 0, $link_url = '' ) {
		$payload = array(
			'message' => array(
				'token'        => $token,
				'notification' => array(
					'title' => $title,
					'body'  => $message,
				),
				'data'         => array(
					'notification_type' => 'bcsend_campaign',
					'primary_text'      => $title,
					'action'            => 'open_notifications',
				),
				'apns'         => array(
					'payload' => array(
						'aps' => array(
							'sound' => 'default',
							'badge' => 1,
						),
					),
				),
				'android'      => array(
					'notification' => array(
						'sound'              => 'default',
						'notification_count' => 1,
					),
				),
			),
		);

		// Add deep link URL if provided.
		if ( ! empty( $link_url ) ) {
			$payload['message']['data']['link_url']      = $link_url;
			$payload['message']['data']['deep_link_url'] = $link_url;
		}

		// Set badge count from BuddyBoss notification count if available.
		if ( $user_id > 0 && function_exists( 'bbapp_notifications' ) ) {
			$count                                     = bbapp_notifications()->get_notification_count( $user_id );
			$payload['message']['data']['badge_count'] = (string) $count;
			$payload['message']['apns']['payload']['aps']['badge']               = (int) $count;
			$payload['message']['android']['notification']['notification_count'] = (int) $count;
		}

		return $payload;
	}

	public function send_single( $token, $title, $message, $user_id = 0, $link_url = '', $return_details = false ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = sprintf(
			'https://fcm.googleapis.com/v1/projects/%s/messages:send',
			rawurlencode( $this->project_id )
		);

		$body = $this->build_message_payload( $token, $title, $message, $user_id, $link_url );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		);

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_RETRIES ) {
			++$attempt;

			if ( $attempt > 1 ) {
				$backoff = pow( 2, $attempt );
				sleep( $backoff );
			}

			$response = wp_remote_post( $url, $args );

			// Connection-level error.
			if ( is_wp_error( $response ) ) {
				$last_error = $response;

				Bcsend_Logger::log(
					'api_call',
					'FCM send_single connection error',
					wp_json_encode(
						array(
							'service' => 'fcm',
							'action'  => 'send_single',
							'attempt' => $attempt,
							'error'   => $response->get_error_message(),
						)
					),
					'error'
				);

				continue;
			}

			$code         = wp_remote_retrieve_response_code( $response );
			$raw_body     = wp_remote_retrieve_body( $response );
			$decoded_body = json_decode( $raw_body, true );

			// Success.
			if ( 200 === $code ) {
				if ( $return_details ) {
					return array(
						'status'        => 'success',
						'fcm_response'  => $raw_body,
						'error_message' => '',
					);
				}
				return true;
			}

			// Invalid / unregistered token (404 or specific error).
			if ( 404 === $code ) {
				if ( $return_details ) {
					return array(
						'status'        => 'invalid_token',
						'fcm_response'  => $raw_body,
						'error_message' => 'Token not registered (HTTP 404)',
					);
				}
				return 'invalid_token';
			}

			// Check for UNREGISTERED or INVALID_ARGUMENT in the error details.
			if ( isset( $decoded_body['error']['details'] ) && is_array( $decoded_body['error']['details'] ) ) {
				foreach ( $decoded_body['error']['details'] as $detail ) {
					if ( isset( $detail['errorCode'] ) && in_array( $detail['errorCode'], array( 'UNREGISTERED', 'INVALID_ARGUMENT' ), true ) ) {
						if ( $return_details ) {
							return array(
								'status'        => 'invalid_token',
								'fcm_response'  => $raw_body,
								'error_message' => $detail['errorCode'],
							);
						}
						return 'invalid_token';
					}
				}
			}

			// Also check the status field for token-related errors.
			if ( 400 === $code && isset( $decoded_body['error']['status'] ) && 'INVALID_ARGUMENT' === $decoded_body['error']['status'] ) {
				if ( $return_details ) {
					return array(
						'status'        => 'invalid_token',
						'fcm_response'  => $raw_body,
						'error_message' => 'INVALID_ARGUMENT',
					);
				}
				return 'invalid_token';
			}

			// Auth failures - do not retry.
			if ( in_array( $code, array( 401, 403 ), true ) ) {
				// Clear cached token as it may have been revoked.
				delete_transient( 'bcsend_fcm_access_token' );

				$error_message = isset( $decoded_body['error']['message'] )
					? $decoded_body['error']['message']
					: 'FCM authentication failed';

				if ( $return_details ) {
					return array(
						'status'        => 'error',
						'fcm_response'  => $raw_body,
						'error_message' => sprintf( '%s (HTTP %d)', $error_message, $code ),
					);
				}

				return new WP_Error(
					'push_auth_error',
					sprintf( '%s (HTTP %d)', $error_message, $code ),
					array(
						'status_code' => $code,
						'response'    => $decoded_body,
					)
				);
			}

			// 5xx or other retryable errors.
			$last_error = new WP_Error(
				'push_api_error',
				sprintf( 'FCM API returned HTTP %d', $code ),
				array(
					'status_code' => $code,
					'response'    => $decoded_body,
				)
			);
		}

		// All retries exhausted.
		if ( $return_details ) {
			$msg = is_wp_error( $last_error ) ? $last_error->get_error_message() : 'FCM request failed after maximum retries.';
			return array(
				'status'        => 'error',
				'fcm_response'  => '',
				'error_message' => $msg,
			);
		}

		if ( ! is_wp_error( $last_error ) ) {
			$last_error = new WP_Error(
				'push_api_error',
				'FCM request failed after maximum retries.'
			);
		}

		return $last_error;
	}

	/**
	 * Send push notifications to a list of WordPress user IDs.
	 *
	 * Queries push tokens from the BuddyBoss App push tokens table,
	 * sends individually with retry logic, and cleans up invalid tokens.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $user_ids Array of WordPress user IDs.
	 * @param string $title    Notification title.
	 * @param string $message  Notification body.
	 *
	 * @return array|WP_Error Summary array or WP_Error on auth failure.
	 */
	public function send_to_users( $user_ids, $title, $message ) {
		global $wpdb;

		if ( empty( $user_ids ) ) {
			return array(
				'total'           => 0,
				'sent'            => 0,
				'failed'          => 0,
				'invalid_cleaned' => 0,
				'batches'         => 0,
			);
		}

		$user_ids = array_map( 'absint', $user_ids );
		$table    = $wpdb->prefix . 'bbapp_user_devices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$tokens = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, device_token FROM {$table} WHERE user_id IN ({$placeholders}) AND device_token != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$user_ids
			)
		);

		if ( empty( $tokens ) ) {
			return array(
				'total'           => 0,
				'sent'            => 0,
				'failed'          => 0,
				'invalid_cleaned' => 0,
				'batches'         => 0,
			);
		}

		$total          = count( $tokens );
		$sent           = 0;
		$failed         = 0;
		$invalid_tokens = array();
		$batches        = array_chunk( $tokens, self::BATCH_SIZE );
		$batch_count    = count( $batches );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $token_row ) {
				$result = $this->send_single( $token_row->device_token, $title, $message, (int) $token_row->user_id );

				if ( true === $result ) {
					++$sent;
				} elseif ( 'invalid_token' === $result ) {
					++$failed;
					$invalid_tokens[] = $token_row->device_token;
				} elseif ( is_wp_error( $result ) ) {
					$error_data = $result->get_error_data();

					// Auth failure - abort entire send.
					if ( 'push_auth_error' === $result->get_error_code() ) {
						// Clean up any invalid tokens collected so far.
						$this->cleanup_invalid_tokens( $invalid_tokens );

						Bcsend_Logger::log(
							'api_call',
							'FCM send_to_users auth failure',
							wp_json_encode(
								array(
									'service' => 'fcm',
									'action'  => 'send_to_users',
									'error'   => $result->get_error_message(),
									'sent'    => $sent,
									'failed'  => $failed,
								)
							),
							'error'
						);

						return $result;
					}

					++$failed;
				}
			}
		}

		// Clean up invalid tokens.
		$invalid_cleaned = $this->cleanup_invalid_tokens( $invalid_tokens );

		$summary = array(
			'total'           => $total,
			'sent'            => $sent,
			'failed'          => $failed,
			'invalid_cleaned' => $invalid_cleaned,
			'batches'         => $batch_count,
		);

		Bcsend_Logger::log(
			'api_call',
			'FCM send_to_users completed',
			wp_json_encode(
				array(
					'service' => 'fcm',
					'action'  => 'send_to_users',
					'summary' => $summary,
				)
			)
		);

		return $summary;
	}

	/**
	 * Get push tokens for a list of user IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $user_ids Array of WordPress user IDs.
	 *
	 * @return array Array of objects with user_id and token properties.
	 */
	public function get_tokens_for_users( $user_ids ) {
		global $wpdb;

		if ( empty( $user_ids ) ) {
			return array();
		}

		$user_ids = array_map( 'absint', $user_ids );
		$table    = $wpdb->prefix . 'bbapp_user_devices';

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, device_token FROM {$table} WHERE user_id IN ({$placeholders}) AND device_token != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$user_ids
			)
		);

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * Send push notifications to an array of device tokens.
	 *
	 * Intended for use by Action Scheduler batch jobs.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $tokens  Array of FCM device token strings.
	 * @param string $title   Notification title.
	 * @param string $message Notification body.
	 *
	 * @return array|WP_Error Per-batch summary or WP_Error on auth failure.
	 */
	public function send_batch( $tokens, $title, $message ) {
		$tokens = $this->normalize_tokens( $tokens );

		if ( empty( $tokens ) ) {
			return array(
				'batch_number'    => 1,
				'total'           => 0,
				'sent'            => 0,
				'failed'          => 0,
				'invalid_cleaned' => 0,
			);
		}

		$total          = count( $tokens );
		$sent           = 0;
		$failed         = 0;
		$invalid_tokens = array();

		foreach ( $tokens as $token ) {
			$result = $this->send_single( $token, $title, $message );

			if ( true === $result ) {
				++$sent;
			} elseif ( 'invalid_token' === $result ) {
				++$failed;
				$invalid_tokens[] = $token;
			} elseif ( is_wp_error( $result ) ) {
				// Auth failure - abort batch.
				if ( 'push_auth_error' === $result->get_error_code() ) {
					$this->cleanup_invalid_tokens( $invalid_tokens );

					Bcsend_Logger::log(
						'api_call',
						'FCM send_batch auth failure',
						wp_json_encode(
							array(
								'service' => 'fcm',
								'action'  => 'send_batch',
								'error'   => $result->get_error_message(),
								'sent'    => $sent,
								'failed'  => $failed,
							)
						),
						'error'
					);

					return $result;
				}

				++$failed;
			}
		}

		// Clean up invalid tokens.
		$invalid_cleaned = $this->cleanup_invalid_tokens( $invalid_tokens );

		$summary = array(
			'batch_number'    => 1,
			'total'           => $total,
			'sent'            => $sent,
			'failed'          => $failed,
			'invalid_cleaned' => $invalid_cleaned,
		);

		Bcsend_Logger::log(
			'api_call',
			'FCM send_batch completed',
			wp_json_encode(
				array(
					'service' => 'fcm',
					'action'  => 'send_batch',
					'summary' => $summary,
				)
			)
		);

		return $summary;
	}

	/**
	 * Normalize token rows into a flat array of token strings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tokens Raw tokens or token rows.
	 * @return array
	 */
	private function normalize_tokens( $tokens ) {
		$normalized = array();

		foreach ( (array) $tokens as $token ) {
			if ( is_object( $token ) && isset( $token->device_token ) ) {
				$token = $token->device_token;
			} elseif ( is_object( $token ) && isset( $token->token ) ) {
				$token = $token->token;
			} elseif ( is_array( $token ) && isset( $token['device_token'] ) ) {
				$token = $token['device_token'];
			} elseif ( is_array( $token ) && isset( $token['token'] ) ) {
				$token = $token['token'];
			}

			$token = is_string( $token ) ? trim( $token ) : '';

			if ( '' !== $token ) {
				$normalized[] = $token;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Remove invalid tokens from the push tokens table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $invalid_tokens Array of token strings to remove.
	 *
	 * @return int Number of tokens actually deleted.
	 */
	private function cleanup_invalid_tokens( $invalid_tokens ) {
		global $wpdb;

		if ( empty( $invalid_tokens ) ) {
			return 0;
		}

		$table        = $wpdb->prefix . 'bbapp_user_devices';
		$placeholders = implode( ',', array_fill( 0, count( $invalid_tokens ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE device_token IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$invalid_tokens
			)
		);

		if ( $deleted > 0 ) {
			Bcsend_Logger::log(
				'api_call',
				'FCM invalid tokens cleaned up',
				wp_json_encode(
					array(
						'service' => 'fcm',
						'action'  => 'cleanup_invalid_tokens',
						'deleted' => $deleted,
					)
				)
			);
		}

		return (int) $deleted;
	}
}
