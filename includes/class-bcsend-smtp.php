<?php
/**
 * Email routing via Brevo transactional API for all WordPress emails.
 *
 * Intercepts wp_mail() via the pre_wp_mail filter and sends through
 * Brevo's /v3/smtp/email API using the API key already configured
 * in Beacon Campaign Sender. No separate SMTP credentials needed.
 *
 * @package Bcsend_Plugin
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Smtp
 *
 * Routes WordPress email through Brevo's transactional email API,
 * overrides From headers, logs success/failure, and detects conflicts
 * with WP Mail SMTP.
 *
 * @since 2.2.0
 */
class Bcsend_Smtp {

	/**
	 * Failure counter transient key.
	 *
	 * @var string
	 */
	const FAILURE_TRANSIENT = 'bcsend_smtp_failure_count';

	/**
	 * Failure notice dismissal transient key.
	 *
	 * @var string
	 */
	const FAILURE_DISMISSED_TRANSIENT = 'bcsend_smtp_failure_dismissed';

	/**
	 * Sender verification transient key.
	 *
	 * @var string
	 */
	const DOMAIN_STATUS_TRANSIENT = 'bcsend_sender_domain_verified';

	/**
	 * Failure count threshold before showing the admin notice.
	 *
	 * @var int
	 */
	const FAILURE_THRESHOLD = 3;

	/**
	 * Original email log ID when a resend is in progress.
	 *
	 * @var int|null
	 */
	public static $resend_from_id = null;

	/**
	 * One-shot flag to bypass Beacon Campaign Sender SMTP interception for the next wp_mail() call.
	 *
	 * @var bool
	 */
	public static $bypass_once = false;

	/**
	 * Whether email routing is enabled.
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * Whether to force From name/email on all emails.
	 *
	 * @var bool
	 */
	private $force_from;

	/**
	 * Decrypted Brevo API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Sender name from Beacon Campaign Sender settings.
	 *
	 * @var string
	 */
	private $sender_name;

	/**
	 * Sender email from Beacon Campaign Sender settings.
	 *
	 * @var string
	 */
	private $sender_email;

	/**
	 * Constructor. Reads settings and decrypts the API key.
	 */
	public function __construct() {
		$settings = get_option( 'bcsend_settings', array() );

		$this->enabled      = ! empty( $settings['smtp_routing_enabled'] );
		$this->force_from   = ! empty( $settings['smtp_force_from'] );
		$this->sender_name  = isset( $settings['brevo_sender_name'] ) ? $settings['brevo_sender_name'] : '';
		$this->sender_email = isset( $settings['brevo_sender_email'] ) ? $settings['brevo_sender_email'] : '';

		$encrypted_key = isset( $settings['brevo_api_key'] ) ? $settings['brevo_api_key'] : '';
		$this->api_key = ! empty( $encrypted_key ) ? Bcsend_Encryption::decrypt( $encrypted_key ) : '';
	}

	/**
	 * Register hooks. Only adds mail hooks when the feature is enabled
	 * and the API key is configured.
	 *
	 * @return void
	 */
	public function init() {
		// Conflict notice on admin pages (always registered when enabled).
		if ( is_admin() && $this->enabled ) {
			add_action( 'admin_notices', array( $this, 'admin_conflict_notice' ) );
			add_action( 'admin_notices', array( $this, 'admin_failure_notice' ) );
			add_action( 'admin_notices', array( $this, 'admin_domain_notice' ) );
			add_action( 'wp_ajax_bcsend_dismiss_smtp_failure', array( __CLASS__, 'ajax_dismiss_failure_notice' ) );
		}

		if ( ! $this->enabled || empty( $this->api_key ) ) {
			return;
		}

		// Intercept wp_mail() and send via Brevo API (WP 5.7+).
		add_filter( 'pre_wp_mail', array( $this, 'send_via_api' ), 10, 2 );

		// Force From name/email if enabled.
		if ( $this->force_from && ! empty( $this->sender_email ) ) {
			add_filter( 'wp_mail_from', array( $this, 'override_from' ), PHP_INT_MAX );
			add_filter( 'wp_mail_from_name', array( $this, 'override_from_name' ), PHP_INT_MAX );
		}
	}

	/**
	 * Intercept wp_mail() and send via Brevo transactional API.
	 *
	 * @param null|bool $pre  Short-circuit return value. Null to continue with default.
	 * @param array     $atts wp_mail attributes: to, subject, message, headers, attachments.
	 * @return bool|null True on success, false on failure, null to fall through.
	 */
	public function send_via_api( $pre, $atts ) {
		// If another filter already handled it, don't interfere.
		if ( null !== $pre ) {
			return $pre;
		}

		if ( self::$bypass_once ) {
			self::$bypass_once = false;
			return null;
		}

		$to          = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject     = isset( $atts['subject'] ) ? $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		// Parse recipients.
		$to_addresses = $this->parse_recipients( $to );
		if ( empty( $to_addresses ) ) {
			$this->log_error( $to, $subject, 'No valid recipients.' );
			return false;
		}

		// Parse headers for CC, BCC, Reply-To, Content-Type.
		$parsed   = $this->parse_headers( $headers );
		$cc       = $parsed['cc'];
		$bcc      = $parsed['bcc'];
		$reply_to = $parsed['reply_to'];
		$is_html  = $parsed['is_html'];

		// Determine sender.
		$from_email = $this->force_from ? $this->sender_email : ( $parsed['from_email'] ?: $this->sender_email );
		$from_name  = $this->force_from ? $this->sender_name : ( $parsed['from_name'] ?: $this->sender_name );

		// Build API payload.
		$payload = array(
			'sender'  => array(
				'name'  => $from_name,
				'email' => $from_email,
			),
			'to'      => $to_addresses,
			'subject' => $subject,
		);

		// Content.
		if ( $is_html ) {
			$payload['htmlContent'] = $message;
		} else {
			$payload['textContent'] = $message;
		}

		// CC.
		if ( ! empty( $cc ) ) {
			$payload['cc'] = $cc;
		}

		// BCC.
		if ( ! empty( $bcc ) ) {
			$payload['bcc'] = $bcc;
		}

		// Reply-To.
		if ( ! empty( $reply_to ) ) {
			$payload['replyTo'] = $reply_to;
		}

		// Attachments.
		if ( ! empty( $attachments ) ) {
			$api_attachments = $this->process_attachments( $attachments );
			if ( ! empty( $api_attachments ) ) {
				$payload['attachment'] = $api_attachments;
			}
		}

		$log_data = array(
			'to_email'           => is_array( $to ) ? implode( ', ', $to ) : $to,
			'cc'                 => ! empty( $cc ) ? wp_json_encode( $cc ) : null,
			'bcc'                => ! empty( $bcc ) ? wp_json_encode( $bcc ) : null,
			'subject'            => $subject,
			'body'               => $message,
			'headers'            => is_array( $headers ) ? implode( "\r\n", $headers ) : $headers,
			'is_html'            => $is_html ? 1 : 0,
			'attachments'        => ! empty( $attachments ) ? wp_json_encode( $attachments ) : null,
			'from_name'          => $from_name,
			'from_email'         => $from_email,
			'resent_from_log_id' => self::$resend_from_id ? (int) self::$resend_from_id : null,
		);
		$log_data = Bcsend_Email_Log::prepare_log_data( $log_data );

		$brevo    = new Bcsend_Brevo_API();
		$response = $brevo->send_transactional( $payload );

		// Success (201 = created, 202 = accepted/scheduled).
		if ( ! is_wp_error( $response ) ) {
			$log_data['status']           = 'sent';
			$log_data['brevo_message_id'] = isset( $response['messageId'] ) ? sanitize_text_field( $response['messageId'] ) : null;
			Bcsend_Email_Log::insert( $log_data );

			$to_log = is_array( $to ) ? implode( ', ', $to ) : $to;
			Bcsend_Logger::log( 'wp_email', sprintf( 'Email sent to %s: %s', $to_log, $subject ) );

			// Fire the success action so other plugins know.
			do_action( 'wp_mail_succeeded', $atts );

			return true;
		}

		$error_data = $response->get_error_data();
		$error_code = is_array( $error_data ) && isset( $error_data['status_code'] )
			? (int) $error_data['status_code']
			: 0;
		$error_msg  = $response->get_error_message();

		self::record_smtp_failure();

		if ( ! $this->is_retryable_error( $error_code, $response ) ) {
			$log_data['status']        = 'failed';
			$log_data['error_message'] = $error_msg;
			Bcsend_Email_Log::insert( $log_data );

			$this->log_error( $to, $subject, $error_msg );
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error_msg, $atts ) );
			return false;
		}

		$to_log = is_array( $to ) ? implode( ', ', $to ) : $to;

		$log_data['status']        = 'fallback_attempted';
		$log_data['error_message'] = $error_msg;
		Bcsend_Email_Log::insert( $log_data );

		Bcsend_Logger::log(
			'wp_email',
			sprintf( 'Brevo failed after retries for "%s" to %s. Falling back to PHP mail.', $subject, $to_log ),
			$error_msg,
			'warning'
		);

		remove_filter( 'pre_wp_mail', array( $this, 'send_via_api' ), 10 );
		add_action( 'phpmailer_init', array( $this, 'restore_pre_wp_mail_filter' ), PHP_INT_MAX );

		return null;
	}

	/**
	 * Determine whether a Brevo failure is retryable.
	 *
	 * @param int      $status_code HTTP status code if present.
	 * @param WP_Error $error       Brevo error object.
	 * @return bool
	 */
	private function is_retryable_error( $status_code, $error ) {
		if ( in_array( $status_code, array( 400, 401, 403, 404 ), true ) ) {
			return false;
		}

		if ( $status_code >= 500 || 0 === $status_code ) {
			return true;
		}

		return 'brevo_api_error' === $error->get_error_code();
	}

	/**
	 * Parse recipients into Brevo API format.
	 *
	 * Handles string (comma-separated) and array input.
	 *
	 * @param string|array $to Recipients.
	 * @return array Array of {email, name} objects.
	 */
	private function parse_recipients( $to ) {
		if ( ! is_array( $to ) ) {
			$to = explode( ',', $to );
		}

		$addresses = array();

		foreach ( $to as $recipient ) {
			$recipient = trim( $recipient );
			if ( empty( $recipient ) ) {
				continue;
			}

			// Handle "Name <email>" format.
			if ( preg_match( '/^(.+)<(.+)>$/', $recipient, $matches ) ) {
				$addresses[] = array(
					'name'  => trim( $matches[1], ' "' ),
					'email' => trim( $matches[2] ),
				);
			} elseif ( is_email( $recipient ) ) {
				$addresses[] = array( 'email' => $recipient );
			}
		}

		return $addresses;
	}

	/**
	 * Parse email headers string or array into structured data.
	 *
	 * @param string|array $headers Raw headers.
	 * @return array Parsed data with cc, bcc, reply_to, from_email, from_name, is_html.
	 */
	private function parse_headers( $headers ) {
		$result = array(
			'cc'         => array(),
			'bcc'        => array(),
			'reply_to'   => null,
			'from_email' => '',
			'from_name'  => '',
			'is_html'    => false,
		);

		if ( empty( $headers ) ) {
			return $result;
		}

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		foreach ( $headers as $header ) {
			$header = trim( $header );
			if ( empty( $header ) ) {
				continue;
			}

			// Split into name: value.
			$parts = explode( ':', $header, 2 );
			if ( count( $parts ) < 2 ) {
				continue;
			}

			$name  = strtolower( trim( $parts[0] ) );
			$value = trim( $parts[1] );

			switch ( $name ) {
				case 'content-type':
					if ( false !== stripos( $value, 'text/html' ) ) {
						$result['is_html'] = true;
					}
					break;

				case 'cc':
					$parsed       = $this->parse_recipients( $value );
					$result['cc'] = array_merge( $result['cc'], $parsed );
					break;

				case 'bcc':
					$parsed        = $this->parse_recipients( $value );
					$result['bcc'] = array_merge( $result['bcc'], $parsed );
					break;

				case 'reply-to':
					$parsed = $this->parse_recipients( $value );
					if ( ! empty( $parsed ) ) {
						$result['reply_to'] = $parsed[0]; // Brevo accepts single reply-to.
					}
					break;

				case 'from':
					if ( preg_match( '/^(.+)<(.+)>$/', $value, $matches ) ) {
						$result['from_name']  = trim( $matches[1], ' "' );
						$result['from_email'] = trim( $matches[2] );
					} elseif ( is_email( $value ) ) {
						$result['from_email'] = $value;
					}
					break;
			}
		}

		return $result;
	}

	/**
	 * Process file attachments into Brevo API format.
	 *
	 * @param array $attachments Array of file paths.
	 * @return array Brevo attachment objects with name and content (base64).
	 */
	private function process_attachments( $attachments ) {
		$result = array();

		foreach ( $attachments as $path ) {
			$path = trim( $path );
			if ( empty( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			// Brevo has a 10MB total attachment limit.
			$size = filesize( $path );
			if ( $size > 10 * 1024 * 1024 ) {
				continue;
			}

			$content = file_get_contents( $path );
			if ( false === $content ) {
				continue;
			}

			$result[] = array(
				'name'    => basename( $path ),
				'content' => base64_encode( $content ),
			);
		}

		return $result;
	}

	/**
	 * Log an email failure.
	 *
	 * @param string|array $to      Recipients.
	 * @param string       $subject Email subject.
	 * @param string       $error   Error message.
	 */
	private function log_error( $to, $subject, $error ) {
		$to_str = is_array( $to ) ? implode( ', ', $to ) : $to;

		$message = ! empty( $subject )
			? sprintf( 'Email to %s failed (%s): %s', $to_str, $subject, $error )
			: sprintf( 'Email to %s failed: %s', $to_str, $error );

		Bcsend_Logger::log( 'wp_email', $message, '', 'error' );
	}

	/**
	 * Track final SMTP failures for admin alerting.
	 *
	 * @return void
	 */
	private static function record_smtp_failure() {
		$count = (int) get_transient( self::FAILURE_TRANSIENT );
		$count = min( self::FAILURE_THRESHOLD + 1, $count + 1 );

		set_transient( self::FAILURE_TRANSIENT, $count, HOUR_IN_SECONDS );
	}

	/**
	 * Re-register the Brevo mail interceptor after a PHP mail fallback attempt.
	 *
	 * @return void
	 */
	public function restore_pre_wp_mail_filter() {
		remove_action( 'phpmailer_init', array( $this, 'restore_pre_wp_mail_filter' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'send_via_api' ), 10, 2 );
	}

	/**
	 * Override the From email address.
	 *
	 * @param string $from Original From email.
	 * @return string Overridden From email.
	 */
	public function override_from( $from ) {
		return $this->sender_email;
	}

	/**
	 * Override the From name.
	 *
	 * @param string $name Original From name.
	 * @return string Overridden From name.
	 */
	public function override_from_name( $name ) {
		return $this->sender_name;
	}

	/**
	 * Show admin notice when WP Mail SMTP is active alongside Beacon Campaign Sender.
	 *
	 * @return void
	 */
	public function admin_conflict_notice() {
		if ( ! self::is_wp_mail_smtp_active() ) {
			return;
		}

		$plugins_url = admin_url( 'plugins.php' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Beacon Campaign Sender:', 'beacon-campaign-sender' ),
			esc_html__( 'Email routing is enabled but WP Mail SMTP is also active. Both plugins will attempt to handle email delivery. For best results, deactivate WP Mail SMTP.', 'beacon-campaign-sender' ),
			esc_url( $plugins_url ),
			esc_html__( 'Go to Plugins', 'beacon-campaign-sender' )
		);
	}

	/**
	 * Show an admin notice when repeated SMTP failures have occurred.
	 *
	 * @return void
	 */
	public function admin_failure_notice() {
		if ( get_transient( self::FAILURE_DISMISSED_TRANSIENT ) ) {
			return;
		}

		$count = (int) get_transient( self::FAILURE_TRANSIENT );
		if ( $count < self::FAILURE_THRESHOLD ) {
			return;
		}

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			return;
		}

		$logs_url = admin_url( 'admin.php?page=bcsend-logs' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'bcsend_nonce' );
		wp_register_script( 'bcsend-smtp-notice', false, array(), BCSEND_VERSION, true );
		wp_enqueue_script( 'bcsend-smtp-notice' );
		wp_add_inline_script(
			'bcsend-smtp-notice',
			'document.addEventListener("click",function(event){var button=event.target.closest("[data-bcsend-dismiss=\\"smtp-failure\\"] .notice-dismiss");if(!button){return;}var notice=button.closest("[data-bcsend-dismiss=\\"smtp-failure\\"]");if(!notice||notice.dataset.bcsendDismissSent==="1"){return;}notice.dataset.bcsendDismissSent="1";var body=new URLSearchParams();body.append("action","bcsend_dismiss_smtp_failure");body.append("nonce",notice.getAttribute("data-bcsend-nonce"));if(window.fetch){fetch(notice.getAttribute("data-bcsend-ajax-url"),{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()});}});'
		);

		printf(
			'<div class="notice notice-error is-dismissible" data-bcsend-dismiss="smtp-failure" data-bcsend-ajax-url="%1$s" data-bcsend-nonce="%2$s"><p><strong>%3$s</strong> %4$s <a href="%5$s">%6$s</a></p></div>',
			esc_url( $ajax_url ),
			esc_attr( $nonce ),
			esc_html__( 'Beacon Campaign Sender:', 'beacon-campaign-sender' ),
			esc_html(
				sprintf(
					/* translators: %d is the number of failures in the last hour. */
					_n(
						'WordPress email delivery has failed %d time in the last hour.',
						'WordPress email delivery has failed %d times in the last hour.',
						$count,
						'beacon-campaign-sender'
					),
					$count
				)
			),
			esc_url( $logs_url ),
			esc_html__( 'Review logs', 'beacon-campaign-sender' )
		);
	}

	/**
	 * Show an admin warning when the sender domain is known to be unverified.
	 *
	 * @return void
	 */
	public function admin_domain_notice() {
		$status = get_transient( self::DOMAIN_STATUS_TRANSIENT );

		if ( empty( $status ) || 'verified' === $status ) {
			return;
		}

		if ( 0 !== strpos( $status, 'unverified:' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			return;
		}

		$domain       = substr( $status, strlen( 'unverified:' ) );
		$settings_url = admin_url( 'admin.php?page=bcsend-settings' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Beacon Campaign Sender:', 'beacon-campaign-sender' ),
			esc_html(
				sprintf(
					/* translators: %s is the sender domain. */
					__( 'The sender domain "%s" is not verified in Brevo. Transactional emails may fail or land in spam.', 'beacon-campaign-sender' ),
					$domain
				)
			),
			esc_url( $settings_url ),
			esc_html__( 'Open settings', 'beacon-campaign-sender' )
		);
	}

	/**
	 * Dismiss the SMTP failure notice for one hour.
	 *
	 * @return void
	 */
	public static function ajax_dismiss_failure_notice() {
		check_ajax_referer( 'bcsend_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_bcsend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beacon-campaign-sender' ) ), 403 );
		}

		set_transient( self::FAILURE_DISMISSED_TRANSIENT, 1, HOUR_IN_SECONDS );

		wp_send_json_success();
	}

	/**
	 * Check if WP Mail SMTP (Lite or Pro) is active.
	 *
	 * @return bool
	 */
	public static function is_wp_mail_smtp_active() {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		foreach ( $active_plugins as $plugin ) {
			if ( false !== strpos( $plugin, 'wp-mail-smtp' ) || false !== strpos( $plugin, 'wp_mail_smtp' ) ) {
				return true;
			}
		}

		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

			foreach ( array_keys( $network_plugins ) as $plugin ) {
				if ( false !== strpos( $plugin, 'wp-mail-smtp' ) || false !== strpos( $plugin, 'wp_mail_smtp' ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
