<?php
/**
 * Ability: beacon-campaign-sender/list-campaigns
 *
 * List campaigns with optional status filter. Includes Brevo
 * open/click rates for sent campaigns.
 *
 * @package Bcsend_Plugin
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_init',
	function () {
		$settings = get_option( 'bcsend_settings', array() );
		if ( empty( $settings['abilities_bridge_enabled'] ) ) {
			return;
		}

		wp_register_ability(
			'beacon-campaign-sender/list-campaigns',
			array(
				'label'               => __( 'List Campaigns', 'beacon-campaign-sender' ),
				'description'         => 'List Beacon Campaign Sender campaigns with optional status filter. Includes Brevo open/click rates for sent campaigns.',
				'category'            => 'beacon-campaign-sender',

				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Filter by campaign status.',
							'enum'        => array( 'draft', 'approved', 'scheduled', 'sending', 'sent', 'failed', 'cancelled' ),
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Maximum campaigns to return. Default 20, max 100.',
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
					'additionalProperties' => false,
				),

				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array(
								'type'        => 'integer',
								'description' => 'Campaign ID.',
							),
							'name'         => array(
								'type'        => 'string',
								'description' => 'Campaign name.',
							),
							'status'       => array(
								'type'        => 'string',
								'description' => 'Campaign status.',
							),
							'scheduled_at' => array(
								'type'        => array( 'string', 'null' ),
								'description' => 'Scheduled send time.',
							),
							'sent_at'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => 'Actual send time.',
							),
							'open_rate'    => array(
								'type'        => array( 'number', 'null' ),
								'description' => 'Unique open rate from Brevo.',
							),
							'click_rate'   => array(
								'type'        => array( 'number', 'null' ),
								'description' => 'Unique click rate from Brevo.',
							),
						),
					),
				),

				'execute_callback'    => 'bcsend_ability_list_campaigns',
				'permission_callback' => function () {
					return current_user_can( 'edit_bcsend_campaigns' );
				},

				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'ai_enabled'  => true,
				),
			)
		);
	}
);

/**
 * List campaigns with optional status filter.
 *
 * @param array $input {
 *     @type string $status Optional status filter.
 *     @type int    $limit  Max results (default 20, max 100).
 * }
 * @return array Array of campaign summary objects.
 */
function bcsend_ability_list_campaigns( $input = array() ) {
	global $wpdb;

	$table  = $wpdb->prefix . 'bcsend_campaigns';
	$status = isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : '';
	$limit  = isset( $input['limit'] ) ? min( (int) $input['limit'], 100 ) : 20;
	$limit  = max( 1, $limit );

	if ( ! empty( $status ) ) {
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, status, scheduled_at, sent_at, brevo_campaign_id
				FROM {$table}
				WHERE status = %s
				ORDER BY created_at DESC
				LIMIT %d",
				$status,
				$limit
			)
		);
	} else {
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, status, scheduled_at, sent_at, brevo_campaign_id
				FROM {$table}
				ORDER BY created_at DESC
				LIMIT %d",
				$limit
			)
		);
	}

	if ( empty( $campaigns ) ) {
		return array();
	}

	$brevo  = new Bcsend_Brevo_API();
	$result = array();

	foreach ( $campaigns as $campaign ) {
		$entry = array(
			'id'           => (int) $campaign->id,
			'name'         => $campaign->name,
			'status'       => $campaign->status,
			'scheduled_at' => $campaign->scheduled_at,
			'sent_at'      => $campaign->sent_at,
			'open_rate'    => null,
			'click_rate'   => null,
		);

		if ( 'sent' === $campaign->status && ! empty( $campaign->brevo_campaign_id ) && $brevo->is_configured() ) {
			$stats = $brevo->get_campaign_stats( (int) $campaign->brevo_campaign_id );

			if ( ! is_wp_error( $stats ) && is_array( $stats ) ) {
				$extracted = Bcsend_Brevo_API::extract_campaign_stats( $stats );

				$entry['open_rate']  = $extracted['open_rate'];
				$entry['click_rate'] = $extracted['click_rate'];
			}
		}

		$result[] = $entry;
	}

	return $result;
}
