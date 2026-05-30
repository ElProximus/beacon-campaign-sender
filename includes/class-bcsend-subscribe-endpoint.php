<?php
/**
 * Public subscribe endpoint and shortcode for Beacon Campaign Sender.
 *
 * @package Bcsend_Plugin
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Subscribe_Endpoint
 */
class Bcsend_Subscribe_Endpoint {

	/**
	 * Initialize frontend hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'bcsend_subscribe_form', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'beacon-campaign-sender/v1',
			'/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_subscribe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle public subscribe requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_subscribe( WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : $request->get_params();
		$email    = isset( $params['email'] ) ? strtolower( sanitize_email( $params['email'] ) ) : '';
		$consent  = array_key_exists( 'consent', $params ) && rest_sanitize_boolean( $params['consent'] );
		$honeypot = isset( $params['honeypot'] ) ? trim( (string) $params['honeypot'] ) : '';
		$ip       = self::get_client_ip();

		if ( '' !== $honeypot ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'We could not process that request.', 'beacon-campaign-sender' ),
				),
				400
			);
		}

		if ( self::is_rate_limited( $ip ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Too many subscription attempts. Please wait a bit and try again.', 'beacon-campaign-sender' ),
				),
				429
			);
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please enter a valid email address.', 'beacon-campaign-sender' ),
				),
				400
			);
		}

		if ( ! $consent ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please confirm that you want to sign up for emails.', 'beacon-campaign-sender' ),
				),
				400
			);
		}

		$brevo = new Bcsend_Brevo_API();
		if ( ! $brevo->is_configured() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Subscriptions are temporarily unavailable. Please try again later.', 'beacon-campaign-sender' ),
				),
				503
			);
		}

		$result = Bcsend_Subscriber_Ingest::register(
			array(
				'email'        => $email,
				'first_name'   => isset( $params['first_name'] ) ? sanitize_text_field( $params['first_name'] ) : '',
				'last_name'    => isset( $params['last_name'] ) ? sanitize_text_field( $params['last_name'] ) : '',
				'list_ids'     => self::sanitize_list_ids( isset( $params['list_ids'] ) ? $params['list_ids'] : array() ),
				'source'       => 'subscribe_page',
				'consent_text' => self::get_consent_text(),
				'ip_address'   => $ip,
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'metadata'     => array(
					'referrer' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				),
			)
		);

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'We could not save your subscription right now. Please try again shortly.', 'beacon-campaign-sender' ),
				),
				400
			);
		}

		if ( 'pending' === ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
			self::increment_rate_limit( $ip );
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Your subscription has been saved and is finishing in the background. You do not need to resubmit.', 'beacon-campaign-sender' ),
					'status'  => 'pending',
				),
				202
			);
		}

		self::increment_rate_limit( $ip );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: site name */
					__( "You're subscribed! Welcome to the %s newsletter.", 'beacon-campaign-sender' ),
					get_bloginfo( 'name' )
				),
				'status'  => isset( $result['status'] ) ? $result['status'] : 'confirmed',
			),
			200
		);
	}

	/**
	 * Render the subscribe shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'style'       => 'card',
				'button_text' => __( 'Sign me up', 'beacon-campaign-sender' ),
				'show_names'  => 'true',
				'list_ids'    => '',
				'class'       => '',
			),
			$atts,
			'bcsend_subscribe_form'
		);

		$form_id      = 'bcsend-subscribe-form-' . wp_generate_uuid4();
		$show_names   = filter_var( $atts['show_names'], FILTER_VALIDATE_BOOLEAN );
		$terms_markup = self::get_terms_fine_print_markup();
		$style_class  = 'inline' === $atts['style'] ? 'bcsend-subscribe-form-inline' : 'bcsend-subscribe-form-card';

		$extra_classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) $atts['class'] ) ) ) );
		if ( ! empty( $extra_classes ) ) {
			$style_class .= ' ' . implode( ' ', $extra_classes );
		}

		$rest_url     = esc_url( rest_url( 'beacon-campaign-sender/v1/subscribe' ) );
		$list_ids     = self::sanitize_list_ids( $atts['list_ids'] );
		self::enqueue_shortcode_assets();

		ob_start();
		?>
		<form class="bcsend-subscribe-form <?php echo esc_attr( $style_class ); ?>" id="<?php echo esc_attr( $form_id ); ?>" data-bcsend-rest-url="<?php echo esc_url( $rest_url ); ?>" data-bcsend-error="<?php echo esc_attr__( 'Something went wrong. Please try again.', 'beacon-campaign-sender' ); ?>">
			<?php if ( $show_names ) : ?>
				<div class="bcsend-subscribe-form-row">
					<input type="text" name="first_name" placeholder="<?php esc_attr_e( 'First name', 'beacon-campaign-sender' ); ?>" />
					<input type="text" name="last_name" placeholder="<?php esc_attr_e( 'Last name', 'beacon-campaign-sender' ); ?>" />
				</div>
			<?php endif; ?>
			<div class="bcsend-subscribe-form-row">
				<input type="email" name="email" required placeholder="<?php esc_attr_e( 'Email address', 'beacon-campaign-sender' ); ?>" />
			</div>
			<?php if ( ! empty( $list_ids ) ) : ?>
				<input type="hidden" name="list_ids" value="<?php echo esc_attr( implode( ',', $list_ids ) ); ?>" />
			<?php endif; ?>
			<div class="bcsend-subscribe-form-row bcsend-subscribe-form-consent">
				<label>
					<input type="checkbox" name="consent" value="1" required />
					<span><?php echo esc_html( self::get_consent_text() ); ?></span>
				</label>
			</div>
			<div class="bcsend-subscribe-form-row bcsend-subscribe-form-honeypot" aria-hidden="true">
				<label><?php esc_html_e( 'Website', 'beacon-campaign-sender' ); ?><input type="text" name="honeypot" tabindex="-1" autocomplete="off" /></label>
			</div>
			<div class="bcsend-subscribe-form-row">
				<button type="submit" class="button button-primary"><?php echo esc_html( $atts['button_text'] ); ?></button>
			</div>
			<?php if ( ! empty( $terms_markup ) ) : ?>
				<div class="bcsend-subscribe-form-fine-print"><?php echo wp_kses_post( $terms_markup ); ?></div>
			<?php endif; ?>
			<div class="bcsend-subscribe-form-message" aria-live="polite"></div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the front-end subscribe assets.
	 *
	 * The submit handler is loaded site-wide (not only with the shortcode) so
	 * visitor-built custom HTML forms using the documented markup also submit
	 * correctly. The handler is delegated, so it does nothing until a matching
	 * form is submitted.
	 *
	 * Saved custom CSS is loaded site-wide too, but only on pages whose content
	 * does not contain the shortcode. Shortcode pages load the same custom CSS
	 * from render (after the default styles, so it overrides them); skipping it
	 * here avoids duplicating the inline style on those pages.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		wp_register_script( 'bcsend-subscribe-form', false, array(), BCSEND_VERSION, true );
		wp_enqueue_script( 'bcsend-subscribe-form' );
		wp_add_inline_script(
			'bcsend-subscribe-form',
			'document.addEventListener("submit",async function(event){var form=event.target.closest(".bcsend-subscribe-form");if(!form){return;}event.preventDefault();var button=form.querySelector("button[type=\\"submit\\"]");var message=form.querySelector(".bcsend-subscribe-form-message");var get=function(name){var field=form.querySelector("[name=\\""+name+"\\"]");return field?field.value:"";};var payload={first_name:get("first_name"),last_name:get("last_name"),email:get("email"),consent:!!(form.querySelector("[name=\\"consent\\"]")&&form.querySelector("[name=\\"consent\\"]").checked),honeypot:get("honeypot"),list_ids:get("list_ids")};if(button){button.disabled=true;}if(message){message.textContent="";message.classList.remove("is-success","is-error");}try{var response=await fetch(form.getAttribute("data-bcsend-rest-url"),{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)});var data=await response.json();if(message){message.textContent=data.message||"";message.classList.add(response.ok?"is-success":"is-error");}if(response.ok){form.reset();}}catch(error){if(message){message.textContent=form.getAttribute("data-bcsend-error")||"Something went wrong. Please try again.";message.classList.add("is-error");}}finally{if(button){button.disabled=false;}}});'
		);

		$custom_css = self::get_subscribe_custom_css();
		if ( '' !== $custom_css && ! self::content_has_subscribe_shortcode() ) {
			wp_register_style( 'bcsend-subscribe-form-custom', false, array(), BCSEND_VERSION );
			wp_enqueue_style( 'bcsend-subscribe-form-custom' );
			wp_add_inline_style( 'bcsend-subscribe-form-custom', $custom_css );
		}
	}

	/**
	 * Enqueue shortcode styles: the built-in defaults plus any admin custom CSS.
	 *
	 * Custom CSS is appended after the defaults so its rules win on equal
	 * specificity. Styles load only where the shortcode renders; custom HTML
	 * embeds bring their own styling.
	 *
	 * @return void
	 */
	private static function enqueue_shortcode_assets() {
		wp_register_style( 'bcsend-subscribe-form', false, array(), BCSEND_VERSION );
		wp_enqueue_style( 'bcsend-subscribe-form' );
		wp_add_inline_style(
			'bcsend-subscribe-form',
			'.bcsend-subscribe-form{max-width:680px;margin:0 auto;padding:24px;border:1px solid #d0d5dd;border-radius:18px;background:#f2f4f7;display:grid;gap:12px}.bcsend-subscribe-form-inline{background:transparent;border:none;padding:0;margin:0 auto}.bcsend-subscribe-form-row{display:flex;gap:12px;flex-wrap:wrap}.bcsend-subscribe-form-row input[type="text"],.bcsend-subscribe-form-row input[type="email"]{flex:1 1 220px;padding:12px 14px;border:1px solid #c9d3de;border-radius:12px}.bcsend-subscribe-form-consent label{display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.5;color:#344054}.bcsend-subscribe-form-consent input[type="checkbox"]{margin-top:3px}.bcsend-subscribe-form-fine-print{font-size:12px;line-height:1.6;color:#475467}.bcsend-subscribe-form-fine-print a{color:#175cd3}.bcsend-subscribe-form-honeypot{position:absolute;left:-9999px;opacity:0;pointer-events:none}.bcsend-subscribe-form-message{font-size:14px}.bcsend-subscribe-form-message.is-error{color:#b42318}.bcsend-subscribe-form-message.is-success{color:#067647}'
		);

		$custom_css = self::get_subscribe_custom_css();
		if ( '' !== $custom_css ) {
			wp_add_inline_style( 'bcsend-subscribe-form', $custom_css );
		}
	}

	/**
	 * Resolve the admin-saved custom CSS for the subscribe form.
	 *
	 * @return string
	 */
	private static function get_subscribe_custom_css() {
		$settings = self::get_frontend_settings();

		return isset( $settings['subscribe_custom_css'] ) ? trim( (string) $settings['subscribe_custom_css'] ) : '';
	}

	/**
	 * Whether the current singular content contains the subscribe shortcode.
	 *
	 * Used to decide whether custom CSS is already loaded by the shortcode
	 * render, so the site-wide enqueue can avoid duplicating it.
	 *
	 * @return bool
	 */
	private static function content_has_subscribe_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'bcsend_subscribe_form' );
	}

	/**
	 * Resolve current subscribe-form consent copy.
	 *
	 * @return string
	 */
	private static function get_consent_text() {
		return (string) apply_filters(
			'bcsend_subscribe_form_consent_text',
			sprintf(
				/* translators: %s: site name */
				__( 'By signing up, you agree to receive the %s newsletter by email.', 'beacon-campaign-sender' ),
				get_bloginfo( 'name' )
			)
		);
	}

	/**
	 * Build optional terms fine print for the public subscribe form.
	 *
	 * @return string
	 */
	private static function get_terms_fine_print_markup() {
		$settings     = self::get_frontend_settings();
		$url          = isset( $settings['subscribe_terms_url'] ) ? esc_url( $settings['subscribe_terms_url'] ) : '';
		$text         = isset( $settings['subscribe_terms_text'] ) ? trim( (string) $settings['subscribe_terms_text'] ) : '';
		$link_text    = isset( $settings['subscribe_terms_link_text'] ) ? trim( (string) $settings['subscribe_terms_link_text'] ) : '';
		$consent_text = trim( self::get_consent_text() );

		if ( '' === $consent_text ) {
			$consent_text = sprintf(
				/* translators: %s: site name */
				__( 'By signing up, you agree to receive the %s newsletter by email.', 'beacon-campaign-sender' ),
				get_bloginfo( 'name' )
			);
		}

		if ( '' === $url || '' === $text || '' === $link_text ) {
			return esc_html( $consent_text );
		}

		return sprintf(
			'%1$s %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>.',
			esc_html( untrailingslashit( rtrim( $consent_text, '.' ) ) ),
			esc_html( strtolower( $text ) ),
			$url,
			esc_html( $link_text )
		);
	}

	/**
	 * Get frontend-safe settings needed for the subscribe form.
	 *
	 * Avoids relying on admin-only classes during public shortcode rendering.
	 *
	 * @return array
	 */
	private static function get_frontend_settings() {
		$settings = get_option( 'bcsend_settings', array() );

		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			array(
				'subscribe_terms_url'       => home_url( '/terms-of-service/' ),
				'subscribe_terms_text'      => __( 'By signing up, you agree to our', 'beacon-campaign-sender' ),
				'subscribe_terms_link_text' => __( 'Terms of Service', 'beacon-campaign-sender' ),
				'subscribe_custom_css'      => '',
			)
		);
	}

	/**
	 * Resolve current client IP for rate limiting and audit logs.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return sanitize_text_field( trim( $forwarded[0] ) );
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Build the transient key for a client IP.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private static function rate_limit_key( $ip ) {
		return 'bcsend_subscribe_rate_' . md5( (string) $ip );
	}

	/**
	 * Check whether a client is currently rate limited.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_rate_limited( $ip ) {
		$count = (int) get_transient( self::rate_limit_key( $ip ) );
		return $count >= 5;
	}

	/**
	 * Increment the client rate-limit counter.
	 *
	 * @param string $ip IP address.
	 * @return void
	 */
	private static function increment_rate_limit( $ip ) {
		$key   = self::rate_limit_key( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Sanitize list IDs from shortcode or request input.
	 *
	 * @param mixed $value Raw list IDs.
	 * @return array
	 */
	private static function sanitize_list_ids( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', trim( $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
	}
}
