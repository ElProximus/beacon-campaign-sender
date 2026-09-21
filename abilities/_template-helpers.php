<?php
/**
 * Shared helpers for the template abilities (get/create/update-template).
 *
 * Loaded by _loader.php alongside the ability files. Every function here is
 * only called from an ability execute callback, so load order is irrelevant.
 *
 * @package Bcsend_Plugin
 * @since   1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch one template row.
 *
 * @param int $template_id Template ID.
 * @return array|null Row as an associative array, or null when missing.
 */
function bcsend_template_ability_fetch( $template_id ) {
	global $wpdb;

	$template_id = (int) $template_id;
	if ( $template_id <= 0 ) {
		return null;
	}

	$table = $wpdb->prefix . 'bcsend_templates';
	$row   = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, name, html_content, plain_text, thumbnail, created_at FROM {$table} WHERE id = %d", $template_id ),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Find a template by exact name, optionally ignoring one ID (for renames).
 *
 * @param string $name       Template name.
 * @param int    $exclude_id Template ID to ignore.
 * @return int Matching template ID, or 0.
 */
function bcsend_template_ability_find_by_name( $name, $exclude_id = 0 ) {
	global $wpdb;

	$table = $wpdb->prefix . 'bcsend_templates';

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE name = %s AND id <> %d ORDER BY id ASC LIMIT 1",
			$name,
			(int) $exclude_id
		)
	);
}

/**
 * Shape a template row for ability output.
 *
 * @param array $row Template row.
 * @return array
 */
function bcsend_template_ability_format( $row ) {
	$default_id = (int) get_option( 'bcsend_default_template_id', 0 );

	return array(
		'id'           => (int) $row['id'],
		'name'         => (string) $row['name'],
		'html_content' => (string) $row['html_content'],
		'plain_text'   => (string) $row['plain_text'],
		'thumbnail'    => (string) $row['thumbnail'],
		'created_at'   => (string) $row['created_at'],
		'is_default'   => ( (int) $row['id'] === $default_id ),
	);
}

/**
 * Derive a plain-text version of template HTML.
 *
 * Mirrors what an email client would show without HTML: tags stripped,
 * whitespace collapsed, one blank line between blocks.
 *
 * @param string $html Sanitized template HTML.
 * @return string
 */
function bcsend_template_ability_plain_text_from_html( $html ) {
	$text = preg_replace( '#<(style|script)[^>]*>.*?</\1>#is', '', (string) $html );
	$text = preg_replace( '#</(p|div|tr|li|h[1-6]|blockquote|table)>#i', "$0\n\n", $text );
	$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( "/[ \t]+/", ' ', $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );

	return trim( $text );
}

/**
 * Sanitize the writable template fields supplied to an ability.
 *
 * Applies the same rules as the admin save handler so an AI client cannot
 * store anything the Templates screen would refuse.
 *
 * @param array $input Raw ability input.
 * @return array Sanitized fields that were present in $input (name,
 *               html_content, plain_text, thumbnail), keyed by column.
 */
function bcsend_template_ability_sanitize_fields( $input ) {
	$fields = array();

	if ( isset( $input['name'] ) ) {
		$fields['name'] = sanitize_text_field( (string) $input['name'] );
	}

	if ( isset( $input['html_content'] ) ) {
		$fields['html_content'] = bcsend_kses_email( (string) $input['html_content'] );
	}

	if ( isset( $input['plain_text'] ) ) {
		$fields['plain_text'] = sanitize_textarea_field( (string) $input['plain_text'] );
	}

	if ( isset( $input['thumbnail'] ) ) {
		$fields['thumbnail'] = esc_url_raw( (string) $input['thumbnail'] );
	}

	return $fields;
}
