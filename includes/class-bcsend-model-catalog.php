<?php
/**
 * Authoritative model catalog for AI generation.
 *
 * Single source of truth for every model Beacon can call: identity, provider,
 * settings grouping, request shape (thinking behavior, per-task output budgets
 * and effort), refusal-fallback eligibility, and provider-native background
 * support. Settings validation, the settings UI, and both provider clients
 * consume this catalog - model knowledge should never be duplicated elsewhere.
 *
 * Request-shape notes for the Claude 5 family (Sonnet 5 / Opus 5 / Fable 5):
 * thinking is on by default (always-on for Fable) and counts against
 * max_tokens, so these models get larger output budgets and an explicit
 * output_config.effort. The thinking parameter itself is deliberately never
 * sent - omitting it is valid on every model and avoids per-model 400 rules.
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Model_Catalog
 */
class Bcsend_Model_Catalog {

	/**
	 * The full model catalog.
	 *
	 * Group values: recommended | premium | economical | legacy.
	 * Thinking values: none (pre-Claude-5 request shape) | adaptive | always.
	 *
	 * @return array
	 */
	public static function models() {
		return array(
			// -------------------------------------------------- Anthropic --
			'claude-sonnet-5'           => array(
				'label'      => __( 'Claude Sonnet 5 (Fast & Economical)', 'beacon-campaign-sender' ),
				'provider'   => 'anthropic',
				'group'      => 'economical',
				'thinking'   => 'adaptive',
				'effort'     => array(
					'campaign' => 'medium',
					'html'     => 'medium',
					'push'     => 'low',
					'social'   => 'low',
				),
				'max_tokens' => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
			),
			'claude-opus-5'             => array(
				'label'      => __( 'Claude Opus 5 (Recommended)', 'beacon-campaign-sender' ),
				'provider'   => 'anthropic',
				'group'      => 'recommended',
				'thinking'   => 'adaptive',
				'effort'     => array(
					'campaign' => 'medium',
					'html'     => 'medium',
					'push'     => 'low',
					'social'   => 'low',
				),
				'max_tokens' => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
			),
			'claude-fable-5-1'          => array(
				'label'       => __( 'Claude Fable 5.1 (Maximum Capability — premium pricing, slower)', 'beacon-campaign-sender' ),
				'provider'    => 'anthropic',
				'group'       => 'premium',
				'thinking'    => 'always',
				'effort'      => array(
					'campaign' => 'high',
					'html'     => 'high',
					'push'     => 'medium',
					'social'   => 'medium',
				),
				'max_tokens'  => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
				'fallback_to' => 'claude-opus-5',
			),
			'claude-fable-5'            => array(
				'label'       => __( 'Claude Fable 5 (Legacy — premium pricing, slower)', 'beacon-campaign-sender' ),
				'provider'    => 'anthropic',
				'group'       => 'legacy',
				'thinking'    => 'always',
				'effort'      => array(
					'campaign' => 'high',
					'html'     => 'high',
					'push'     => 'medium',
					'social'   => 'medium',
				),
				'max_tokens'  => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
				'fallback_to' => 'claude-opus-5',
			),
			'claude-sonnet-4-6'         => array(
				'label'    => __( 'Claude Sonnet 4.6 (Legacy)', 'beacon-campaign-sender' ),
				'provider' => 'anthropic',
				'group'    => 'legacy',
				'thinking' => 'none',
			),
			'claude-opus-4-8'           => array(
				'label'    => __( 'Claude Opus 4.8 (Legacy)', 'beacon-campaign-sender' ),
				'provider' => 'anthropic',
				'group'    => 'legacy',
				'thinking' => 'none',
			),
			'claude-opus-4-7'           => array(
				'label'    => __( 'Claude Opus 4.7 (Legacy)', 'beacon-campaign-sender' ),
				'provider' => 'anthropic',
				'group'    => 'legacy',
				'thinking' => 'none',
			),
			'claude-opus-4-6'           => array(
				'label'    => __( 'Claude Opus 4.6 (Legacy)', 'beacon-campaign-sender' ),
				'provider' => 'anthropic',
				'group'    => 'legacy',
				'thinking' => 'none',
			),
			'claude-haiku-4-5-20251001' => array(
				'label'    => __( 'Claude Haiku 4.5 (Legacy — fastest)', 'beacon-campaign-sender' ),
				'provider' => 'anthropic',
				'group'    => 'legacy',
				'thinking' => 'none',
			),

			// ---------------------------------------------------- OpenAI --
			'gpt-5.6-terra'             => array(
				'label'      => __( 'GPT-5.6 Terra (Recommended)', 'beacon-campaign-sender' ),
				'provider'   => 'openai',
				'group'      => 'recommended',
				'background' => true,
				'max_tokens' => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
			),
			'gpt-6-astra'               => array(
				'label'      => __( 'GPT-6 Astra (Maximum Capability — premium pricing, slower)', 'beacon-campaign-sender' ),
				'provider'   => 'openai',
				'group'      => 'premium',
				'background' => true,
				// OpenAI recommends at least 25,000 output tokens for Astra;
				// reasoning draws from the same allowance. Short tasks run at
				// low effort so 8,192 stays safe.
				'effort'     => array(
					'campaign' => 'medium',
					'html'     => 'medium',
					'push'     => 'low',
					'social'   => 'low',
				),
				'max_tokens' => array(
					'default' => 25000,
					'push'    => 8192,
					'social'  => 8192,
				),
			),
			'gpt-5.6-sol'               => array(
				'label'      => __( 'GPT-5.6 Sol (Higher Quality)', 'beacon-campaign-sender' ),
				'provider'   => 'openai',
				'group'      => 'premium',
				'background' => true,
				'max_tokens' => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
			),
			'gpt-5.6-luna'              => array(
				'label'      => __( 'GPT-5.6 Luna (Fastest & Cheapest)', 'beacon-campaign-sender' ),
				'provider'   => 'openai',
				'group'      => 'economical',
				'background' => true,
				'max_tokens' => array(
					'default' => 16000,
					'push'    => 8192,
					'social'  => 8192,
				),
			),
			'gpt-5.5'                   => array(
				'label'    => __( 'GPT-5.5 (Previous generation)', 'beacon-campaign-sender' ),
				'provider' => 'openai',
				'group'    => 'legacy',
			),
			'gpt-5.4'                   => array(
				'label'    => __( 'GPT-5.4 (Previous generation)', 'beacon-campaign-sender' ),
				'provider' => 'openai',
				'group'    => 'legacy',
			),
			'gpt-5.2'                   => array(
				'label'    => __( 'GPT-5.2 (Previous generation)', 'beacon-campaign-sender' ),
				'provider' => 'openai',
				'group'    => 'legacy',
			),
			'gpt-5-mini'                => array(
				'label'    => __( 'GPT-5 Mini (Previous generation)', 'beacon-campaign-sender' ),
				'provider' => 'openai',
				'group'    => 'legacy',
			),
		);
	}

	/**
	 * Get one model record.
	 *
	 * @param string $id Model ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$models = self::models();

		return isset( $models[ $id ] ) ? $models[ $id ] : null;
	}

	/**
	 * Models for one provider, preserving catalog order.
	 *
	 * @param string $provider Provider slug.
	 * @return array id => record.
	 */
	public static function for_provider( $provider ) {
		return array_filter(
			self::models(),
			static function ( $model ) use ( $provider ) {
				return $provider === $model['provider'];
			}
		);
	}

	/**
	 * Allowed model IDs for a provider (settings validation).
	 *
	 * @param string $provider Provider slug.
	 * @return array
	 */
	public static function allowed_ids( $provider ) {
		return array_keys( self::for_provider( $provider ) );
	}

	/**
	 * Recommended default model for new installations.
	 *
	 * Existing installations keep their saved selection - this only seeds
	 * fresh settings.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function default_model( $provider ) {
		return 'openai' === $provider ? 'gpt-5.6-terra' : 'claude-opus-5';
	}

	/**
	 * Per-task output-token budget for a model.
	 *
	 * Models without catalog budgets (legacy) fall back to the pre-catalog
	 * 4096 so their behavior is unchanged.
	 *
	 * @param string $id   Model ID.
	 * @param string $task Task type (campaign|html|push|social).
	 * @return int
	 */
	public static function max_tokens( $id, $task ) {
		$model = self::get( $id );

		if ( ! $model || empty( $model['max_tokens'] ) ) {
			return 4096;
		}

		if ( isset( $model['max_tokens'][ $task ] ) ) {
			return (int) $model['max_tokens'][ $task ];
		}

		return isset( $model['max_tokens']['default'] ) ? (int) $model['max_tokens']['default'] : 4096;
	}

	/**
	 * Per-task effort level for a model, or empty when unsupported.
	 *
	 * @param string $id   Model ID.
	 * @param string $task Task type.
	 * @return string
	 */
	public static function effort( $id, $task ) {
		$model = self::get( $id );

		if ( ! $model || empty( $model['effort'] ) ) {
			return '';
		}

		return isset( $model['effort'][ $task ] ) ? (string) $model['effort'][ $task ] : '';
	}

	/**
	 * The refusal-fallback target for a model, or empty when none applies.
	 *
	 * @param string $id Model ID.
	 * @return string
	 */
	public static function fallback_model( $id ) {
		$model = self::get( $id );

		return ( $model && ! empty( $model['fallback_to'] ) ) ? (string) $model['fallback_to'] : '';
	}

	/**
	 * Whether a model supports provider-native background execution.
	 *
	 * @param string $id Model ID.
	 * @return bool
	 */
	public static function supports_background( $id ) {
		$model = self::get( $id );

		return (bool) ( $model && ! empty( $model['background'] ) );
	}

	/**
	 * Group models for a settings dropdown.
	 *
	 * @param string $provider Provider slug.
	 * @return array group => array( id => label ).
	 */
	public static function grouped_options( $provider ) {
		$groups = array(
			'recommended' => array(),
			'premium'     => array(),
			'economical'  => array(),
			'legacy'      => array(),
		);

		foreach ( self::for_provider( $provider ) as $id => $model ) {
			$group = isset( $model['group'] ) ? $model['group'] : 'legacy';
			if ( ! isset( $groups[ $group ] ) ) {
				$group = 'legacy';
			}
			$groups[ $group ][ $id ] = $model['label'];
		}

		return array_filter( $groups );
	}

	/**
	 * Human label for a dropdown group.
	 *
	 * @param string $group Group slug.
	 * @return string
	 */
	public static function group_label( $group ) {
		$labels = array(
			'recommended' => __( 'Recommended', 'beacon-campaign-sender' ),
			'premium'     => __( 'Higher quality (higher cost)', 'beacon-campaign-sender' ),
			'economical'  => __( 'Fast & economical', 'beacon-campaign-sender' ),
			'legacy'      => __( 'Legacy', 'beacon-campaign-sender' ),
		);

		return isset( $labels[ $group ] ) ? $labels[ $group ] : $group;
	}
}
