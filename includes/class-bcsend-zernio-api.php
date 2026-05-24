<?php
/**
 * Zernio API integration for Beacon Campaign Sender.
 *
 * Handles connected account sync, validation, and social post delivery.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Zernio_API
 */
class Bcsend_Zernio_API {

	/**
	 * Zernio API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://zernio.com/api/v1/';

	/**
	 * Maximum number of retry attempts for transient failures.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Decrypted API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Stored profile ID.
	 *
	 * @var string
	 */
	private $profile_id;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key_override Optional plaintext API key.
	 */
	public function __construct( $api_key_override = null ) {
		$settings = get_option( 'bcsend_settings', array() );

		if ( null !== $api_key_override ) {
			$this->api_key = $api_key_override;
		} else {
			$encrypted_key = isset( $settings['zernio_api_key'] ) ? $settings['zernio_api_key'] : '';
			$this->api_key = ! empty( $encrypted_key ) ? Bcsend_Encryption::decrypt( $encrypted_key ) : '';
		}

		$this->profile_id = isset( $settings['zernio_profile_id'] ) ? (string) $settings['zernio_profile_id'] : '';
	}

	/**
	 * Check whether the Zernio API is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->api_key );
	}

	/**
	 * Run a lightweight connection test.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$profiles = $this->list_profiles();

		if ( is_wp_error( $profiles ) ) {
			return $profiles;
		}

		return array(
			'message'  => __( 'Connected successfully.', 'beacon-campaign-sender' ),
			'profiles' => is_array( $profiles ) ? count( $profiles ) : 0,
		);
	}

	/**
	 * Get supported social platforms.
	 *
	 * @return array
	 */
	public static function get_supported_platforms() {
		return array(
			'twitter'   => array(
				'label'     => 'Twitter / X',
				'max_chars' => 280,
			),
			'linkedin'  => array(
				'label'     => 'LinkedIn',
				'max_chars' => 3000,
			),
			'bluesky'   => array(
				'label'     => 'Bluesky',
				'max_chars' => 300,
			),
			'threads'   => array(
				'label'     => 'Threads',
				'max_chars' => 500,
			),
			'facebook'  => array(
				'label'     => 'Facebook',
				'max_chars' => 63206,
			),
			'instagram' => array(
				'label'     => 'Instagram',
				'max_chars' => 2200,
			),
		);
	}

	/**
	 * Get a per-platform character limit.
	 *
	 * @param string $platform Platform slug.
	 * @return int
	 */
	public static function get_platform_char_limit( $platform ) {
		$platforms = self::get_supported_platforms();
		return isset( $platforms[ $platform ]['max_chars'] ) ? (int) $platforms[ $platform ]['max_chars'] : 500;
	}

	/**
	 * Build a normalized Zernio create-post payload.
	 *
	 * @param array $args Normalized local arguments.
	 * @return array
	 */
	public static function build_post_payload( $args ) {
		$content   = isset( $args['content'] ) ? (string) $args['content'] : '';
		$scheduled = isset( $args['scheduled_for'] ) ? sanitize_text_field( $args['scheduled_for'] ) : '';
		$media     = isset( $args['media_items'] ) && is_array( $args['media_items'] ) ? $args['media_items'] : array();
		$accounts  = isset( $args['accounts'] ) && is_array( $args['accounts'] ) ? $args['accounts'] : array();
		$timezone  = wp_timezone_string();
		if ( '' === $timezone ) {
			$timezone = 'UTC';
		}

		if ( empty( $accounts ) && ( ! empty( $args['platform'] ) || ! empty( $args['account_id'] ) ) ) {
			$accounts[] = array(
				'platform'   => isset( $args['platform'] ) ? $args['platform'] : '',
				'account_id' => isset( $args['account_id'] ) ? $args['account_id'] : '',
			);
		}

		$platforms = array_values(
			array_filter(
				array_map(
					static function ( $account ) {
						if ( ! is_array( $account ) ) {
							return null;
						}

						$platform   = isset( $account['platform'] ) ? sanitize_key( $account['platform'] ) : '';
						$account_id = isset( $account['account_id'] ) ? sanitize_text_field( $account['account_id'] ) : '';

						if ( empty( $platform ) || empty( $account_id ) ) {
							return null;
						}

						return array(
							'platform'  => $platform,
							'accountId' => $account_id,
						);
					},
					$accounts
				)
			)
		);

		$payload = array(
			'content'   => $content,
			'platforms' => $platforms,
		);

		if ( ! empty( $scheduled ) ) {
			$timestamp = strtotime( $scheduled );
			$now       = time();

			// If the scheduled time is still in the future, hand it to Zernio as a
			// scheduled post. Otherwise, publish immediately instead of letting
			// Zernio save a draft for a time that already passed.
			if ( false !== $timestamp && $timestamp > ( $now + 30 ) ) {
				$payload['scheduledFor'] = gmdate( 'c', $timestamp );
				$payload['timezone']     = $timezone;
			} else {
				$payload['publishNow'] = true;
			}
		} else {
			$payload['publishNow'] = true;
		}

		if ( ! empty( $media ) ) {
			$payload['mediaItems'] = array_values(
				array_filter(
					array_map(
						static function ( $item ) {
							if ( ! is_array( $item ) || empty( $item['url'] ) ) {
								return null;
							}
							$out = array( 'url' => (string) $item['url'] );
							if ( ! empty( $item['type'] ) ) {
								$out['type'] = (string) $item['type'];
							}
							return $out;
						},
						$media
					)
				)
			);
		}

		return $payload;
	}

	/**
	 * List Zernio profiles.
	 *
	 * @return array|WP_Error
	 */
	public function list_profiles() {
		$response = $this->request( 'GET', 'profiles' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['profiles'] ) && is_array( $response['profiles'] ) ) {
			return $response['profiles'];
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * List connected accounts.
	 *
	 * @return array|WP_Error
	 */
	public function list_accounts() {
		$response = $this->request( 'GET', 'accounts' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['accounts'] ) && is_array( $response['accounts'] ) ) {
			return $response['accounts'];
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Normalize a Zernio account response into a flat list of account arrays.
	 *
	 * @param array $accounts Raw account response or account list.
	 * @return array
	 */
	public static function normalize_accounts( $accounts ) {
		if ( ! is_array( $accounts ) ) {
			return array();
		}

		if ( isset( $accounts['accounts'] ) && is_array( $accounts['accounts'] ) ) {
			$accounts = $accounts['accounts'];
		}

		return array_values(
			array_filter(
				$accounts,
				static function ( $account ) {
					return is_array( $account );
				}
			)
		);
	}

	/**
	 * Filter accounts to a single profile, when profile metadata is present.
	 *
	 * @param array  $accounts   Raw or normalized account list.
	 * @param string $profile_id Selected profile ID.
	 * @return array
	 */
	public static function filter_accounts_for_profile( $accounts, $profile_id, $profile_name = '' ) {
		$profile_id = (string) $profile_id;
		$accounts   = self::normalize_accounts( $accounts );

		if ( '' === $profile_id ) {
			return $accounts;
		}

		$filtered = array_values(
			array_filter(
				$accounts,
				static function ( $account ) use ( $profile_id ) {
					$candidates = array(
						isset( $account['profileId'] ) && ! is_array( $account['profileId'] ) ? $account['profileId'] : '',
					);

					if ( isset( $account['profileId'] ) && is_array( $account['profileId'] ) ) {
						$candidates[] = isset( $account['profileId']['_id'] ) ? $account['profileId']['_id'] : '';
					}

					$candidates = array_filter(
						array_map(
							static function ( $value ) {
								return (string) $value;
							},
							$candidates
						)
					);

					return in_array( $profile_id, $candidates, true );
				}
			)
		);

		return ! empty( $filtered ) ? $filtered : $accounts;
	}

	/**
	 * Build an account-connection URL for a platform.
	 *
	 * @param string $platform     Platform slug.
	 * @param string $profile_id   Optional profile ID.
	 * @param string $redirect_url Optional redirect URL.
	 * @return string|WP_Error
	 */
	public function get_connect_url( $platform, $profile_id = '', $redirect_url = '' ) {
		$platform = sanitize_key( $platform );
		$profile  = $profile_id ? $profile_id : $this->profile_id;

		if ( empty( $platform ) ) {
			return new WP_Error( 'missing_platform', __( 'A platform is required.', 'beacon-campaign-sender' ) );
		}

		if ( empty( $profile ) ) {
			return new WP_Error( 'missing_profile', __( 'Select a Zernio profile first.', 'beacon-campaign-sender' ) );
		}

		$query_args = array(
			'profileId' => $profile,
		);

		if ( ! empty( $redirect_url ) ) {
			$query_args['redirect_url'] = esc_url_raw( $redirect_url );
		}

		return add_query_arg( $query_args, self::BASE_URL . 'connect/' . rawurlencode( $platform ) );
	}

	/**
	 * Create a social post in Zernio.
	 *
	 * @param array $data Post payload.
	 * @return array|WP_Error
	 */
	public function create_post( $data ) {
		return $this->request( 'POST', 'posts', $data );
	}

	/**
	 * Get a Zernio post.
	 *
	 * @param string $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function get_post( $post_id ) {
		return $this->request( 'GET', 'posts/' . rawurlencode( $post_id ) );
	}

	/**
	 * Validate only post lengths.
	 *
	 * @param array $data Validation payload.
	 * @return array|WP_Error
	 */
	public function validate_post_length( $data ) {
		return $this->request( 'POST', 'tools/validate/post-length', $data );
	}

	/**
	 * Validate a complete post payload.
	 *
	 * @param array $data Validation payload.
	 * @return array|WP_Error
	 */
	public function validate_post( $data ) {
		return $this->request( 'POST', 'tools/validate/post', $data );
	}

	/**
	 * Validate media items.
	 *
	 * @param array $data Validation payload.
	 * @return array|WP_Error
	 */
	public function validate_media( $data ) {
		return $this->request( 'POST', 'tools/validate/media', $data );
	}

	/**
	 * List configured webhooks.
	 *
	 * Zernio's current docs describe /webhooks with PATCH updates, but this
	 * installation intentionally uses the legacy webhooks/settings + PUT
	 * endpoints because they are the endpoints verified against the live app.
	 *
	 * @return array|WP_Error
	 */
	public function list_webhooks() {
		return $this->request( 'GET', 'webhooks/settings' );
	}

	/**
	 * Create a webhook configuration.
	 *
	 * @param array $data Webhook payload.
	 * @return array|WP_Error
	 */
	public function create_webhook( $data ) {
		return $this->request( 'POST', 'webhooks/settings', $data );
	}

	/**
	 * Update a webhook configuration.
	 *
	 * @param string $webhook_id Webhook ID.
	 * @param array  $data       Update payload.
	 * @return array|WP_Error
	 */
	public function update_webhook( $webhook_id, $data ) {
		$data['_id'] = $webhook_id;
		return $this->request( 'PUT', 'webhooks/settings', $data );
	}

	/**
	 * Make an HTTP request to Zernio with retry logic.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Relative endpoint.
	 * @param array|null $body     Optional request body.
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, $body = null ) {
		$url           = self::BASE_URL . ltrim( $endpoint, '/' );
		$method_upper  = strtoupper( $method );
		$endpoint_norm = ltrim( $endpoint, '/' );

		// Post-create is slow (Zernio fetches each media URL, validates, and
		// hands off to the platform pipeline). Multi-image posts routinely
		// exceed 30s — bump the timeout. Retries are safe because Zernio's
		// 409 duplicate response carries the existingPostId, which the
		// social sender uses to link the row to the original request.
		$is_create_post = ( 'POST' === $method_upper && 'posts' === $endpoint_norm );
		$timeout        = $is_create_post ? 90 : 30;
		$max_attempts   = self::MAX_RETRIES;

		$args = array(
			'method'  => $method_upper,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout' => $timeout,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < $max_attempts ) {
			++$attempt;

			if ( $attempt > 1 ) {
				sleep( pow( 2, $attempt ) );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				Bcsend_Logger::log(
					'api_call',
					'Zernio API connection error',
					array(
						'service'  => 'zernio',
						'method'   => $args['method'],
						'endpoint' => $endpoint,
						'attempt'  => $attempt,
						'error'    => $response->get_error_message(),
					),
					'error'
				);
				continue;
			}

			$code             = wp_remote_retrieve_response_code( $response );
			$raw_body         = wp_remote_retrieve_body( $response );
			$decoded_body     = json_decode( $raw_body, true );
			$rate_limit_limit = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
			$rate_limit_left  = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			$rate_limit_reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

			Bcsend_Logger::log(
				'api_call',
				'Zernio API request sent',
				array(
					'service'              => 'zernio',
					'method'               => $args['method'],
					'endpoint'             => $endpoint,
					'attempt'              => $attempt,
					'status_code'          => $code,
					'rate_limit_limit'     => $rate_limit_limit,
					'rate_limit_remaining' => $rate_limit_left,
					'rate_limit_reset'     => $rate_limit_reset,
				)
			);

			if ( $code >= 200 && $code < 300 ) {
				return is_array( $decoded_body ) ? $decoded_body : array();
			}

			$error_message = '';
			if ( is_array( $decoded_body ) ) {
				if ( isset( $decoded_body['error']['message'] ) ) {
					$error_message = (string) $decoded_body['error']['message'];
				} elseif ( isset( $decoded_body['message'] ) ) {
					$error_message = (string) $decoded_body['message'];
				}
			}

			if ( empty( $error_message ) ) {
				$error_message = sprintf( 'Zernio API returned HTTP %d', $code );
			}

			if ( in_array( $code, array( 400, 401, 403, 404, 409, 422 ), true ) ) {
				Bcsend_Logger::log(
					'api_call',
					sprintf( 'Zernio %d response body', $code ),
					wp_json_encode(
						array(
							'service'  => 'zernio',
							'method'   => $args['method'],
							'endpoint' => $endpoint,
							'code'     => $code,
							'sent'     => isset( $args['body'] ) ? $args['body'] : null,
							'response' => null !== $decoded_body ? $decoded_body : $raw_body,
						)
					),
					'error'
				);

				return new WP_Error(
					'zernio_api_error',
					sprintf( '%s (HTTP %d)', $error_message, $code ),
					array(
						'status_code' => $code,
						'response'    => $decoded_body,
					)
				);
			}

			if ( 429 === $code ) {
				$wait_seconds = is_numeric( $rate_limit_reset ) ? max( 1, (int) $rate_limit_reset - time() ) : pow( 2, $attempt );
				sleep( min( $wait_seconds, 10 ) );
			}

			$last_error = new WP_Error(
				'zernio_api_error',
				sprintf( '%s (HTTP %d)', $error_message, $code ),
				array(
					'status_code' => $code,
					'response'    => $decoded_body,
				)
			);
		}

		if ( ! is_wp_error( $last_error ) ) {
			$last_error = new WP_Error( 'zernio_api_error', 'Zernio API request failed after maximum retries.' );
		}

		return $last_error;
	}
}
