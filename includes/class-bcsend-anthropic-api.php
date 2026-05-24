<?php
/**
 * Anthropic Claude API integration for Beacon Campaign Sender.
 *
 * Handles AI-powered email campaign content generation including
 * subject lines, HTML/plain text bodies, and push notifications.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Anthropic_API
 *
 * Provides methods for generating campaign content via the Anthropic Messages API.
 *
 * @since 1.0.0
 */
class Bcsend_Anthropic_API {

	/**
	 * Anthropic Messages API endpoint.
	 *
	 * @var string
	 */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * Maximum number of retry attempts for transient failures.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Maximum tokens for response generation.
	 *
	 * @var int
	 */
	const MAX_TOKENS = 4096;

	/**
	 * Decrypted Anthropic API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Claude model identifier.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * Reads plugin settings and decrypts the Anthropic API key.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$settings = get_option( 'bcsend_settings', array() );

		$encrypted_key = isset( $settings['anthropic_api_key'] ) ? $settings['anthropic_api_key'] : '';

		if ( ! empty( $encrypted_key ) ) {
			$this->api_key = Bcsend_Encryption::decrypt( $encrypted_key );
		} else {
			$this->api_key = '';
		}

		$this->model = isset( $settings['anthropic_model'] ) && ! empty( $settings['anthropic_model'] )
			? $settings['anthropic_model']
			: 'claude-sonnet-4-6';
	}

	/**
	 * Check whether the Anthropic API is configured with a valid key.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the API key is present.
	 */
	public function is_configured() {
		return ! empty( $this->api_key );
	}

	/**
	 * Run a lightweight connection test.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$response = $this->request(
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with OK.',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'message' => 'Connected successfully.',
			'model'   => $this->model,
		);
	}

	/**
	 * Send a request to the Anthropic Messages API with retry logic.
	 *
	 * Retries up to 3 times for 5xx errors and timeouts with exponential backoff.
	 * Does not retry 400, 401, 403, or 404 responses.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $messages Array of message objects ({role, content}).
	 * @param string $system   Optional system prompt.
	 *
	 * @return string|WP_Error Extracted text content or WP_Error on failure.
	 */
	private function request( $messages, $system = '' ) {
		$body = array(
			'model'      => $this->model,
			'max_tokens' => self::MAX_TOKENS,
			'messages'   => $messages,
		);

		if ( ! empty( $system ) ) {
			$body['system'] = $system;
		}

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		);

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_RETRIES ) {
			++$attempt;

			if ( $attempt > 1 ) {
				$backoff = pow( 2, $attempt );
				sleep( $backoff );
			}

			$response = wp_remote_post( self::API_URL, $args );

			// Connection-level error (timeout, DNS failure, etc.).
			if ( is_wp_error( $response ) ) {
				$last_error = $response;

				Bcsend_Logger::log(
					'generation',
					'Anthropic API connection error',
					wp_json_encode(
						array(
							'service' => 'anthropic',
							'model'   => $this->model,
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

			Bcsend_Logger::log(
				'generation',
				'Anthropic API request sent',
				wp_json_encode(
					array(
						'service'     => 'anthropic',
						'model'       => $this->model,
						'attempt'     => $attempt,
						'status_code' => $code,
					)
				)
			);

			// Success.
			if ( 200 === $code ) {
				if ( isset( $decoded_body['content'][0]['text'] ) ) {
					return $decoded_body['content'][0]['text'];
				}

				return new WP_Error(
					'anthropic_api_error',
					'Unexpected response format from Anthropic API.',
					array( 'response' => $decoded_body )
				);
			}

			// Non-retryable client errors.
			if ( in_array( $code, array( 400, 401, 403, 404 ), true ) ) {
				$error_message = isset( $decoded_body['error']['message'] )
					? $decoded_body['error']['message']
					: 'Anthropic API error';

				return new WP_Error(
					'anthropic_api_error',
					sprintf( '%s (HTTP %d)', $error_message, $code ),
					array(
						'status_code' => $code,
						'response'    => $decoded_body,
					)
				);
			}

			// 5xx or other retryable errors.
			$last_error = new WP_Error(
				'anthropic_api_error',
				sprintf( 'Anthropic API returned HTTP %d', $code ),
				array(
					'status_code' => $code,
					'response'    => $decoded_body,
				)
			);
		}

		// All retries exhausted.
		if ( ! is_wp_error( $last_error ) ) {
			$last_error = new WP_Error(
				'anthropic_api_error',
				'Anthropic API request failed after maximum retries.'
			);
		}

		return $last_error;
	}

	/**
	 * Extract and parse a JSON object from AI response text.
	 *
	 * Handles both raw JSON and JSON wrapped in ```json ... ``` code blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Raw text content from Claude.
	 *
	 * @return array|WP_Error Decoded associative array or WP_Error.
	 */
	private function parse_json_response( $content ) {
		$json_string = trim( $content );

		// Strip markdown code block wrapper if present.
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $json_string, $matches ) ) {
			$json_string = trim( $matches[1] );
		}

		// Try direct parse first.
		$decoded = json_decode( $json_string, true );

		if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}

		// Extract the first JSON object from the response (handles text before/after).
		if ( preg_match( '/\{[\s\S]*\}/s', $json_string, $matches ) ) {
			$decoded = json_decode( $matches[0], true );

			if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}

		// Last resort: try to fix common issues — escaped newlines in HTML content.
		$fixed = preg_replace( '/(?<!\\\\)\\\\n/', '\\\\n', $json_string );
		if ( $fixed !== $json_string ) {
			$decoded = json_decode( $fixed, true );

			if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}

		return new WP_Error(
			'anthropic_parse_error',
			sprintf( 'Failed to parse JSON from Anthropic response: %s', json_last_error_msg() ),
			array( 'raw_content' => mb_substr( $content, 0, 500 ) )
		);
	}

	/**
	 * Generate a full email campaign including push notification content.
	 *
	 * Returns an array with keys: subject, preview_text, html_content,
	 * plain_text, push_title (max 26 chars), push_message (max 354 chars).
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $product_data  Optional product data (title, description, price, image_url, permalink).
	 * @param string     $prompt        User prompt describing the desired campaign.
	 * @param string     $brand_voice   Optional brand voice description.
	 * @param string     $base_template Optional base HTML template to follow.
	 *
	 * @return array|WP_Error Campaign content array or WP_Error.
	 */
	public function generate_campaign( $product_data = null, $prompt = '', $brand_voice = '', $base_template = '', $channels = array( 'email', 'push' ), $social_platforms = array(), $social_post_mode = 'single' ) {
		$has_email  = in_array( 'email', $channels, true );
		$has_social = in_array( 'social', $channels, true ) && ! empty( $social_platforms );

		// Adapt persona to active channels.
		if ( $has_email && $has_social ) {
			$system_parts = array( 'You are an email and social media marketing content specialist.' );
		} elseif ( $has_social ) {
			$system_parts = array( 'You are a social media marketing content specialist.' );
		} else {
			$system_parts = array( 'You are an email marketing content specialist.' );
		}

		if ( ! empty( $brand_voice ) ) {
			$system_parts[] = sprintf( 'Use the following brand voice: %s', $brand_voice );
		}

		if ( $has_email ) {
			if ( ! empty( $base_template ) ) {
				// Detect if this is existing campaign HTML (contains typical email markup) vs a blank template.
				$is_existing = ( false !== strpos( $base_template, '</html>' ) || false !== strpos( $base_template, '</table>' ) || mb_strlen( $base_template ) > 200 );

				if ( $is_existing ) {
					$system_parts[] = 'The user has an existing email campaign. Here is the current HTML. Apply the user\'s requested changes to this HTML — do not start from scratch. Preserve the existing design and style but ADD any new content the user provides (products, images, posts/articles).';
					$system_parts[] = sprintf( 'Current email HTML: %s', $base_template );
				} else {
					$system_parts[] = sprintf( 'Use the following base HTML template as a starting point for the html_content: %s', $base_template );
				}
			}

			$system_parts[] = 'If product information includes an Image URL, include it as an <img> tag in the html_content.';
			$system_parts[] = 'If product information includes a Product URL, link the product name and image to it in the html_content.';
			$system_parts[] = 'IMPORTANT: If the user provides image URLs to include, you MUST add every one as an <img> tag in the html_content with proper styling. Never omit provided images.';
			$system_parts[] = 'IMPORTANT: If the user provides posts/pages to include, you MUST add a section for each one in the html_content with its title as a linked heading, the featured image (if provided) as an <img> tag, the excerpt text, and a Read More link. Never omit provided posts.';
		}
		$required_keys = array();
		if ( $has_email ) {
			$required_keys = array_merge( $required_keys, array( 'name', 'subject', 'preview_text', 'html_content', 'plain_text' ) );
		}
		if ( in_array( 'push', $channels, true ) ) {
			$required_keys = array_merge( $required_keys, array( 'push_title (max 26 chars)', 'push_message (max 354 chars)' ) );
		}
		if ( $has_social ) {
			$social_post_mode = in_array( $social_post_mode, array( 'single', 'per_platform' ), true ) ? $social_post_mode : 'single';
			$platform_prompts = array();
			$strictest_limit  = null;
			foreach ( $social_platforms as $platform ) {
				$limit              = Bcsend_Zernio_API::get_platform_char_limit( $platform );
				$strictest_limit    = null === $strictest_limit ? $limit : min( $strictest_limit, $limit );
				$platform_prompts[] = sprintf( '%s (max %d chars)', $platform, $limit );
			}
			if ( 'single' === $social_post_mode ) {
				$system_parts[] = sprintf( 'Also generate ONE shared cross-platform social post optimized for all requested platforms and kept under %d characters.', (int) $strictest_limit );
				$system_parts[] = 'Return the same shared social post content under each requested platform key so the app can preserve account selection while publishing one Zernio post.';
			} else {
				$system_parts[] = 'Also generate tailored social posts for each requested platform using the appropriate tone and platform conventions.';
			}
			$system_parts[]  = 'Generate social posts only for these platforms: ' . implode( ', ', $platform_prompts ) . '.';
			$system_parts[]  = 'Return a "social" object with exactly those platform keys.';
			$system_parts[]  = 'Each social platform entry must be an object with keys: content, link_mode, link_url, media_suggestions.';
			$system_parts[]  = 'Use link_mode values only from: none, product, homepage, custom, link_in_bio.';
			$system_parts[]  = 'Use media_suggestions as an array containing zero or more of: featured_image, product_image, selected_media.';
			$required_keys[] = 'social { ' . implode( ', ', $platform_prompts ) . ' as structured objects }';
		}
		$system_parts[] = 'Return ONLY valid JSON with these exact keys: ' . implode( ', ', $required_keys ) . '.';

		$system_prompt = implode( ' ', $system_parts );

		// Build user message.
		$user_parts = array();

		if ( ! empty( $product_data ) && is_array( $product_data ) ) {
			$product_lines = array( 'Product information:' );

			if ( isset( $product_data['title'] ) ) {
				$product_lines[] = sprintf( 'Title: %s', $product_data['title'] );
			}
			if ( isset( $product_data['description'] ) ) {
				$product_lines[] = sprintf( 'Description: %s', $product_data['description'] );
			}
			if ( isset( $product_data['price'] ) ) {
				$product_lines[] = sprintf( 'Price: %s', $product_data['price'] );
			}
			if ( isset( $product_data['image_url'] ) ) {
				$product_lines[] = sprintf( 'Image URL: %s', $product_data['image_url'] );
			}
			if ( isset( $product_data['permalink'] ) ) {
				$product_lines[] = sprintf( 'Product URL: %s', $product_data['permalink'] );
			}

			$user_parts[] = implode( "\n", $product_lines );
		}

		if ( ! empty( $prompt ) ) {
			$user_parts[] = $prompt;
		}

		$user_message = implode( "\n\n", $user_parts );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $user_message,
			),
		);

		$response = $this->request( $messages, $system_prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_json_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// Validate required keys.
		$base_required = array( 'name', 'subject', 'preview_text', 'html_content', 'plain_text', 'push_title', 'push_message' );
		foreach ( $base_required as $key ) {
			if ( ! isset( $parsed[ $key ] ) ) {
				$parsed[ $key ] = '';
			}
		}

		if ( empty( $parsed['name'] ) ) {
			$parsed['name'] = ! empty( $parsed['subject'] )
				? mb_substr( wp_strip_all_tags( $parsed['subject'] ), 0, 80 )
				: 'AI Campaign';
		}

		// Enforce push notification length limits.
		$parsed['push_title']   = mb_substr( $parsed['push_title'], 0, 26 );
		$parsed['push_message'] = mb_substr( $parsed['push_message'], 0, 354 );
		if ( ! isset( $parsed['social'] ) || ! is_array( $parsed['social'] ) ) {
			$parsed['social'] = array();
		}
		foreach ( $parsed['social'] as $platform => $entry ) {
			if ( is_array( $entry ) ) {
				$entry['content']              = mb_substr(
					isset( $entry['content'] ) ? (string) $entry['content'] : '',
					0,
					Bcsend_Zernio_API::get_platform_char_limit( $platform )
				);
				$parsed['social'][ $platform ] = $entry;
			} else {
				$parsed['social'][ $platform ] = mb_substr( (string) $entry, 0, Bcsend_Zernio_API::get_platform_char_limit( $platform ) );
			}
		}

		return $parsed;
	}

	/**
	 * Regenerate HTML email content from plain text.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plain_text    Plain text content to convert to HTML.
	 * @param string $brand_voice   Optional brand voice description.
	 * @param string $base_template Optional base HTML template to follow.
	 *
	 * @return array|WP_Error Array with html_content key or WP_Error.
	 */
	public function regenerate_html( $plain_text, $brand_voice = '', $base_template = '' ) {
		$system_parts = array(
			'You are an email marketing HTML specialist. Convert the provided plain text into a beautifully formatted HTML email.',
		);

		if ( ! empty( $brand_voice ) ) {
			$system_parts[] = sprintf( 'Use the following brand voice: %s', $brand_voice );
		}

		if ( ! empty( $base_template ) ) {
			$system_parts[] = sprintf( 'Use the following base HTML template as a starting point: %s', $base_template );
		}

		$system_parts[] = 'Return ONLY valid JSON with this exact key: html_content.';

		$system_prompt = implode( ' ', $system_parts );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $plain_text,
			),
		);

		$response = $this->request( $messages, $system_prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_json_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! isset( $parsed['html_content'] ) ) {
			$parsed['html_content'] = '';
		}

		return $parsed;
	}

	/**
	 * Regenerate push notification title and message from context.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context_text Context text to generate push notification from.
	 *
	 * @return array|WP_Error Array with push_title (max 26) and push_message (max 354) or WP_Error.
	 */
	public function regenerate_push( $context_text ) {
		$system_prompt = 'You are a push notification specialist. Generate a compelling push notification from the provided context. '
			. 'The push_title must be a maximum of 26 characters. The push_message must be a maximum of 354 characters. '
			. 'Return ONLY valid JSON with these exact keys: push_title, push_message.';

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $context_text,
			),
		);

		$response = $this->request( $messages, $system_prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_json_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! isset( $parsed['push_title'] ) ) {
			$parsed['push_title'] = '';
		}

		if ( ! isset( $parsed['push_message'] ) ) {
			$parsed['push_message'] = '';
		}

		// Enforce length limits.
		$parsed['push_title']   = mb_substr( $parsed['push_title'], 0, 26 );
		$parsed['push_message'] = mb_substr( $parsed['push_message'], 0, 354 );

		return $parsed;
	}

	/**
	 * Regenerate social content from context.
	 *
	 * @param string $context_text Context text.
	 * @param array  $platforms    Social platforms.
	 * @return array|WP_Error
	 */
	public function regenerate_social( $context_text, $platforms = array(), $brand_voice = '', $social_post_mode = 'single' ) {
		$social_post_mode = in_array( $social_post_mode, array( 'single', 'per_platform' ), true ) ? $social_post_mode : 'single';
		$platform_prompts = array();
		$strictest_limit  = null;
		foreach ( $platforms as $platform ) {
			$limit              = Bcsend_Zernio_API::get_platform_char_limit( $platform );
			$strictest_limit    = null === $strictest_limit ? $limit : min( $strictest_limit, $limit );
			$platform_prompts[] = sprintf( '%s (max %d characters)', $platform, $limit );
		}

		$system_prompt  = 'You are a social media content specialist. ';
		$system_prompt .= 'single' === $social_post_mode
			? sprintf( 'Generate one shared cross-platform social post from the provided context, kept under %d characters. ', (int) $strictest_limit )
			: 'Generate compelling social media posts from the provided context, tailored to each platform. ';
		if ( ! empty( $brand_voice ) ) {
			$system_prompt .= sprintf( 'Use the following brand voice: %s ', $brand_voice );
		}
		if ( ! empty( $platform_prompts ) ) {
			$system_prompt .= 'Generate posts only for these platforms: ' . implode( ', ', $platform_prompts ) . '. ';
			$system_prompt .= 'Return a "social" object with exactly those platform keys. ';
			if ( 'single' === $social_post_mode ) {
				$system_prompt .= 'Use the same shared content under each platform key. ';
			}
		}
		$system_prompt .= 'Return ONLY valid JSON with key "social" containing structured objects per platform. ';
		$system_prompt .= 'Each platform entry must include: content, link_mode, link_url, media_suggestions. ';
		$system_prompt .= 'Allowed link_mode values: none, product, homepage, custom, link_in_bio. ';
		$system_prompt .= 'Allowed media_suggestions values: featured_image, product_image, selected_media. ';
		$system_prompt .= 'Use empty arrays or empty strings when you have no suggestion.';

		$response = $this->request(
			array(
				array(
					'role'    => 'user',
					'content' => $context_text,
				),
			),
			$system_prompt
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_json_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! isset( $parsed['social'] ) || ! is_array( $parsed['social'] ) ) {
			$parsed['social'] = array();
		}

		foreach ( $parsed['social'] as $platform => $entry ) {
			if ( is_array( $entry ) ) {
				$entry['content']              = mb_substr(
					isset( $entry['content'] ) ? (string) $entry['content'] : '',
					0,
					Bcsend_Zernio_API::get_platform_char_limit( $platform )
				);
				$parsed['social'][ $platform ] = $entry;
			} else {
				$parsed['social'][ $platform ] = mb_substr( (string) $entry, 0, Bcsend_Zernio_API::get_platform_char_limit( $platform ) );
			}
		}

		return $parsed;
	}
}
