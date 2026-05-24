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
		$salt = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'bcsend-default-key-change-me';
		return hash( 'sha256', $salt, true );
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
	 * @param string $value The encrypted value to decrypt.
	 * @return string The decrypted plaintext, or original if decryption fails.
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
			return $value;
		}

		$key       = self::get_key();
		$iv_length = openssl_cipher_iv_length( self::METHOD );

		// Extract IV and encrypted data.
		$iv        = substr( $data, 0, $iv_length );
		$encrypted = substr( $data, $iv_length );

		$decrypted = openssl_decrypt( $encrypted, self::METHOD, $key, 0, $iv );

		if ( false === $decrypted ) {
			return $value;
		}

		return $decrypted;
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
