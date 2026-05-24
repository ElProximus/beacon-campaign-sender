<?php
/**
 * Abilities loader for Beacon Campaign Sender.
 *
 * Registers the beacon-campaign-sender ability category and includes all ability
 * files from this directory. Each ability file hooks wp_abilities_api_init
 * internally and calls wp_register_ability() with the correct schema.
 *
 * @package Bcsend_Plugin
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'beacon-campaign-sender',
			array(
				'label'       => __( 'Beacon Campaign Sender', 'beacon-campaign-sender' ),
				'description' => __( 'Email and push notification campaign tools.', 'beacon-campaign-sender' ),
			)
		);
	}
);

// Include all ability files (each hooks wp_abilities_api_init internally).
$bcsend_abilities_dir = BCSEND_PLUGIN_DIR . 'abilities/';

foreach ( glob( $bcsend_abilities_dir . '*.php' ) as $bcsend_ability_file ) {
	if ( '_loader.php' === basename( $bcsend_ability_file ) ) {
		continue;
	}
	require_once $bcsend_ability_file;
}
