<?php
/**
 * Settings storage and accessors.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single `vaani_settings` option.
 *
 * Every subsystem reads settings through this class rather than calling
 * get_option() directly, so the storage shape can change in one place.
 */
class Settings {

	/**
	 * Option name holding the settings array.
	 */
	public const OPTION_NAME = 'vaani_settings';

	/**
	 * Post types Vaani is allowed to operate on (v1 scope).
	 *
	 * @var string[]
	 */
	public const ALLOWED_POST_TYPES = array( 'post', 'page' );

	/**
	 * @param Crypto $crypto Encrypts/decrypts the stored API key.
	 */
	public function __construct( private Crypto $crypto ) {}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'     => '',
			'source_lang' => 'en',
			'post_types'  => array( 'post' ),
		);
	}

	/**
	 * Full settings array merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Sarvam API key, decrypted for use.
	 */
	public function get_api_key(): string {
		return $this->crypto->decrypt( (string) $this->all()['api_key'] );
	}

	/**
	 * Whether an API key has been saved.
	 */
	public function has_api_key(): bool {
		return '' !== trim( $this->get_api_key() );
	}

	/**
	 * Default source language code.
	 */
	public function get_source_lang(): string {
		return (string) $this->all()['source_lang'];
	}

	/**
	 * Post types enabled for translation.
	 *
	 * @return string[]
	 */
	public function get_post_types(): array {
		$types = $this->all()['post_types'];

		return is_array( $types ) ? array_values( $types ) : array();
	}

	/**
	 * Sanitize callback for the Settings API.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$api_key = $this->resolve_api_key( $input );

		$source_lang = isset( $input['source_lang'] ) ? sanitize_key( (string) $input['source_lang'] ) : $defaults['source_lang'];

		$post_types = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_values( array_intersect( self::ALLOWED_POST_TYPES, array_map( 'sanitize_key', $input['post_types'] ) ) )
			: array();

		return array(
			'api_key'     => $api_key,
			'source_lang' => $source_lang,
			'post_types'  => $post_types,
		);
	}

	/**
	 * Decide the API key value to store from submitted settings.
	 *
	 * The form never re-renders the saved secret, so a blank submission means
	 * "keep the current key". An explicit remove flag clears it; a non-empty
	 * submission replaces it (encrypted).
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 * @return string Encrypted key, or '' when cleared.
	 */
	private function resolve_api_key( array $input ): string {
		if ( ! empty( $input['remove_api_key'] ) ) {
			return '';
		}

		$submitted = isset( $input['api_key'] ) ? sanitize_text_field( (string) $input['api_key'] ) : '';

		if ( '' !== $submitted ) {
			return $this->crypto->encrypt( $submitted );
		}

		// Blank submission: preserve the existing stored (already encrypted) value.
		return $this->stored_api_key();
	}

	/**
	 * Raw stored API key value, still encrypted (no decryption).
	 */
	private function stored_api_key(): string {
		$stored = get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) && isset( $stored['api_key'] ) ? (string) $stored['api_key'] : '';
	}
}
