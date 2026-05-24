<?php
/**
 * Shared AI service for Beacon Campaign Sender.
 *
 * Centralizes provider selection and campaign generation flows so
 * admin AJAX handlers and external tool integrations use the same
 * orchestration logic.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_AI_Service
 */
class Bcsend_AI_Service {

	/**
	 * Get decrypted Beacon Campaign Sender settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return class_exists( 'Bcsend_Settings' ) ? Bcsend_Settings::get_settings() : Bcsend_Encryption::decrypt_settings( get_option( 'bcsend_settings', array() ) );
	}

	/**
	 * Get the configured provider slug.
	 *
	 * @param array|null $settings Optional decrypted settings.
	 * @return string
	 */
	public static function get_provider( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'anthropic';

		return in_array( $provider, array( 'anthropic', 'openai' ), true ) ? $provider : 'anthropic';
	}

	/**
	 * Get a human-readable provider label.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_provider_label( $provider ) {
		return 'openai' === $provider ? 'OpenAI' : 'Anthropic';
	}

	/**
	 * Get the configured provider label.
	 *
	 * @param array|null $settings Optional decrypted settings.
	 * @return string
	 */
	public static function get_configured_provider_label( $settings = null ) {
		return self::get_provider_label( self::get_provider( $settings ) );
	}

	/**
	 * Instantiate the selected provider client.
	 *
	 * @param string|null $provider Optional provider override.
	 * @param array|null  $settings Optional decrypted settings.
	 * @return Bcsend_Anthropic_API|Bcsend_OpenAI_API
	 */
	public static function get_client( $provider = null, $settings = null ) {
		$provider = $provider ? $provider : self::get_provider( $settings );

		return 'openai' === $provider ? new Bcsend_OpenAI_API() : new Bcsend_Anthropic_API();
	}

	/**
	 * Ensure the configured AI client is ready to use.
	 *
	 * @param array|null $settings Optional decrypted settings.
	 * @return array|WP_Error Array with provider, label, client, and settings.
	 */
	public static function get_ready_client( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$provider = self::get_provider( $settings );
		$label    = self::get_provider_label( $provider );
		$client   = self::get_client( $provider, $settings );

		if ( ! $client->is_configured() ) {
			return new WP_Error(
				'ai_not_configured',
				sprintf( __( '%s API key not configured.', 'beacon-campaign-sender' ), $label )
			);
		}

		return array(
			'settings' => $settings,
			'provider' => $provider,
			'label'    => $label,
			'client'   => $client,
		);
	}

	/**
	 * Resolve the social post mode for AI prompt shaping.
	 *
	 * @param string     $mode     Explicit campaign/request mode.
	 * @param array|null $settings Optional decrypted settings fallback.
	 * @return string
	 */
	private static function resolve_social_post_mode( $mode = '', $settings = null ) {
		$mode = sanitize_key( (string) $mode );
		if ( in_array( $mode, array( 'single', 'per_platform' ), true ) ) {
			return $mode;
		}

		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$mode     = isset( $settings['zernio_post_mode'] ) ? sanitize_key( (string) $settings['zernio_post_mode'] ) : '';

		return in_array( $mode, array( 'single', 'per_platform' ), true ) ? $mode : 'single';
	}

	/**
	 * Generate a sample content payload.
	 *
	 * @param string $prompt Prompt text.
	 * @param string $type   Sample type: email or push.
	 * @return array|WP_Error
	 */
	public static function generate_sample( $prompt, $type = 'email' ) {
		$context = self::get_ready_client();

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$settings = $context['settings'];
		$client   = $context['client'];

		if ( 'push' === $type ) {
			$generated = $client->regenerate_push( $prompt );
		} else {
			$generated = $client->generate_campaign(
				null,
				$prompt,
				isset( $settings['brand_voice'] ) ? $settings['brand_voice'] : '',
				isset( $settings['base_template'] ) ? $settings['base_template'] : '',
				array( 'email', 'push' ),
				array()
			);
		}

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		if ( isset( $generated['social'] ) ) {
			$generated['social'] = self::normalize_generated_social_entries( $generated['social'] );
		}

		return array(
			'provider' => $context['provider'],
			'content'  => $generated,
		);
	}

	/**
	 * Generate campaign content from admin composer inputs.
	 *
	 * @param array  $product_ids WooCommerce product IDs.
	 * @param string $prompt      Campaign prompt.
	 * @param int    $template_id Optional template ID.
	 * @return array|WP_Error
	 */
	public static function generate_campaign_from_request( $product_ids, $prompt, $template_id = 0, $current_html = '', $image_urls = array(), $post_ids = array(), $channels = array( 'email', 'push' ), $social_platforms = array(), $social_post_mode = '' ) {
		// Build structured content blocks for all selected items.
		$content_blocks = array();

		// Products — each gets a full structured block.
		if ( ! empty( $product_ids ) && function_exists( 'wc_get_product' ) ) {
			$product_num = 0;
			foreach ( $product_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}
				++$product_num;
				$image_id  = $product->get_image_id();
				$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

				$block   = array();
				$block[] = sprintf( 'Product %d:', $product_num );
				$block[] = sprintf( '  Title: %s', html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) );
				$block[] = sprintf( '  Price: $%s', $product->get_price() );
				$desc    = wp_strip_all_tags( $product->get_short_description() );
				if ( $desc ) {
					$block[] = sprintf( '  Description: %s', $desc );
				}
				if ( $image_url ) {
					$block[] = sprintf( '  Image URL: %s', $image_url );
				}
				$block[] = sprintf( '  Product URL: %s', $product->get_permalink() );

				$content_blocks[] = implode( "\n", $block );
			}
		}

		// Posts/pages — each gets a full structured block.
		if ( ! empty( $post_ids ) ) {
			$post_num = 0;
			foreach ( $post_ids as $pid ) {
				$p = get_post( $pid );
				if ( ! $p ) {
					continue;
				}
				++$post_num;
				$thumb   = get_the_post_thumbnail_url( $pid, 'large' );
				$excerpt = wp_trim_words( get_the_excerpt( $p ), 40, '...' );

				$block   = array();
				$block[] = sprintf( 'Article %d:', $post_num );
				$block[] = sprintf( '  Title: %s', html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) );
				if ( $thumb ) {
					$block[] = sprintf( '  Featured Image URL: %s', $thumb );
				}
				$block[] = sprintf( '  Link: %s', get_permalink( $p ) );
				if ( $excerpt ) {
					$block[] = sprintf( '  Excerpt: %s', html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' ) );
				}

				$content_blocks[] = implode( "\n", $block );
			}
		}

		// Media images — structured block.
		if ( ! empty( $image_urls ) ) {
			$img_num = 0;
			foreach ( $image_urls as $url ) {
				++$img_num;
				$content_blocks[] = sprintf( "Image %d:\n  URL: %s", $img_num, $url );
			}
		}

		// Build the first product data for backwards compat with generate_campaign().
		$product_data = null;
		if ( ! empty( $product_ids ) && function_exists( 'wc_get_product' ) ) {
			$first = wc_get_product( $product_ids[0] );
			if ( $first ) {
				$first_img    = $first->get_image_id() ? wp_get_attachment_url( $first->get_image_id() ) : '';
				$product_data = array(
					'title'       => html_entity_decode( $first->get_name(), ENT_QUOTES, 'UTF-8' ),
					'price'       => $first->get_price(),
					'description' => wp_strip_all_tags( $first->get_short_description() ),
					'image_url'   => $first_img,
					'permalink'   => $first->get_permalink(),
				);
			}
		}

		// Prepend structured content blocks to the prompt.
		if ( ! empty( $content_blocks ) ) {
			$prompt = "Content to include in the email:\n\n" . implode( "\n\n", $content_blocks ) . "\n\n" . $prompt;
		}

		// If there's existing HTML, use it as the base template so the AI edits it
		// rather than generating from scratch.
		if ( ! empty( $current_html ) ) {
			$template_html = $current_html;
		} else {
			$template_html = self::get_template_html( (int) $template_id );
		}

		return self::generate_campaign_content( $product_data, $prompt, $template_html, $channels, $social_platforms, $social_post_mode );
	}

	/**
	 * Generate campaign content with shared provider selection.
	 *
	 * @param array|null  $product_data      Optional product context.
	 * @param string      $prompt            Campaign prompt.
	 * @param string|null $base_template_html Optional template override.
	 * @return array|WP_Error
	 */
	public static function generate_campaign_content( $product_data = null, $prompt = '', $base_template_html = null, $channels = array( 'email', 'push' ), $social_platforms = array(), $social_post_mode = '' ) {
		$context = self::get_ready_client();

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$settings      = $context['settings'];
		$brand_voice   = isset( $settings['brand_voice'] ) ? $settings['brand_voice'] : '';
		$base_template = null !== $base_template_html ? $base_template_html : ( isset( $settings['base_template'] ) ? $settings['base_template'] : '' );
		$generated     = $context['client']->generate_campaign( $product_data, $prompt, $brand_voice, $base_template, $channels, $social_platforms, self::resolve_social_post_mode( $social_post_mode, $settings ) );

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		if ( isset( $generated['social'] ) ) {
			$generated['social'] = self::normalize_generated_social_entries( $generated['social'] );
		}

		return array(
			'provider' => $context['provider'],
			'content'  => $generated,
		);
	}

	/**
	 * Regenerate HTML from existing campaign or direct plain text.
	 *
	 * @param int    $campaign_id Optional campaign ID.
	 * @param string $plain_text  Optional direct plain text.
	 * @return array|WP_Error
	 */
	public static function regenerate_html_from_request( $campaign_id = 0, $plain_text = '' ) {
		if ( $campaign_id ) {
			$campaign = self::get_draft_campaign( $campaign_id );

			if ( is_wp_error( $campaign ) ) {
				return $campaign;
			}

			if ( empty( $plain_text ) ) {
				$plain_text = isset( $campaign['plain_text'] ) ? $campaign['plain_text'] : '';
			}
		}

		if ( empty( $plain_text ) ) {
			return new WP_Error( 'missing_plain_text', __( 'Plain text content is required.', 'beacon-campaign-sender' ) );
		}

		$context = self::get_ready_client();

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$settings  = $context['settings'];
		$generated = $context['client']->regenerate_html(
			$plain_text,
			isset( $settings['brand_voice'] ) ? $settings['brand_voice'] : '',
			isset( $settings['base_template'] ) ? $settings['base_template'] : ''
		);

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		return array(
			'provider'     => $context['provider'],
			'html_content' => isset( $generated['html_content'] ) ? $generated['html_content'] : '',
		);
	}

	/**
	 * Regenerate push content from existing campaign or direct context.
	 *
	 * @param int    $campaign_id  Optional campaign ID.
	 * @param string $context_text Optional context override.
	 * @param string $prompt       Optional extra instructions.
	 * @return array|WP_Error
	 */
	public static function regenerate_push_from_request( $campaign_id = 0, $context_text = '', $prompt = '' ) {
		if ( $campaign_id ) {
			$campaign = self::get_draft_campaign( $campaign_id );

			if ( is_wp_error( $campaign ) ) {
				return $campaign;
			}

			if ( empty( $context_text ) ) {
				$context_text  = 'Campaign name: ' . $campaign['name'] . "\n";
				$context_text .= 'Email subject: ' . $campaign['subject'] . "\n";
				$context_text .= 'Plain text: ' . $campaign['plain_text'] . "\n";
				if ( ! empty( $prompt ) ) {
					$context_text .= 'Additional instructions: ' . $prompt . "\n";
				}
			}
		}

		if ( empty( $context_text ) ) {
			return new WP_Error( 'missing_push_context', __( 'Push generation context is required.', 'beacon-campaign-sender' ) );
		}

		$context = self::get_ready_client();

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$generated = $context['client']->regenerate_push( $context_text );

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		return array(
			'provider'     => $context['provider'],
			'push_title'   => isset( $generated['push_title'] ) ? $generated['push_title'] : '',
			'push_message' => isset( $generated['push_message'] ) ? $generated['push_message'] : '',
		);
	}

	/**
	 * Regenerate social content from an existing draft or direct context.
	 *
	 * @param int    $campaign_id  Optional campaign ID.
	 * @param string $context_text Optional context override.
	 * @param array  $platforms    Social platforms to generate for.
	 * @param string $prompt       Optional extra instructions.
	 * @return array|WP_Error
	 */
	public static function regenerate_social_from_request( $campaign_id = 0, $context_text = '', $platforms = array(), $prompt = '', $social_post_mode = '' ) {
		if ( $campaign_id ) {
			$campaign = self::get_draft_campaign( $campaign_id );

			if ( is_wp_error( $campaign ) ) {
				return $campaign;
			}

			if ( empty( $context_text ) ) {
				$context_text  = 'Campaign name: ' . $campaign['name'] . "\n";
				$context_text .= 'Email subject: ' . $campaign['subject'] . "\n";
				$context_text .= 'Plain text: ' . $campaign['plain_text'] . "\n";
				if ( ! empty( $prompt ) ) {
					$context_text .= 'Additional instructions: ' . $prompt . "\n";
				}
			}

			$social_post_mode = isset( $campaign['social_post_mode'] ) ? $campaign['social_post_mode'] : $social_post_mode;
		}

		if ( empty( $context_text ) ) {
			return new WP_Error( 'missing_social_context', __( 'Social generation context is required.', 'beacon-campaign-sender' ) );
		}

		$context = self::get_ready_client();

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$settings    = $context['settings'];
		$brand_voice = isset( $settings['brand_voice'] ) ? $settings['brand_voice'] : '';
		$generated   = $context['client']->regenerate_social( $context_text, $platforms, $brand_voice, self::resolve_social_post_mode( $social_post_mode, $settings ) );

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$social = isset( $generated['social'] ) && is_array( $generated['social'] ) ? self::normalize_generated_social_entries( $generated['social'] ) : array();

		return array(
			'provider' => $context['provider'],
			'social'   => $social,
		);
	}

	/**
	 * Extract readable text from a structured generated social content payload.
	 *
	 * @param mixed $content Raw generated content.
	 * @return string
	 */
	private static function extract_generated_social_text( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$text_keys = array( 'text', 'value', 'caption', 'body', 'message' );

		foreach ( $text_keys as $key ) {
			if ( isset( $content[ $key ] ) && is_string( $content[ $key ] ) ) {
				return $content[ $key ];
			}
		}

		if ( isset( $content['content'] ) ) {
			$nested = self::extract_generated_social_text( $content['content'] );
			if ( '' !== $nested ) {
				return $nested;
			}
		}

		if ( isset( $content['parts'] ) && is_array( $content['parts'] ) ) {
			$parts = array();
			foreach ( $content['parts'] as $part ) {
				$part_text = self::extract_generated_social_text( $part );
				if ( '' !== $part_text ) {
					$parts[] = $part_text;
				}
			}

			if ( ! empty( $parts ) ) {
				return implode( "\n", $parts );
			}
		}

		foreach ( $content as $value ) {
			$nested = self::extract_generated_social_text( $value );
			if ( '' !== $nested ) {
				return $nested;
			}
		}

		return '';
	}

	/**
	 * Normalize escaped newline sequences in generated social copy.
	 *
	 * Some model responses double-escape newlines in JSON strings, which can
	 * later degrade into literal "nn" fragments after textarea sanitization.
	 *
	 * @param mixed $content Raw generated content.
	 * @return string
	 */
	private static function normalize_generated_social_text( $content ) {
		$content = self::extract_generated_social_text( $content );
		$content = str_replace( array( "\\r\\n", "\\n", "\\r" ), array( "\n", "\n", "\n" ), $content );
		$content = preg_replace( "/\n{3,}/", "\n\n", $content );

		return trim( $content );
	}

	/**
	 * Normalize generated social entries to a structured per-platform payload.
	 *
	 * @param array $social Raw social payload from the provider.
	 * @return array
	 */
	private static function normalize_generated_social_entries( $social ) {
		if ( ! is_array( $social ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $social as $platform => $entry ) {
			$link_mode         = 'none';
			$link_url          = '';
			$media_suggestions = array();
			$media_items       = array();
			$content           = '';

			if ( is_array( $entry ) ) {
				$content           = isset( $entry['content'] ) ? self::normalize_generated_social_text( $entry['content'] ) : '';
				$link_mode         = isset( $entry['link_mode'] ) ? sanitize_key( $entry['link_mode'] ) : 'none';
				$link_url          = isset( $entry['link_url'] ) ? esc_url_raw( $entry['link_url'] ) : '';
				$media_suggestions = isset( $entry['media_suggestions'] ) && is_array( $entry['media_suggestions'] ) ? array_values( array_map( 'sanitize_key', $entry['media_suggestions'] ) ) : array();
				$media_items       = isset( $entry['media_items'] ) && is_array( $entry['media_items'] ) ? self::normalize_generated_social_media_items( $entry['media_items'] ) : array();
			} else {
				$content = self::normalize_generated_social_text( $entry );
			}

			if ( ! in_array( $link_mode, array( 'none', 'product', 'homepage', 'custom', 'link_in_bio' ), true ) ) {
				$link_mode = 'none';
			}

			$normalized[ sanitize_key( $platform ) ] = array(
				'content'           => $content,
				'link_mode'         => $link_mode,
				'link_url'          => $link_url,
				'media_suggestions' => $media_suggestions,
				'media_items'       => $media_items,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize generated media items into a safe array shape.
	 *
	 * @param array $items Raw media items.
	 * @return array
	 */
	private static function normalize_generated_social_media_items( $items ) {
		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) ) {
				continue;
			}

			$url = esc_url_raw( $item['url'] );
			if ( empty( $url ) ) {
				continue;
			}

			$normalized[] = array(
				'id'    => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
				'type'  => isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'image',
				'url'   => $url,
				'alt'   => isset( $item['alt'] ) ? sanitize_text_field( $item['alt'] ) : '',
				'title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'thumb' => isset( $item['thumb'] ) ? esc_url_raw( $item['thumb'] ) : $url,
			);
		}

		return $normalized;
	}

	/**
	 * Load a draft campaign by ID.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	private static function get_draft_campaign( $campaign_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'bcsend_campaigns';
		$campaign = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $campaign_id ),
			ARRAY_A
		);

		if ( ! $campaign ) {
			return new WP_Error( 'campaign_not_found', __( 'Campaign not found.', 'beacon-campaign-sender' ) );
		}

		if ( 'draft' !== $campaign['status'] ) {
			return new WP_Error( 'invalid_campaign_status', __( 'Only draft campaigns can be regenerated.', 'beacon-campaign-sender' ) );
		}

		return $campaign;
	}

	/**
	 * Load template HTML by ID.
	 *
	 * @param int $template_id Template ID.
	 * @return string
	 */
	private static function get_template_html( $template_id ) {
		global $wpdb;

		if ( empty( $template_id ) ) {
			return '';
		}

		$table = $wpdb->prefix . 'bcsend_templates';

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT html_content FROM {$table} WHERE id = %d",
				$template_id
			)
		);
	}
}
