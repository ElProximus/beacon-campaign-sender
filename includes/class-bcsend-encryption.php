<?php
/**
 * Encryption helper for sensitive data.
 *
 * Uses AES-256-CBC encryption with WordPress AUTH_KEY as the encryption key.
 * Follows the same pattern as FD_Encryption in the fulfillment-dashboard plugin.
 *
 * @package Bcsend_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bcsend_Encryption
 */
class Bcsend_Encryption {

	/**
	 * Encryption method.
	 */
	private const METHOD = 'aes-256-cbc';

	/**
	 * Prefix for encrypted values to identify them.
	 */
	private const ENCRYPTED_PREFIX = '$bcsend_enc$';

	/**
	 * List of settings keys that should be encrypted.
	 *
	 * @var string[]
	 */
	private static $encrypted_keys = array(
		'brevo_api_key',
		'anthropic_api_key',
		'openai_api_key',
		'firebase_service_account_json',
		'zernio_api_key',
		'zernio_webhook_secret',
	);

	/**
	 * Get the encryption key derived from WordPress AUTH_KEY.
	 *
	 * @return string 32-byte binary encryption key.
	 */
	private static function get_key() {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return hash( 'sha256', AUTH_KEY, true );
		}

		// Standard WordPress always defines AUTH_KEY; without it this
		// fallback key is public knowledge (it is in the plugin source), so
		// "encryption" is obfuscation only. Kept for backward compatibility
		// with values already stored under it - but say so, once a day.
		if ( class_exists( 'Bcsend_Logger' ) && false === get_transient( 'bcsend_enc_fallback_warned' ) ) {
			set_transient( 'bcsend_enc_fallback_warned', 1, DAY_IN_SECONDS );
			Bcsend_Logger::log( 'settings', 'AUTH_KEY is not defined; stored secrets are encrypted with a publicly known fallback key. Define AUTH_KEY in wp-config.php and re-save the API keys.', '', 'error' );
		}

		return hash( 'sha256', 'bcsend-default-key-change-me', true );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $value The plaintext value to encrypt.
	 * @return string The encrypted value with prefix, or original if encryption fails.
	 */
	public static function encrypt( $value ) {
		if ( empty( $value ) ) {
			return $value;
		}

		// Don't double-encrypt.
		if ( self::is_encrypted( $value ) ) {
			return $value;
		}

		$key = self::get_key();
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );

		$encrypted = openssl_encrypt( $value, self::METHOD, $key, 0, $iv );

		if ( false === $encrypted ) {
			return $value;
		}

		// Combine IV and encrypted data, then base64 encode.
		$result = base64_encode( $iv . $encrypted );

		return self::ENCRYPTED_PREFIX . $result;
	}

	/**
	 * Decrypt a value.
	 *
	 * Legacy plaintext values (no encryption prefix) pass through untouched.
	 * A prefixed value that cannot be decrypted returns an EMPTY string, not
	 * the ciphertext: returning the blob made the plugin send
	 * "$bcsend_enc$..." to providers as the API key (baffling 401s, blob
	 * shown in the settings field), while an empty value flows into the
	 * existing honest "API key not configured" handling.
	 *
	 * @param string $value The encrypted value to decrypt.
	 * @return string The decrypted plaintext, '' if decryption fails.
	 */
	public static function decrypt( $value ) {
		if ( empty( $value ) ) {
			return $value;
		}

		// Check if value is encrypted.
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		// Remove prefix.
		$data = substr( $value, strlen( self::ENCRYPTED_PREFIX ) );
		$data = base64_decode( $data );

		if ( false === $data ) {
			return self::fail_decrypt();
		}

		$key       = self::get_key();
		$iv_length = openssl_cipher_iv_length( self::METHOD );

		// Extract IV and encrypted data.
		$iv        = substr( $data, 0, $iv_length );
		$encrypted = substr( $data, $iv_length );

		$decrypted = openssl_decrypt( $encrypted, self::METHOD, $key, 0, $iv );

		if ( false === $decrypted ) {
			return self::fail_decrypt();
		}

		return $decrypted;
	}

	/**
	 * Report a decryption failure and return the empty replacement value.
	 *
	 * @return string Always ''.
	 */
	private static function fail_decrypt() {
		if ( class_exists( 'Bcsend_Logger' ) && false === get_transient( 'bcsend_enc_decrypt_warned' ) ) {
			set_transient( 'bcsend_enc_decrypt_warned', 1, HOUR_IN_SECONDS );
			Bcsend_Logger::log( 'settings', 'A stored secret could not be decrypted - AUTH_KEY may have changed (host migration, key rotation). Re-enter the affected API keys in Beacon Settings.', '', 'error' );
		}

		return '';
	}

	/**
	 * Check if a value is encrypted.
	 *
	 * @param string $value The value to check.
	 * @return bool True if encrypted, false otherwise.
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::ENCRYPTED_PREFIX );
	}

	/**
	 * Check if a settings key should be encrypted.
	 *
	 * @param string $key The settings key.
	 * @return bool True if the key should be encrypted.
	 */
	public static function should_encrypt( $key ) {
		return in_array( $key, self::$encrypted_keys, true );
	}

	/**
	 * Encrypt sensitive fields in a settings array.
	 *
	 * @param array $settings The settings array.
	 * @return array Settings with sensitive fields encrypted.
	 */
	public static function encrypt_settings( $settings ) {
		foreach ( self::$encrypted_keys as $key ) {
			if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
				$settings[ $key ] = self::encrypt( $settings[ $key ] );
			}
		}
		return $settings;
	}

	/**
	 * Decrypt sensitive fields in a settings array.
	 *
	 * @param array $settings The settings array.
	 * @return array Settings with sensitive fields decrypted.
	 */
	public static function decrypt_settings( $settings ) {
		foreach ( self::$encrypted_keys as $key ) {
			if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
				$settings[ $key ] = self::decrypt( $settings[ $key ] );
			}
		}
		return $settings;
	}
}
