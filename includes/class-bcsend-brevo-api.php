<?php
/**
 * Brevo (Sendinblue) API integration for Beacon Campaign Sender.
 *
 * Handles all communication with the Brevo API including
 * contact list management, campaign creation, sending, and statistics.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Brevo_API
 *
 * Provides methods for interacting with the Brevo v3 API.
 *
 * @since 1.0.0
 */
class Bcsend_Brevo_API {

	/**
	 * Brevo API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://api.brevo.com/v3/';

	/**
	 * Maximum number of retry attempts for transient failures.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Decrypted Brevo API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Sender name for campaigns.
	 *
	 * @var string
	 */
	private $sender_name;

	/**
	 * Sender email for campaigns.
	 *
	 * @var string
	 */
	private $sender_email;

	/**
	 * Constructor.
	 *
	 * Reads plugin settings and decrypts the Brevo API key.
	 *
	 * @since 1.0.0
	 */
	public function __construct( $api_key_override = null ) {
		$settings = get_option( 'bcsend_settings', array() );

		if ( null !== $api_key_override ) {
			$this->api_key = $api_key_override;
		} else {
			$encrypted_key = isset( $settings['brevo_api_key'] ) ? $settings['brevo_api_key'] : '';

			if ( ! empty( $encrypted_key ) ) {
				$this->api_key = Bcsend_Encryption::decrypt( $encrypted_key );
			} else {
				$this->api_key = '';
			}
		}

		$this->sender_name  = isset( $settings['brevo_sender_name'] ) ? $settings['brevo_sender_name'] : '';
		$this->sender_email = isset( $settings['brevo_sender_email'] ) ? $settings['brevo_sender_email'] : '';
	}

	/**
	 * Check whether the Brevo API is configured with a valid key.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the API key is present.
	 */
	public function is_configured() {
		return ! empty( $this->api_key );
	}

	/**
	 * Send a transactional email via Brevo.
	 *
	 * @since 2.5.0
	 *
	 * @param array $payload Transactional email payload.
	 * @return array|WP_Error
	 */
	public function send_transactional( $payload ) {
		return $this->request( 'POST', 'smtp/email', $payload );
	}

	/**
	 * Retrieve Brevo senders that are active.
	 *
	 * @since 2.5.0
	 *
	 * @return array|WP_Error
	 */
	public function get_senders() {
		$response = $this->request( 'GET', 'senders' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$senders = isset( $response['senders'] ) && is_array( $response['senders'] )
			? $response['senders']
			: array();

		return array_values(
			array_filter(
				$senders,
				static function ( $sender ) {
					return is_array( $sender ) && ! empty( $sender['active'] );
				}
			)
		);
	}

	/**
	 * Make an HTTP request to the Brevo API with retry logic.
	 *
	 * Retries up to 3 times for transient failures (timeouts, 5xx, connection errors)
	 * with exponential backoff. Does not retry 400, 401, 403, or 404 responses.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $method   HTTP method (GET, POST, PUT, DELETE).
	 * @param string     $endpoint API endpoint relative to base URL.
	 * @param array|null $body     Optional request body.
	 *
	 * @return array|WP_Error Decoded JSON response or WP_Error on failure.
	 */
	private function request( $method, $endpoint, $body = null ) {
		$url = self::BASE_URL . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'api-key'      => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_RETRIES ) {
			++$attempt;

			if ( $attempt > 1 ) {
				$backoff = pow( 2, $attempt );
				sleep( $backoff );
			}

			$response = wp_remote_request( $url, $args );

			// Connection-level error (timeout, DNS failure, etc.).
			if ( is_wp_error( $response ) ) {
				$last_error = $response;

				Bcsend_Logger::event(
					'api_call',
					'Brevo API request failed before receiving a response.',
					array(
						'service'  => 'brevo',
						'method'   => $args['method'],
						'endpoint' => $endpoint,
						'attempt'  => $attempt,
						'error'    => $response->get_error_message(),
					),
					'error'
				);

				continue;
			}

			$code         = wp_remote_retrieve_response_code( $response );
			$raw_body     = wp_remote_retrieve_body( $response );
			$decoded_body = json_decode( $raw_body, true );

			Bcsend_Logger::event(
				'api_call',
				'Brevo API request completed.',
				array(
					'service'     => 'brevo',
					'method'      => $args['method'],
					'endpoint'    => $endpoint,
					'attempt'     => $attempt,
					'status_code' => $code,
				)
			);

			// Success.
			if ( $code >= 200 && $code < 300 ) {
				return is_array( $decoded_body ) ? $decoded_body : array();
			}

			// Non-retryable client errors.
			if ( in_array( $code, array( 400, 401, 403, 404 ), true ) ) {
				$error_message = isset( $decoded_body['message'] ) ? $decoded_body['message'] : 'Brevo API error';
				return new WP_Error(
					'brevo_api_error',
					sprintf( '%s (HTTP %d)', $error_message, $code ),
					array(
						'status_code' => $code,
						'response'    => $decoded_body,
					)
				);
			}

			// 5xx or other retryable errors.
			$last_error = new WP_Error(
				'brevo_api_error',
				sprintf( 'Brevo API returned HTTP %d', $code ),
				array(
					'status_code' => $code,
					'response'    => $decoded_body,
				)
			);
		}

		// All retries exhausted.
		if ( ! is_wp_error( $last_error ) ) {
			$last_error = new WP_Error(
				'brevo_api_error',
				'Brevo API request failed after maximum retries.'
			);
		}

		return $last_error;
	}

	/**
	 * Retrieve all contact lists from Brevo.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Array of list objects or WP_Error.
	 */
	public function get_lists() {
		$response = $this->request( 'GET', 'contacts/lists' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['lists'] ) ? $response['lists'] : array();
	}

	/**
	 * Retrieve a single contact list from Brevo.
	 *
	 * @since 2.5.0
	 *
	 * @param int $list_id Brevo list ID.
	 * @return array|WP_Error List data or WP_Error.
	 */
	public function get_list( $list_id ) {
		return $this->request( 'GET', 'contacts/lists/' . (int) $list_id );
	}

	/**
	 * Fetch a single contact's details from Brevo.
	 *
	 * Hits GET /v3/contacts/{email}. Returns the contact data including
	 * emailBlacklisted state, list memberships, attributes, and a
	 * statistics object that distinguishes userUnsubscription (contact
	 * clicked unsubscribe) from adminUnsubscription (bulk/manual/import),
	 * plus hardBounces and complaints.
	 *
	 * @since 2.5.0
	 *
	 * @param string $email Contact email address.
	 * @return array|WP_Error Contact data or WP_Error. Returns a
	 *                        'brevo_contact_not_found' WP_Error with
	 *                        status 404 if the contact doesn't exist.
	 */
	public function get_contact( $email ) {
		$email = sanitize_email( $email );

		if ( empty( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.' );
		}

		$endpoint = 'contacts/' . rawurlencode( $email );
		$response = $this->request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();

			if ( is_array( $data ) && isset( $data['status_code'] ) && 404 === (int) $data['status_code'] ) {
				return new WP_Error(
					'brevo_contact_not_found',
					sprintf( 'Contact %s not found in Brevo.', $email ),
					array( 'status_code' => 404 )
				);
			}
		}

		return $response;
	}

	/**
	 * Classify why a contact is blocklisted from email marketing.
	 *
	 * Inspects the statistics block returned by get_contact() and maps
	 * it to a high-level reason so callers don't have to re-implement
	 * the logic. Returns one of:
	 *   - 'not_blocklisted'   emailBlacklisted is false
	 *   - 'user_unsubscribed' contact clicked unsubscribe link
	 *   - 'hard_bounce'       delivery failure marked them invalid
	 *   - 'spam_complaint'    contact marked email as spam
	 *   - 'admin_or_import'   bulk/manual/import/Conversations-sourced
	 *   - 'unknown'           blocklisted but no statistic explains why
	 *
	 * @since 2.5.0
	 *
	 * @param array $contact Contact data from get_contact().
	 * @return string Reason code.
	 */
	public static function classify_blocklist_reason( $contact ) {
		if ( empty( $contact['emailBlacklisted'] ) ) {
			return 'not_blocklisted';
		}

		$stats = isset( $contact['statistics'] ) && is_array( $contact['statistics'] )
			? $contact['statistics']
			: array();

		$unsubs = isset( $stats['unsubscriptions'] ) && is_array( $stats['unsubscriptions'] )
			? $stats['unsubscriptions']
			: array();

		if ( ! empty( $unsubs['userUnsubscription'] ) ) {
			return 'user_unsubscribed';
		}

		if ( ! empty( $stats['hardBounces'] ) ) {
			return 'hard_bounce';
		}

		if ( ! empty( $stats['complaints'] ) ) {
			return 'spam_complaint';
		}

		if ( ! empty( $unsubs['adminUnsubscription'] ) ) {
			return 'admin_or_import';
		}

		return 'unknown';
	}

	/**
	 * Extract campaign statistics from a Brevo globalStats array.
	 *
	 * Handles field name variations across Brevo API versions:
	 * - Opens: uniqueViews / uniqueOpens / viewed
	 * - Open rate: opensRate / openRate / trackableViewsRate
	 * - Click rate: calculated from uniqueClicks / delivered (not returned by API)
	 *
	 * @since 2.5.0
	 *
	 * @param array $stats_data The campaign statistics payload from Brevo.
	 * @return array {
	 *     @type float $open_rate   Open rate as decimal (0.0–1.0).
	 *     @type float $click_rate  Click rate as decimal (0.0–1.0).
	 *     @type int   $unique_opens  Unique open count.
	 *     @type int   $unique_clicks Unique click count.
	 *     @type int   $delivered     Delivered count.
	 *     @type int   $unsubscriptions Unsubscribe count.
	 * }
	 */
	public static function extract_campaign_stats( $stats_data ) {
		if ( ! is_array( $stats_data ) ) {
			return array(
				'open_rate'       => null,
				'click_rate'      => null,
				'unique_opens'    => 0,
				'unique_clicks'   => 0,
				'delivered'       => 0,
				'unsubscriptions' => 0,
			);
		}

		// Brevo sometimes zeroes out globalStats but has real data in
		// campaignStats (per-list breakdown). Use globalStats first,
		// fall back to aggregating campaignStats if delivered is 0.
		$global = $stats_data;

		if ( isset( $stats_data['globalStats'] ) ) {
			$global = $stats_data['globalStats'];

			// If globalStats shows 0 delivered but campaignStats has data, aggregate it.
			$g_delivered = isset( $global['delivered'] ) ? (int) $global['delivered'] : 0;
			if ( 0 === $g_delivered && ! empty( $stats_data['campaignStats'] ) && is_array( $stats_data['campaignStats'] ) ) {
				$global = self::aggregate_campaign_stats( $stats_data['campaignStats'] );
			}
		}

		// Opens count: uniqueViews → uniqueOpens → viewed.
		$unique_opens = 0;
		if ( ! empty( $global['uniqueViews'] ) ) {
			$unique_opens = (int) $global['uniqueViews'];
		} elseif ( ! empty( $global['uniqueOpens'] ) ) {
			$unique_opens = (int) $global['uniqueOpens'];
		} elseif ( ! empty( $global['viewed'] ) ) {
			$unique_opens = (int) $global['viewed'];
		}

		$unique_clicks   = isset( $global['uniqueClicks'] ) ? (int) $global['uniqueClicks'] : 0;
		$delivered       = isset( $global['delivered'] ) ? (int) $global['delivered'] : 0;
		$unsubscriptions = isset( $global['unsubscriptions'] ) ? (int) $global['unsubscriptions'] : 0;

		// Open rate: opensRate → openRate → trackableViewsRate → calculate.
		$open_rate = null;
		if ( ! empty( $global['opensRate'] ) ) {
			$open_rate = (float) $global['opensRate'];
		} elseif ( ! empty( $global['openRate'] ) ) {
			$open_rate = (float) $global['openRate'];
		} elseif ( ! empty( $global['trackableViewsRate'] ) ) {
			$open_rate = (float) $global['trackableViewsRate'];
		} elseif ( $delivered > 0 ) {
			$open_rate = $unique_opens / $delivered;
		}

		// Click rate: not returned by current API — calculate from counts.
		$click_rate = null;
		if ( ! empty( $global['clickRate'] ) ) {
			$click_rate = (float) $global['clickRate'];
		} elseif ( $delivered > 0 ) {
			$click_rate = $unique_clicks / $delivered;
		}

		return array(
			'open_rate'       => $open_rate,
			'click_rate'      => $click_rate,
			'unique_opens'    => $unique_opens,
			'unique_clicks'   => $unique_clicks,
			'delivered'       => $delivered,
			'unsubscriptions' => $unsubscriptions,
		);
	}

	/**
	 * Aggregate per-list campaignStats into a single totals array.
	 *
	 * @since 2.5.0
	 *
	 * @param array $campaign_stats Array of per-list stat objects from Brevo.
	 * @return array Aggregated stats with the same keys as a single entry.
	 */
	private static function aggregate_campaign_stats( $campaign_stats ) {
		$totals   = array();
		$sum_keys = array(
			'uniqueClicks',
			'clickers',
			'complaints',
			'delivered',
			'sent',
			'softBounces',
			'hardBounces',
			'uniqueViews',
			'unsubscriptions',
			'viewed',
			'trackableViews',
			'uniqueOpens',
			'deferred',
		);

		foreach ( $campaign_stats as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( $sum_keys as $key ) {
				if ( isset( $entry[ $key ] ) ) {
					$totals[ $key ] = ( isset( $totals[ $key ] ) ? $totals[ $key ] : 0 ) + (int) $entry[ $key ];
				}
			}
		}

		return $totals;
	}

	/**
	 * Extract subscriber count from a Brevo list array.
	 *
	 * The field name varies across Brevo API versions and account types.
	 * Mirrors the JS fallback: uniqueSubscribers → totalSubscribers → subscriberCount.
	 *
	 * @since 2.5.0
	 *
	 * @param array $list Single list array from the Brevo API.
	 * @return int Subscriber count.
	 */
	public static function extract_subscriber_count( $list ) {
		if ( ! empty( $list['uniqueSubscribers'] ) ) {
			return (int) $list['uniqueSubscribers'];
		}
		if ( ! empty( $list['totalSubscribers'] ) ) {
			return (int) $list['totalSubscribers'];
		}
		if ( ! empty( $list['subscriberCount'] ) ) {
			return (int) $list['subscriberCount'];
		}
		return 0;
	}

	/**
	 * Create an email campaign in Brevo.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Campaign data with keys: subject, htmlContent, recipients (listIds), name.
	 *
	 * @return array|WP_Error Created campaign data or WP_Error.
	 */
	public function create_campaign( $data ) {
		$html_content = isset( $data['htmlContent'] ) ? $data['htmlContent'] : '';

		// Inject Brevo unsubscribe link if not already present.
		if ( false === strpos( $html_content, '{{ unsubscribe }}' ) ) {
			$unsub_html = '<p style="text-align:center;font-size:12px;color:#999;margin-top:30px;">'
				. '<a href="{{ unsubscribe }}" style="color:#999;">Unsubscribe</a></p>';

			// Insert before </body> if it exists, otherwise append.
			if ( false !== stripos( $html_content, '</body>' ) ) {
				$html_content = str_ireplace( '</body>', $unsub_html . '</body>', $html_content );
			} else {
				$html_content .= $unsub_html;
			}
		}

		$campaign_body = array(
			'sender'      => array(
				'name'  => $this->sender_name,
				'email' => $this->sender_email,
			),
			'subject'     => isset( $data['subject'] ) ? $data['subject'] : '',
			'htmlContent' => $html_content,
			'recipients'  => array(
				'listIds' => isset( $data['recipients']['listIds'] ) ? array_map( 'intval', $data['recipients']['listIds'] ) : array(),
			),
			'name'        => isset( $data['name'] ) ? $data['name'] : '',
		);

		// Always set reply-to. Fall back to sender email if not provided.
		$reply_to_email = ! empty( $data['replyTo'] ) && is_email( $data['replyTo'] )
			? sanitize_email( $data['replyTo'] )
			: $this->sender_email;

		if ( ! empty( $reply_to_email ) ) {
			$campaign_body['replyTo'] = $reply_to_email;
		}

		return $this->request( 'POST', 'emailCampaigns', $campaign_body );
	}

	/**
	 * Send a campaign immediately.
	 *
	 * @since 1.0.0
	 *
	 * @param int $brevo_campaign_id Brevo campaign ID.
	 *
	 * @return array|WP_Error Response data or WP_Error.
	 */
	public function send_campaign( $brevo_campaign_id ) {
		$endpoint = sprintf( 'emailCampaigns/%d/sendNow', (int) $brevo_campaign_id );
		return $this->request( 'POST', $endpoint );
	}

	/**
	 * Retrieve campaign statistics from Brevo.
	 *
	 * @since 1.0.0
	 *
	 * @param int $brevo_campaign_id Brevo campaign ID.
	 *
	 * @return array|WP_Error Campaign statistics array or WP_Error.
	 */
	public function get_campaign_stats( $brevo_campaign_id ) {
		$endpoint = sprintf( 'emailCampaigns/%d', (int) $brevo_campaign_id );
		$response = $this->request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$stats = isset( $response['statistics'] ) ? $response['statistics'] : array();

		// Log the raw response structure once for diagnostics.
		$diag_key = 'bcsend_brevo_stats_diag_v3_' . (int) $brevo_campaign_id;
		if ( false === get_transient( $diag_key ) ) {
			Bcsend_Logger::log(
				'brevo_stats_diag',
				'Brevo full response keys for campaign ' . (int) $brevo_campaign_id,
				wp_json_encode( array_keys( $response ) )
			);
			// Log the top-level statistics structure.
			$stats_summary = array();
			if ( isset( $response['statistics'] ) ) {
				$stats_summary['statistics_keys'] = array_keys( $response['statistics'] );
				if ( isset( $response['statistics']['globalStats'] ) ) {
					$stats_summary['globalStats'] = $response['statistics']['globalStats'];
				}
				if ( isset( $response['statistics']['campaignStats'] ) ) {
					$stats_summary['campaignStats_count'] = count( $response['statistics']['campaignStats'] );
					$stats_summary['campaignStats_first'] = ! empty( $response['statistics']['campaignStats'][0] ) ? $response['statistics']['campaignStats'][0] : null;
				}
			}
			// Also check for stats at top level.
			foreach ( array( 'status', 'sentDate', 'recipients' ) as $k ) {
				if ( isset( $response[ $k ] ) ) {
					$stats_summary[ $k ] = $response[ $k ];
				}
			}
			Bcsend_Logger::log(
				'brevo_stats_diag',
				'Brevo stats detail for campaign ' . (int) $brevo_campaign_id,
				wp_json_encode( $stats_summary )
			);
			set_transient( $diag_key, 1, DAY_IN_SECONDS );
		}

		return $stats;
	}

	/**
	 * Create or update a contact list in Brevo.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name      List name.
	 * @param int|null $folder_id Optional folder ID to place the list in.
	 *
	 * @return array|WP_Error Created list data or WP_Error.
	 */
	public function create_or_update_contact_list( $name, $folder_id = null ) {
		$body = array(
			'name' => $name,
		);

		if ( null !== $folder_id ) {
			$body['folderId'] = (int) $folder_id;
		} else {
			// Brevo requires a folderId; default to root folder (1).
			$body['folderId'] = 1;
		}

		return $this->request( 'POST', 'contacts/lists', $body );
	}

	/**
	 * Create or update a contact in Brevo.
	 *
	 * Hits POST /v3/contacts with updateEnabled=true so existing
	 * contacts are updated rather than erroring out. Use this for
	 * adding explicit opt-in signups (sign-up sheets, form
	 * submissions, etc.) and ensure the contact has consented to
	 * receive email before calling it.
	 *
	 * This method does not alter the contact's emailBlacklisted
	 * state. If the email is already blocklisted in Brevo, calling
	 * this endpoint will not resubscribe them.
	 *
	 * @since 2.5.0
	 *
	 * @param string $email      Contact email address.
	 * @param array  $attributes Optional. Brevo attributes (FIRSTNAME,
	 *                           LASTNAME, etc.). Keys should be uppercase.
	 * @param array  $list_ids   Optional. Brevo list IDs to add the
	 *                           contact to.
	 * @return array|WP_Error Brevo response or WP_Error.
	 */
	public function create_contact( $email, $attributes = array(), $list_ids = array() ) {
		$email = sanitize_email( $email );

		if ( empty( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.' );
		}

		$body = array(
			'email'         => $email,
			'updateEnabled' => true,
		);

		if ( ! empty( $attributes ) && is_array( $attributes ) ) {
			$body['attributes'] = $attributes;
		}

		if ( ! empty( $list_ids ) && is_array( $list_ids ) ) {
			$body['listIds'] = array_values( array_map( 'intval', $list_ids ) );
		}

		return $this->request( 'POST', 'contacts', $body );
	}

	/**
	 * Retrieve contacts from the Brevo account.
	 *
	 * Wraps GET /v3/contacts and returns the raw Brevo response.
	 *
	 * @since 2.5.0
	 *
	 * @param array $args Optional. Brevo query params.
	 * @return array|WP_Error Raw Brevo response or WP_Error.
	 */
	public function list_contacts( $args = array() ) {
		$endpoint = 'contacts';

		if ( ! empty( $args ) && is_array( $args ) ) {
			$query = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			if ( '' !== $query ) {
				$endpoint .= '?' . $query;
			}
		}

		return $this->request( 'GET', $endpoint );
	}

	/**
	 * Retrieve contacts from a specific Brevo list.
	 *
	 * Wraps GET /v3/contacts/lists/{listId}/contacts and returns the raw
	 * Brevo response. Returns a dedicated brevo_list_not_found error on 404.
	 *
	 * @since 2.5.0
	 *
	 * @param int   $list_id Brevo list ID.
	 * @param array $args    Optional. Brevo query params.
	 * @return array|WP_Error Raw Brevo response or WP_Error.
	 */
	public function list_list_contacts( $list_id, $args = array() ) {
		$endpoint = 'contacts/lists/' . (int) $list_id . '/contacts';

		if ( ! empty( $args ) && is_array( $args ) ) {
			$query = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			if ( '' !== $query ) {
				$endpoint .= '?' . $query;
			}
		}

		$response = $this->request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();

			if ( is_array( $data ) && isset( $data['status_code'] ) && 404 === (int) $data['status_code'] ) {
				return new WP_Error(
					'brevo_list_not_found',
					sprintf( 'Brevo list %d not found.', (int) $list_id ),
					array( 'status_code' => 404 )
				);
			}
		}

		return $response;
	}

	/**
	 * Add contacts to a list by email address.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $list_id Brevo list ID.
	 * @param array $emails  Array of email addresses to add.
	 *
	 * @return array|WP_Error Response data or WP_Error.
	 */
	public function add_contacts_to_list( $list_id, $emails ) {
		$endpoint = sprintf( 'contacts/lists/%d/contacts/add', (int) $list_id );

		$body = array(
			'emails' => array_values( array_map( 'sanitize_email', $emails ) ),
		);

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Get the subscriber count for a specific list.
	 *
	 * @since 1.0.0
	 *
	 * @param int $list_id Brevo list ID.
	 *
	 * @return int|WP_Error Subscriber count or WP_Error.
	 */
	public function get_subscriber_count( $list_id ) {
		$endpoint = sprintf( 'contacts/lists/%d', (int) $list_id );
		$response = $this->request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::extract_subscriber_count( $response );
	}

	/**
	 * Get all contact emails on a specific list.
	 *
	 * Paginates through the Brevo API to collect every email address.
	 *
	 * @since 2.0.0
	 *
	 * @param int $list_id Brevo list ID.
	 *
	 * @return array|WP_Error Array of email strings or WP_Error.
	 */
	public function get_list_contacts( $list_id ) {
		$all_emails = array();
		$limit      = 500;
		$offset     = 0;

		do {
			$endpoint = sprintf(
				'contacts/lists/%d/contacts?limit=%d&offset=%d',
				(int) $list_id,
				$limit,
				$offset
			);

			$response = $this->request( 'GET', $endpoint );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$contacts = isset( $response['contacts'] ) ? $response['contacts'] : array();

			foreach ( $contacts as $contact ) {
				if ( ! empty( $contact['email'] ) ) {
					$all_emails[] = strtolower( $contact['email'] );
				}
			}

			$offset += $limit;
			$total   = isset( $response['count'] ) ? (int) $response['count'] : 0;
		} while ( $offset < $total );

		return $all_emails;
	}

	/**
	 * Remove contacts from a specific list.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $list_id Brevo list ID.
	 * @param array $emails  Array of email strings to remove.
	 *
	 * @return array|WP_Error Brevo response or WP_Error.
	 */
	public function remove_contacts_from_list( $list_id, $emails ) {
		$endpoint = sprintf( 'contacts/lists/%d/contacts/remove', (int) $list_id );

		$body = array(
			'emails' => array_values( array_map( 'strtolower', $emails ) ),
		);

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Update an existing contact in Brevo.
	 *
	 * Wraps PUT /v3/contacts/{identifier}. Accepts the raw Brevo payload
	 * so callers can update attributes, list memberships, and blacklist
	 * flags in one request. Returns a dedicated brevo_contact_not_found
	 * error on 404.
	 *
	 * Note: Brevo updates the primary email address by passing EMAIL in
	 * the attributes payload. Brevo documents that changing the email of
	 * a blocklisted contact removes that blocklisting and resubscribes the
	 * contact.
	 *
	 * @since 2.5.0
	 *
	 * @param string $email Contact email address.
	 * @param array  $data  Raw Brevo update payload.
	 * @return array|WP_Error Brevo response or WP_Error.
	 */
	public function update_contact( $email, $data = array() ) {
		$email = sanitize_email( $email );

		if ( empty( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.' );
		}

		$endpoint = 'contacts/' . rawurlencode( $email );
		$response = $this->request( 'PUT', $endpoint, is_array( $data ) ? $data : array() );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();

			if ( is_array( $error_data ) && isset( $error_data['status_code'] ) && 404 === (int) $error_data['status_code'] ) {
				return new WP_Error(
					'brevo_contact_not_found',
					sprintf( 'Contact %s not found in Brevo.', $email ),
					array( 'status_code' => 404 )
				);
			}
		}

		return $response;
	}
}
