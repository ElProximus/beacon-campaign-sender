<?php
/**
 * OpenAI API integration for Beacon Campaign Sender.
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

class Bcsend_OpenAI_API {

	const API_URL           = 'https://api.openai.com/v1/responses';
	const MAX_RETRIES       = 3;
	const MAX_OUTPUT_TOKENS = 4096;

	private $api_key;
	private $model;

	public function __construct() {
		$settings = get_option( 'bcsend_settings', array() );

		$encrypted_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		if ( ! empty( $encrypted_key ) ) {
			$this->api_key = Bcsend_Encryption::decrypt( $encrypted_key );
		} else {
			$this->api_key = '';
		}

		$allowed_models = Bcsend_Model_Catalog::allowed_ids( 'openai' );
		$this->model    = isset( $settings['openai_model'] ) && in_array( $settings['openai_model'], $allowed_models, true )
			? $settings['openai_model']
			: Bcsend_Model_Catalog::default_model( 'openai' );
	}

	public function is_configured() {
		return ! empty( $this->api_key );
	}

	public function test_connection() {
		$response = $this->request( 'Reply with OK.', 'Reply with exactly OK.' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'message' => 'Connected successfully.',
			'model'   => $this->model,
		);
	}

	private function request( $input, $instructions = '', $max_tokens = self::MAX_OUTPUT_TOKENS ) {
		// Models with catalog budgets (the GPT-5.6 family) use the per-task
		// output budget - reasoning tokens draw from max_output_tokens, so
		// the small legacy caps (512 push / 1024 social) would truncate them.
		// Legacy models keep the caller-provided cap unchanged.
		$catalog_entry = Bcsend_Model_Catalog::get( $this->model );
		if ( $catalog_entry && ! empty( $catalog_entry['max_tokens'] ) ) {
			$max_tokens = Bcsend_Model_Catalog::max_tokens( $this->model, Bcsend_AI_Service::get_task_context() );
		}

		$body = array(
			'model'             => $this->model,
			'input'             => $input,
			'max_output_tokens' => $max_tokens,
		);

		if ( ! empty( $instructions ) ) {
			$body['instructions'] = $instructions;
		}

		Bcsend_AI_Service::record_generation_meta(
			array(
				'effective_model' => $this->model,
				'fallback_used'   => false,
			)
		);

		/**
		 * Whether to run this request in OpenAI's provider-native background
		 * mode (submit, then poll the response ID in short requests). Enabled
		 * by the background job runner - the provider then owns the
		 * long-running execution instead of a fragile long HTTP request.
		 *
		 * @param bool $background Default false (interactive requests block).
		 */
		$use_background = Bcsend_Model_Catalog::supports_background( $this->model )
			&& apply_filters( 'bcsend_openai_background', false );

		if ( $use_background ) {
			$body['background'] = true;
		}

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'content-type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			/** This filter is documented in includes/class-bcsend-anthropic-api.php */
			'timeout' => (int) apply_filters( 'bcsend_ai_request_timeout', 120 ),
		);

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_RETRIES ) {
			++$attempt;

			if ( $attempt > 1 ) {
				sleep( pow( 2, $attempt ) );
			}

			$response = wp_remote_post( self::API_URL, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				Bcsend_Logger::log(
					'generation',
					'OpenAI API connection error',
					wp_json_encode(
						array(
							'service' => 'openai',
							'model'   => $this->model,
							'attempt' => $attempt,
							'error'   => $response->get_error_message(),
						)
					),
					'error'
				);

				// Ambiguous transport timeouts are never retried - the request
				// may still complete and be billed at the provider.
				if ( Bcsend_AI_Service::is_ambiguous_transport_error( $response ) ) {
					return new WP_Error(
						'ai_timeout_ambiguous',
						__( 'The AI request timed out. It may still be processing (and billed) at the provider, so it was not retried automatically.', 'beacon-campaign-sender' )
					);
				}

				continue;
			}

			$code         = wp_remote_retrieve_response_code( $response );
			$decoded_body = json_decode( wp_remote_retrieve_body( $response ), true );

			Bcsend_Logger::log(
				'generation',
				'OpenAI API request sent',
				wp_json_encode(
					array(
						'service'     => 'openai',
						'model'       => $this->model,
						'attempt'     => $attempt,
						'status_code' => $code,
					)
				)
			);

			if ( 200 === $code ) {
				$status = isset( $decoded_body['status'] ) ? $decoded_body['status'] : '';

				// Background submission accepted: the provider now owns the
				// long-running execution; poll its status in short requests.
				if ( $use_background && ! empty( $decoded_body['id'] ) && in_array( $status, array( 'queued', 'in_progress' ), true ) ) {
					Bcsend_AI_Service::record_generation_meta( array( 'provider_response_id' => $decoded_body['id'] ) );

					// Persist the ID on the job row NOW - if hosting kills
					// this worker mid-poll, the pointer to the (billed)
					// OpenAI result must survive.
					do_action( 'bcsend_ai_provider_response_submitted', $decoded_body['id'] );

					return $this->poll_background_response( $decoded_body['id'] );
				}

				$text = $this->extract_output_text( $decoded_body );

				if ( '' !== $text ) {
					return $text;
				}

				return new WP_Error( 'openai_api_error', 'Unexpected response format from OpenAI API.', array( 'response' => $decoded_body ) );
			}

			// Timeout-family responses are ambiguous: an intermediary may
			// have stopped waiting after OpenAI accepted the generation.
			if ( in_array( $code, array( 408, 504, 524 ), true ) ) {
				return new WP_Error(
					'ai_timeout_ambiguous',
					__( 'The AI request timed out at a gateway. It may still be processing (and billed) at the provider, so it was not retried automatically.', 'beacon-campaign-sender' ),
					array( 'status_code' => $code )
				);
			}

			// A provider or gateway 5xx can arrive after generation was
			// accepted. Without provider idempotency, another POST is unsafe.
			if ( 0 === $code || $code >= 500 ) {
				return new WP_Error(
					'ai_generation_ambiguous',
					__( 'OpenAI returned a server error after the generation request was sent. It may still have completed and been billed, so it was not submitted again.', 'beacon-campaign-sender' ),
					array( 'status_code' => $code )
				);
			}

			$error_message = isset( $decoded_body['error']['message'] ) ? $decoded_body['error']['message'] : 'OpenAI API error';
			$api_error     = new WP_Error(
				'openai_api_error',
				sprintf( '%s (HTTP %d)', $error_message, $code ),
				array(
					'status_code' => $code,
					'response'    => $decoded_body,
				)
			);

			// HTTP 429 is an explicit rejection, so a delayed retry cannot
			// duplicate provider work. Other HTTP responses are returned now.
			if ( 429 !== $code ) {
				return $api_error;
			}
			$last_error = $api_error;
		}

		if ( ! is_wp_error( $last_error ) ) {
			$last_error = new WP_Error( 'openai_api_error', 'OpenAI API request failed after maximum retries.' );
		}

		return $last_error;
	}

	/**
	 * Fetch a previously-submitted background response by ID.
	 *
	 * Used to recover a job whose worker died after OpenAI accepted the
	 * request: the generation may have completed (and been billed) on
	 * OpenAI's side, and a plain GET carries no duplicate-generation or
	 * billing risk.
	 *
	 * @param string $response_id OpenAI response ID.
	 * @return array {status, text} - status is one of completed|pending|failed.
	 */
	public function fetch_background_response( $response_id ) {
		if ( empty( $this->api_key ) || '' === (string) $response_id ) {
			return array(
				'status' => 'failed',
				'text'   => '',
			);
		}

		$response = wp_remote_get(
			self::API_URL . '/' . rawurlencode( $response_id ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Transport problem or transient provider error: unknown, not failed.
			return array(
				'status' => 'pending',
				'text'   => '',
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$status  = isset( $decoded['status'] ) ? $decoded['status'] : '';

		if ( in_array( $status, array( 'queued', 'in_progress' ), true ) ) {
			return array(
				'status' => 'pending',
				'text'   => '',
			);
		}

		if ( 'completed' === $status ) {
			return array(
				'status' => 'completed',
				'text'   => $this->extract_output_text( $decoded ),
			);
		}

		return array(
			'status' => 'failed',
			'text'   => '',
		);
	}

	/**
	 * Poll a background response until it reaches a terminal state.
	 *
	 * Each poll is a short GET, so no single HTTP request has to outlive a
	 * slow generation - host and proxy timeouts stop mattering. The overall
	 * budget is the same filtered timeout the blocking path uses, keeping the
	 * job lease valid. Transient poll failures are retried (polling a GET is
	 * always billing-safe); budget exhaustion surfaces as the ambiguous
	 * timeout so the job lands in the uncertain state with the provider
	 * response ID recorded for follow-up.
	 *
	 * @param string $response_id OpenAI response ID.
	 * @return string|WP_Error
	 */
	private function poll_background_response( $response_id ) {
		/** This filter is documented in includes/class-bcsend-anthropic-api.php */
		$deadline = time() + (int) apply_filters( 'bcsend_ai_request_timeout', 120 );

		while ( time() < $deadline ) {
			sleep( 5 );

			$response = wp_remote_get(
				self::API_URL . '/' . rawurlencode( $response_id ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				// A failed status poll is always safe to retry.
				continue;
			}

			$poll_code = (int) wp_remote_retrieve_response_code( $response );

			// Rate limits and server errors on a status GET are transient and
			// billing-safe to retry - only a 200 carries a real status.
			if ( 200 !== $poll_code ) {
				continue;
			}

			$decoded_body = json_decode( wp_remote_retrieve_body( $response ), true );
			$status       = isset( $decoded_body['status'] ) ? $decoded_body['status'] : '';

			if ( in_array( $status, array( 'queued', 'in_progress' ), true ) ) {
				// The provider confirmed the job is alive - keep our own
				// job lease alive to match.
				do_action( 'bcsend_ai_lease_extend' );
				continue;
			}

			if ( 'completed' === $status ) {
				$text = $this->extract_output_text( $decoded_body );

				if ( '' !== $text ) {
					return $text;
				}

				return new WP_Error( 'openai_api_error', 'Background response completed without output text.', array( 'response' => $decoded_body ) );
			}

			$error_message = isset( $decoded_body['error']['message'] ) ? $decoded_body['error']['message'] : sprintf( 'Background response ended with status "%s".', $status );

			return new WP_Error( 'openai_api_error', $error_message, array( 'response' => $decoded_body ) );
		}

		return new WP_Error(
			'ai_timeout_ambiguous',
			__( 'The AI request is still running at OpenAI after the polling window closed. It may complete (and be billed) at the provider; it was not retried automatically.', 'beacon-campaign-sender' )
		);
	}

	private function extract_output_text( $decoded_body ) {
		if ( isset( $decoded_body['output_text'] ) && is_string( $decoded_body['output_text'] ) ) {
			return $decoded_body['output_text'];
		}

		if ( empty( $decoded_body['output'] ) || ! is_array( $decoded_body['output'] ) ) {
			return '';
		}

		$text = '';

		foreach ( $decoded_body['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $content_item ) {
				if ( isset( $content_item['text'] ) && is_string( $content_item['text'] ) ) {
					$text .= $content_item['text'];
				}
			}
		}

		return $text;
	}

	private function parse_json_response( $content ) {
		$json_string = trim( $content );

		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $json_string, $matches ) ) {
			$json_string = trim( $matches[1] );
		}

		$decoded = json_decode( $json_string, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'openai_parse_error', sprintf( 'Failed to parse JSON from OpenAI response: %s', json_last_error_msg() ), array( 'raw_content' => $content ) );
		}

		return $decoded;
	}

	public function generate_campaign( $product_data = null, $prompt = '', $brand_voice = '', $base_template = '', $channels = array( 'email', 'push' ), $social_platforms = array(), $social_post_mode = 'single' ) {
		$has_email  = in_array( 'email', $channels, true );
		$has_social = in_array( 'social', $channels, true ) && ! empty( $social_platforms );

		// Adapt persona to active channels.
		if ( $has_email && $has_social ) {
			$instruction_parts = array( 'You are an email and social media marketing content specialist.' );
		} elseif ( $has_social ) {
			$instruction_parts = array( 'You are a social media marketing content specialist.' );
		} else {
			$instruction_parts = array( 'You are an email marketing content specialist.' );
		}

		if ( ! empty( $brand_voice ) ) {
			$instruction_parts[] = sprintf( 'Use the following brand voice: %s', $brand_voice );
		}

		if ( $has_email ) {
			if ( ! empty( $base_template ) ) {
				$is_existing = ( false !== strpos( $base_template, '</html>' ) || false !== strpos( $base_template, '</table>' ) || mb_strlen( $base_template ) > 200 );

				if ( $is_existing ) {
					$instruction_parts[] = 'The user has an existing email campaign. Here is the current HTML. Apply the user\'s requested changes to this HTML — do not start from scratch. Preserve the existing design and style but ADD any new content the user provides (products, images, posts/articles).';
					$instruction_parts[] = sprintf( 'Current email HTML: %s', $base_template );
				} else {
					$instruction_parts[] = sprintf( 'Use the following base HTML template as a starting point for the html_content: %s', $base_template );
				}
			}

			$instruction_parts[] = 'If product information includes an Image URL, include it as an <img> tag in the html_content.';
			$instruction_parts[] = 'If product information includes a Product URL, link the product name and image to it in the html_content.';
			$instruction_parts[] = 'IMPORTANT: If the user provides image URLs to include, you MUST add every one as an <img> tag in the html_content with proper styling. Never omit provided images.';
			$instruction_parts[] = 'IMPORTANT: If the user provides posts/pages to include, you MUST add a section for each one in the html_content with its title as a linked heading, the featured image (if provided) as an <img> tag, the excerpt text, and a Read More link. Never omit provided posts.';
		}
		$required_keys = array();
		if ( $has_email ) {
			$required_keys = array_merge( $required_keys, array( 'name', 'subject', 'preview_text', 'html_content', 'plain_text' ) );
		}
		if ( in_array( 'push', $channels, true ) ) {
			$required_keys = array_merge( $required_keys, array( 'push_title', 'push_message' ) );
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
				$instruction_parts[] = sprintf( 'Also generate ONE shared cross-platform social post in a "social" object. Optimize it for all requested platforms and keep the content under %d characters.', (int) $strictest_limit );
				$instruction_parts[] = 'Return the same shared social post content under each requested platform key so the app can preserve account selection while publishing one Zernio post.';
			} else {
				$instruction_parts[] = 'Also generate tailored social posts for each requested platform in a "social" object.';
			}
			$instruction_parts[] = 'Generate social posts only for these platforms: ' . implode( ', ', $platform_prompts ) . '.';
			$instruction_parts[] = 'Return a "social" object with exactly those platform keys.';
			$instruction_parts[] = 'Each social platform entry must be an object with keys: content, link_mode, link_url, media_suggestions.';
			$instruction_parts[] = 'Use link_mode values only from: none, product, homepage, custom, link_in_bio.';
			$instruction_parts[] = 'Use media_suggestions as an array containing zero or more of: featured_image, product_image, selected_media.';
			$required_keys[]     = 'social { ' . implode( ', ', $platform_prompts ) . ' as structured objects }';
		}
		$instruction_parts[] = 'Return ONLY valid JSON with these exact keys: ' . implode( ', ', $required_keys ) . '. Keep push_title at 26 characters or less and push_message at 354 characters or less.';

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

		$response = $this->request( implode( "\n\n", $user_parts ), implode( ' ', $instruction_parts ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_json_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$base_required = array( 'name', 'subject', 'preview_text', 'html_content', 'plain_text', 'push_title', 'push_message' );
		foreach ( $base_required as $key ) {
			if ( ! isset( $parsed[ $key ] ) ) {
				$parsed[ $key ] = '';
			}
		}

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

	public function regenerate_html( $plain_text, $brand_voice = '', $base_template = '' ) {
		$instruction_parts = array( 'You are an email marketing HTML specialist. Convert the provided plain text into a beautifully formatted HTML email.' );

		if ( ! empty( $brand_voice ) ) {
			$instruction_parts[] = sprintf( 'Use the following brand voice: %s', $brand_voice );
		}

		if ( ! empty( $base_template ) ) {
			$instruction_parts[] = sprintf( 'Use the following base HTML template as a starting point: %s', $base_template );
		}

		$instruction_parts[] = 'Return ONLY valid JSON with this exact key: html_content.';

		$response = $this->request( $plain_text, implode( ' ', $instruction_parts ) );

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

	public function regenerate_push( $context_text ) {
		$instructions = 'You are a push notification specialist. Generate a compelling push notification from the provided context. '
			. 'The push_title must be a maximum of 26 characters. The push_message must be a maximum of 354 characters. '
			. 'Return ONLY valid JSON with these exact keys: push_title, push_message.';

		$response = $this->request( $context_text, $instructions, 512 );

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

		$instructions  = 'You are a social media content specialist. ';
		$instructions .= 'single' === $social_post_mode
			? sprintf( 'Generate one shared cross-platform social post from the provided context, kept under %d characters. ', (int) $strictest_limit )
			: 'Generate compelling platform-tailored social posts from the provided context. ';
		if ( ! empty( $brand_voice ) ) {
			$instructions .= sprintf( 'Use the following brand voice: %s ', $brand_voice );
		}
		if ( ! empty( $platform_prompts ) ) {
			$instructions .= 'Generate posts only for these platforms: ' . implode( ', ', $platform_prompts ) . '. ';
			$instructions .= 'Return a "social" object with exactly those platform keys. ';
			if ( 'single' === $social_post_mode ) {
				$instructions .= 'Use the same shared content under each platform key. ';
			}
		}
		$instructions .= 'Return ONLY valid JSON with key "social" containing structured objects per platform. ';
		$instructions .= 'Each platform entry must include: content, link_mode, link_url, media_suggestions. ';
		$instructions .= 'Allowed link_mode values: none, product, homepage, custom, link_in_bio. ';
		$instructions .= 'Allowed media_suggestions values: featured_image, product_image, selected_media. ';
		$instructions .= 'Use empty arrays or empty strings when you have no suggestion.';

		$response = $this->request( $context_text, $instructions, 1024 );

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
