<?php
/**
 * Sarvam AI HTTP client.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core\Sarvam;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Sarvam AI API.
 *
 * Phase 0 only needs a connectivity/auth check. The translation and
 * text-to-speech methods (and the exact endpoints, models, and language
 * codes) are added in Phases 2 and 4 after verifying docs.sarvam.ai per
 * CLAUDE.md section 4.
 */
class Client {

	/**
	 * API base URL.
	 */
	private const BASE_URL = 'https://api.sarvam.ai';

	/**
	 * Default request timeout in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Sarvam API key.
	 */
	private string $api_key;

	/**
	 * @param string $api_key Sarvam API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Verify the API key reaches Sarvam and authenticates.
	 *
	 * Sends a minimal translation request to the authenticated /translate
	 * endpoint. Only a genuine 2xx counts as success — a 401/403 means the
	 * key was rejected, and anything else is surfaced as an error rather than
	 * a false positive. (The /v1/models listing is unauthenticated and
	 * returns 200 for any key, so it cannot validate one.)
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function test_connection(): array {
		if ( '' === trim( $this->api_key ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'No API key set.', 'vaani' ),
			);
		}

		$response = wp_remote_post(
			self::BASE_URL . '/translate',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					array(
						'input'                => 'hello',
						'source_language_code' => 'en-IN',
						'target_language_code' => 'hi-IN',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'ok'      => true,
				'message' => __( 'Connection successful.', 'vaani' ),
			);
		}

		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The API key was rejected. Check the key and try again.', 'vaani' ),
			);
		}

		return array(
			'ok'      => false,
			/* translators: %d: HTTP status code. */
			'message' => sprintf( __( 'Sarvam returned an unexpected response (HTTP %d).', 'vaani' ), $code ),
		);
	}

	/**
	 * Common request headers including authentication.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'api-subscription-key' => $this->api_key,
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json',
		);
	}
}
