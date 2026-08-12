<?php
/**
 * Web push frontend for Beacon Campaign Sender.
 *
 * Lets any site with a Firebase project receive Beacon pushes in visitors'
 * browsers - no mobile app required. Provides:
 *
 *  - a service worker (served by the plugin at /?bcsend_push_sw=1) that
 *    receives notifications in the background and opens the click URL,
 *  - a subscribe button via the [bcsend_push_subscribe] shortcode and an
 *    optional floating bell, which asks browser permission and registers the
 *    resulting FCM token with Beacon's device registry,
 *  - frontend configuration sourced from Settings > Push (Firebase web app
 *    config + VAPID public key).
 *
 * The Firebase JS SDK is loaded from Google's official gstatic CDN - the
 * standard delivery mechanism Firebase documents for web apps.
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Web_Push
 */
class Bcsend_Web_Push {

	/**
	 * Firebase JS SDK version served from gstatic.
	 */
	const FIREBASE_JS_VERSION = '10.12.2';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve_service_worker' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_shortcode( 'bcsend_push_subscribe', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_bell' ) );
	}

	/**
	 * Whether web push is enabled and fully configured.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$settings = Bcsend_Settings::get_settings();

		return ! empty( $settings['webpush_enabled'] )
			&& '' !== self::get_web_config_json()
			&& ! empty( $settings['firebase_vapid_key'] );
	}

	/**
	 * The Firebase web app config as a JSON string ('' when invalid/missing).
	 *
	 * @return string
	 */
	public static function get_web_config_json() {
		$settings = Bcsend_Settings::get_settings();
		$raw      = isset( $settings['firebase_web_config'] ) ? trim( (string) $settings['firebase_web_config'] ) : '';

		if ( '' === $raw ) {
			return '';
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['apiKey'] ) || empty( $decoded['projectId'] ) || empty( $decoded['messagingSenderId'] ) || empty( $decoded['appId'] ) ) {
			return '';
		}

		// Only the keys the messaging SDK needs.
		$config = array_intersect_key(
			$decoded,
			array_flip( array( 'apiKey', 'authDomain', 'projectId', 'messagingSenderId', 'appId', 'storageBucket' ) )
		);

		return (string) wp_json_encode( $config );
	}

	/**
	 * Serve the service worker script.
	 *
	 * Registered from the site root as /?bcsend_push_sw=1 so its scope covers
	 * the whole site. The Firebase config is embedded server-side.
	 *
	 * @return void
	 */
	public static function maybe_serve_service_worker() {
		if ( ! isset( $_GET['bcsend_push_sw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public asset endpoint.
			return;
		}

		$config = self::get_web_config_json();

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: no-cache' );

		if ( '' === $config ) {
			echo '// Beacon web push is not configured.';
			exit;
		}

		$version = self::FIREBASE_JS_VERSION;

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript source; $config is wp_json_encode() output, $version a class constant.
		// The click handler is registered BEFORE the Firebase SDK loads, so
		// our listener always runs (Firebase's compat SW installs its own).
		echo <<<'JS'
self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	const data = event.notification.data || {};
	const link = data.link || (data.FCM_MSG && data.FCM_MSG.data && data.FCM_MSG.data.link_url) || '/';
	event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (const client of list) {
			if (client.url === link && 'focus' in client) {
				return client.focus();
			}
		}
		return clients.openWindow(link);
	}));
});

JS;
		echo "importScripts('https://www.gstatic.com/firebasejs/{$version}/firebase-app-compat.js');\n";
		echo "importScripts('https://www.gstatic.com/firebasejs/{$version}/firebase-messaging-compat.js');\n";
		echo "firebase.initializeApp({$config});\n";
		echo <<<'JS'
const messaging = firebase.messaging();

// Beacon sends web push as DATA-ONLY (no notification block), so the browser
// never auto-displays a second copy of what we render here.
messaging.onBackgroundMessage(function (payload) {
	const data = (payload && payload.data) || {};
	const title = data.title || data.primary_text || 'Notification';

	self.registration.showNotification(title, {
		body: data.body || '',
		tag: data.tag || 'bcsend-push',
		renotify: false,
		data: { link: data.link_url || data.deep_link_url || '/' }
	});
});
JS;
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Enqueue the subscribe script when web push is active.
	 *
	 * @return void
	 */
	public static function enqueue_frontend() {
		if ( ! self::is_active() ) {
			return;
		}

		$settings = Bcsend_Settings::get_settings();
		$js_path  = BCSEND_PLUGIN_DIR . 'assets/js/bcsend-push-subscribe.js';

		wp_enqueue_script(
			'bcsend-push-subscribe',
			BCSEND_PLUGIN_URL . 'assets/js/bcsend-push-subscribe.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : BCSEND_VERSION,
			true
		);

		wp_localize_script(
			'bcsend-push-subscribe',
			'bcsendPush',
			array(
				'firebaseConfig' => json_decode( self::get_web_config_json(), true ),
				'vapidKey'       => (string) $settings['firebase_vapid_key'],
				'sdkVersion'     => self::FIREBASE_JS_VERSION,
				'swUrl'          => home_url( '/?bcsend_push_sw=1' ),
				'restUrl'        => esc_url_raw( rest_url( 'bcsend/v1/devices' ) ),
				'restNonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'labels'         => array(
					'subscribe'   => __( 'Enable notifications', 'beacon-campaign-sender' ),
					'subscribed'  => __( 'Notifications on', 'beacon-campaign-sender' ),
					'unsupported' => __( 'Notifications are not supported in this browser.', 'beacon-campaign-sender' ),
					'denied'      => __( 'Notifications are blocked for this site in your browser settings.', 'beacon-campaign-sender' ),
					'error'       => __( 'Could not enable notifications. Please try again.', 'beacon-campaign-sender' ),
				),
			)
		);
	}

	/**
	 * Shortcode: [bcsend_push_subscribe] renders a subscribe button.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		if ( ! self::is_active() ) {
			return '';
		}

		return '<button type="button" class="bcsend-push-subscribe-btn">' . esc_html__( 'Enable notifications', 'beacon-campaign-sender' ) . '</button>';
	}

	/**
	 * Optional floating bell button.
	 *
	 * @return void
	 */
	public static function render_bell() {
		if ( ! self::is_active() ) {
			return;
		}

		$settings = Bcsend_Settings::get_settings();

		if ( empty( $settings['webpush_bell'] ) ) {
			return;
		}

		echo '<button type="button" class="bcsend-push-subscribe-btn bcsend-push-bell" aria-label="' . esc_attr__( 'Enable notifications', 'beacon-campaign-sender' ) . '">&#128276;</button>';
		echo '<style>.bcsend-push-bell{position:fixed;bottom:24px;right:24px;z-index:9999;width:48px;height:48px;border-radius:50%;border:none;background:#2271b1;color:#fff;font-size:20px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);}.bcsend-push-bell.is-subscribed{background:#1a7f37;}</style>';
	}
}
