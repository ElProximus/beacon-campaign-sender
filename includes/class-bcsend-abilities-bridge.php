<?php
/**
 * Abilities Bridge connector for Beacon Campaign Sender.
 *
 * Provides tool definitions so Abilities Bridge can discover
 * and manage Beacon Campaign Sender abilities from its Integrations page.
 *
 * @package Bcsend_Plugin
 * @since   2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Abilities_Bridge
 *
 * Static connector that exposes Beacon Campaign Sender ability metadata
 * for discovery by Abilities Bridge.
 *
 * @since 2.1.3
 */
class Bcsend_Abilities_Bridge {

	/**
	 * Get the canonical Beacon Campaign Sender ability manifest.
	 *
	 * This acts as the single source of truth for Beacon Campaign Sender ability
	 * metadata so the plugin settings screen and Abilities Bridge
	 * integration cannot drift apart.
	 *
	 * @return array Array of ability metadata keyed by ability name.
	 */
	public static function get_ability_manifest() {
		return array(
			'beacon-campaign-sender/sync-social-accounts' => array(
				'label'                 => __( 'Sync Social Accounts', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Sync Social Accounts', 'beacon-campaign-sender' ),
				'connector_description' => 'Sync connected social accounts from Zernio for use in Beacon Campaign Sender composer.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 100,
					'max_per_hour' => 20,
				),
			),
			'beacon-campaign-sender/create-social-post'   => array(
				'label'                 => __( 'Create Social Post', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Create Social Post', 'beacon-campaign-sender' ),
				'connector_description' => 'Create and send social posts via Zernio.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 10,
				),
			),
			'beacon-campaign-sender/list-social-posts'    => array(
				'label'                 => __( 'List Social Posts', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Social Posts', 'beacon-campaign-sender' ),
				'connector_description' => 'List tracked social posts and their statuses.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/get-social-post'      => array(
				'label'                 => __( 'Get Social Post', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Get Social Post', 'beacon-campaign-sender' ),
				'connector_description' => 'Retrieve a single social post row by ID.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/create-campaign'      => array(
				'label'                 => __( 'Create Campaign', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Create Campaign', 'beacon-campaign-sender' ),
				'connector_description' => 'Create an AI-generated email and push notification campaign in one step.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 10,
				),
			),
			'beacon-campaign-sender/create-contact'       => array(
				'label'                 => __( 'Create Contact', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Create Contact', 'beacon-campaign-sender' ),
				'connector_description' => 'Create or update a Brevo contact with optional name attributes and list memberships for explicit opt-in imports.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 100,
					'max_per_hour' => 20,
				),
			),
			'beacon-campaign-sender/bulk-create-contacts' => array(
				'label'                 => __( 'Bulk Create Contacts', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Bulk Create Contacts', 'beacon-campaign-sender' ),
				'connector_description' => 'Bulk-create or update up to 100 Brevo contacts through the Beacon Campaign Sender subscriber ingest pipeline with per-contact consent logging and retry behavior.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 10,
				),
			),
			'beacon-campaign-sender/add-contacts-to-list' => array(
				'label'                 => __( 'Add Contacts to List', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Add Contacts to List', 'beacon-campaign-sender' ),
				'connector_description' => 'Bulk-add existing Brevo contacts to a specific list in one call, without creating contacts or changing attributes.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 100,
					'max_per_hour' => 20,
				),
			),
			'beacon-campaign-sender/list-contacts'        => array(
				'label'                 => __( 'List Contacts', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Contacts', 'beacon-campaign-sender' ),
				'connector_description' => 'List Brevo contacts with pagination and optional list, blacklist, date, and free-text filters.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/update-contact'       => array(
				'label'                 => __( 'Update Contact', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Update Contact', 'beacon-campaign-sender' ),
				'connector_description' => 'Update an existing Brevo contact attributes, list memberships, blacklist flags, or primary email address.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 100,
					'max_per_hour' => 20,
				),
			),
			'beacon-campaign-sender/generate-campaign-content' => array(
				'label'                 => __( 'Generate Campaign Content', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Generate Campaign Content', 'beacon-campaign-sender' ),
				'connector_description' => 'Generate email and push content using AI without saving. For review before committing.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 200,
					'max_per_hour' => 50,
				),
			),
			'beacon-campaign-sender/save-draft'           => array(
				'label'                 => __( 'Save Draft', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Save Draft', 'beacon-campaign-sender' ),
				'connector_description' => 'Create or update a campaign draft.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 200,
					'max_per_hour' => 50,
				),
			),
			'beacon-campaign-sender/schedule-campaign'    => array(
				'label'                 => __( 'Schedule Campaign', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Schedule Campaign', 'beacon-campaign-sender' ),
				'connector_description' => 'Schedule a draft or approved campaign for delivery at a specific time.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 10,
				),
			),
			'beacon-campaign-sender/delete-campaign'      => array(
				'label'                 => __( 'Delete Campaign', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Delete Campaign', 'beacon-campaign-sender' ),
				'connector_description' => 'Delete a campaign. Cannot delete campaigns that are currently sending.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 20,
				),
			),
			'beacon-campaign-sender/get-campaign'         => array(
				'label'                 => __( 'Get Campaign', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Get Campaign', 'beacon-campaign-sender' ),
				'connector_description' => 'Retrieve full details of a specific campaign including segment info.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 1000,
					'max_per_hour' => 200,
				),
			),
			'beacon-campaign-sender/list-campaigns'       => array(
				'label'                 => __( 'List Campaigns', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Campaigns', 'beacon-campaign-sender' ),
				'connector_description' => 'List campaigns with optional status filter and Brevo statistics.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 1000,
					'max_per_hour' => 200,
				),
			),
			'beacon-campaign-sender/get-campaign-stats'   => array(
				'label'                 => __( 'Get Campaign Stats', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Get Campaign Stats', 'beacon-campaign-sender' ),
				'connector_description' => 'Get detailed email and push notification metrics for a campaign.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 1000,
					'max_per_hour' => 200,
				),
			),
			'beacon-campaign-sender/get-analytics'        => array(
				'label'                 => __( 'Get Analytics', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Get Analytics', 'beacon-campaign-sender' ),
				'connector_description' => 'Get aggregate campaign analytics over a configurable time range.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/get-dashboard'        => array(
				'label'                 => __( 'Get Dashboard', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Get Dashboard', 'beacon-campaign-sender' ),
				'connector_description' => 'Get dashboard summary: campaign, segment, and template counts plus recent items.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/get-contact-status'   => array(
				'label'                 => __( 'Get Contact Status', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Get Contact Status', 'beacon-campaign-sender' ),
				'connector_description' => 'Look up a single Brevo contact by email and classify whether it is blocklisted, unsubscribed, bounced, complained, or simply not found.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/list-segments'        => array(
				'label'                 => __( 'List Segments', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Segments', 'beacon-campaign-sender' ),
				'connector_description' => 'List all audience segments with contact counts and sync status.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/create-segment'       => array(
				'label'                 => __( 'Create Segment', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Create Segment', 'beacon-campaign-sender' ),
				'connector_description' => 'Create a new audience segment for targeting campaigns.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 20,
				),
			),
			'beacon-campaign-sender/sync-segments'        => array(
				'label'                 => __( 'Sync Segments', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Sync Segments', 'beacon-campaign-sender' ),
				'connector_description' => 'Force sync all smart segments to Brevo and update contact counts.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 10,
				),
			),
			'beacon-campaign-sender/list-products'        => array(
				'label'                 => __( 'List Products', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Products', 'beacon-campaign-sender' ),
				'connector_description' => 'List WooCommerce products for campaign targeting.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/list-templates'       => array(
				'label'                 => __( 'List Templates', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Templates', 'beacon-campaign-sender' ),
				'connector_description' => 'List saved email templates with preview snippets.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 500,
					'max_per_hour' => 100,
				),
			),
			'beacon-campaign-sender/list-brevo-lists'     => array(
				'label'                 => __( 'List Brevo Lists', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'List Brevo Lists', 'beacon-campaign-sender' ),
				'connector_description' => 'Fetch contact lists from the connected Brevo account. Use the list ID with create-segment to import as an audience.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 200,
					'max_per_hour' => 50,
				),
			),
			'beacon-campaign-sender/send-test-email'      => array(
				'label'                 => __( 'Send Test Email', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Send Test Email', 'beacon-campaign-sender' ),
				'connector_description' => 'Send a test email to a single address via Brevo. Can send a campaign draft or custom content.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 50,
					'max_per_hour' => 10,
				),
			),
			'beacon-campaign-sender/send-push-notification' => array(
				'label'                 => __( 'Send Push Notification', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Send Push Notification', 'beacon-campaign-sender' ),
				'connector_description' => 'Send a push notification immediately to a target segment.',
				'risk_level'            => 'medium',
				'permissions'           => array(
					'max_per_day'  => 20,
					'max_per_hour' => 5,
				),
			),
			'beacon-campaign-sender/update-brand-voice'   => array(
				'label'                 => __( 'Update Brand Voice', 'beacon-campaign-sender' ),
				'settings_label'        => __( 'Update Brand Voice', 'beacon-campaign-sender' ),
				'connector_description' => 'Update the brand voice description used for AI content generation.',
				'risk_level'            => 'low',
				'permissions'           => array(
					'max_per_day'  => 20,
					'max_per_hour' => 10,
				),
			),
		);
	}

	/**
	 * Get all Beacon Campaign Sender tool definitions for Abilities Bridge discovery.
	 *
	 * Each tool includes name, description, risk_level, and the recommended
	 * permission settings for one-click approval.
	 *
	 * @return array Array of tool definition arrays.
	 */
	public static function get_tool_definitions() {
		$tool_definitions = array();

		foreach ( self::get_ability_manifest() as $ability_name => $ability ) {
			$tool_definitions[] = array(
				'name'        => $ability_name,
				'description' => $ability['connector_description'],
				'risk_level'  => $ability['risk_level'],
				'permissions' => $ability['permissions'],
			);
		}

		return $tool_definitions;
	}
}

/**
 * Register Beacon Campaign Sender as a plugin integration with the Abilities Bridge plugin.
 *
 * Hooks the third-party `abilities_bridge_plugin_integrations` filter so the bridge's
 * Integrations admin page can list Beacon Campaign Sender and bulk-approve all of its
 * abilities in a single click. The card is always registered for discoverability;
 * ability definitions are populated only when the provider consent flag is enabled.
 *
 * @param array $integrations Existing integrations array.
 * @return array Integrations array with Beacon Campaign Sender added.
 */
function bcsend_register_with_abilities_bridge( $integrations ) {
	if ( ! is_array( $integrations ) ) {
		$integrations = array();
	}

	$settings = get_option( 'bcsend_settings', array() );
	$enabled  = ! empty( $settings['abilities_bridge_enabled'] );

	$abilities = array();
	if ( $enabled ) {
		foreach ( Bcsend_Abilities_Bridge::get_tool_definitions() as $tool ) {
			$abilities[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'risk_level'  => isset( $tool['risk_level'] ) ? $tool['risk_level'] : 'low',
				'permissions' => isset( $tool['permissions'] ) ? $tool['permissions'] : array(),
			);
		}
	}

	$integrations['beacon-campaign-sender'] = array(
		'plugin_slug'         => 'beacon-campaign-sender',
		'plugin_name'         => 'Beacon Campaign Sender',
		'plugin_description'  => __( 'Email, push notification, and social campaign management with Brevo delivery and optional AI-assisted content generation.', 'beacon-campaign-sender' ),
		'plugin_version'      => defined( 'BCSEND_VERSION' ) ? BCSEND_VERSION : 'Unknown',
		'plugin_active'       => true,
		'integration_enabled' => $enabled,
		'settings_admin_page' => 'bcsend-settings',
		'abilities'           => $abilities,
		'icon'                => 'dashicons-megaphone',
		'approval_profiles'   => array(
			'all' => array(
				'label'       => __( 'Approve Beacon Campaign Sender', 'beacon-campaign-sender' ),
				'description' => __( 'Authorize all Beacon Campaign Sender abilities for the connected MCP client.', 'beacon-campaign-sender' ),
			),
		),
	);

	return $integrations;
}
add_filter( 'abilities_bridge_plugin_integrations', 'bcsend_register_with_abilities_bridge' );
