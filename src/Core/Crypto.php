<?php
/**
 * Symmetric encryption for secrets stored in the database.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts/decrypts values with AES-256-CTR, deriving the key from the
 * install's LOGGED_IN_KEY + LOGGED_IN_SALT constants (present on every
 * standard WordPress install via wp-config.php).
 *
 * Used to keep the Sarvam API key from sitting in plaintext in wp_options.
 * Tying the key to the install's salts means a leaked database dump alone
 * cannot reveal the secret without wp-config.php.
 */
class Crypto {

	/**
	 * OpenSSL cipher method.
	 */
	private const METHOD = 'aes-256-ctr';

	/**
	 * Marker prefixing every ciphertext so encrypted and legacy/plaintext
	 * values are distinguishable and encryption stays idempotent.
	 */
	private const PREFIX = 'vaani:v1:';

	/**
	 * Encrypt a value. Returns it prefixed + base64-encoded.
	 *
	 * No-ops on an empty string or a value that is already encrypted, so it
	 * is safe if the WordPress sanitize callback runs more than once.
	 *
	 * @param string $plaintext Value to encrypt.
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || $this->is_encrypted( $plaintext ) ) {
			return $plaintext;
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		if ( false === $iv_length ) {
			return $plaintext;
		}

		$iv         = random_bytes( $iv_length );
		$ciphertext = openssl_encrypt( $plaintext, self::METHOD, $this->key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt(). A value without the marker is
	 * treated as legacy plaintext and returned unchanged.
	 *
	 * @param string $value Stored value.
	 */
	public function decrypt( string $value ): string {
		if ( '' === $value || ! $this->is_encrypted( $value ) ) {
			return $value;
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		if ( false === $iv_length || strlen( $raw ) <= $iv_length ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );
		$plaintext  = openssl_decrypt( $ciphertext, self::METHOD, $this->key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Whether a value carries the ciphertext marker.
	 *
	 * @param string $value Value to inspect.
	 */
	public function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Derive a 32-byte (256-bit) binary key from the install's salts.
	 */
	private function key(): string {
		$secret = ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' )
			. ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : '' );

		return hash( 'sha256', $secret, true );
	}
}
