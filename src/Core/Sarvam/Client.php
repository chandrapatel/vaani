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
 * Centralises auth, timeout, retry/backoff, and error normalisation so no other
 * subsystem talks HTTP to Sarvam directly. Endpoints, the model name, and the
 * language codes were verified against docs.sarvam.ai per CLAUDE.md §4.
 */
class Client {

	/**
	 * API base URL.
	 */
	private const BASE_URL = 'https://api.sarvam.ai';

	/**
	 * Default request timeout in seconds.
	 */
	private const TIMEOUT = 30;

	/**
	 * Translation models and their capabilities.
	 *
	 * `mayura:v1` supports tone modes, speaker gender, and transliteration but
	 * caps input at 1000 chars. `sarvam-translate:v1` allows 2000 chars and
	 * more languages, but only formal mode and no speaker-gender control (it
	 * was observed to default to a female speaker and mix genders mid-content).
	 *
	 * @var array<string, array{max_chars: int, supports_modes: bool, supports_gender: bool}>
	 */
	public const TRANSLATE_MODELS = array(
		'mayura:v1'           => array(
			'max_chars'       => 1000,
			'supports_modes'  => true,
			'supports_gender' => true,
		),
		'sarvam-translate:v1' => array(
			'max_chars'       => 2000,
			'supports_modes'  => false,
			'supports_gender' => false,
		),
	);

	/**
	 * Default translation model.
	 */
	public const DEFAULT_MODEL = 'mayura:v1';

	/**
	 * Allowed translation tone modes (`mayura:v1`).
	 *
	 * @var string[]
	 */
	public const TRANSLATE_MODES = array( 'formal', 'modern-colloquial', 'classic-colloquial', 'code-mixed' );

	/**
	 * Allowed speaker-gender values (`mayura:v1`).
	 *
	 * @var string[]
	 */
	public const SPEAKER_GENDERS = array( 'Male', 'Female' );

	/**
	 * Number of retry attempts on transient failures (429 / 5xx).
	 */
	private const MAX_RETRIES = 2;

	/**
	 * Sarvam API key.
	 */
	private string $api_key;

	/**
	 * Translation model in use.
	 */
	private string $model;

	/**
	 * Translation tone mode.
	 */
	private string $mode;

	/**
	 * Speaker gender, or '' to let the model decide.
	 */
	private string $speaker_gender;

	/**
	 * @param string               $api_key Sarvam API key.
	 * @param array<string, string> $config  Optional translation config:
	 *                                        `model`, `mode`, `speaker_gender`.
	 */
	public function __construct( string $api_key, array $config = array() ) {
		$this->api_key = $api_key;

		$model         = $config['model'] ?? self::DEFAULT_MODEL;
		$this->model   = isset( self::TRANSLATE_MODELS[ $model ] ) ? $model : self::DEFAULT_MODEL;
		$this->mode    = $config['mode'] ?? 'formal';
		$this->speaker_gender = $config['speaker_gender'] ?? '';
	}

	/**
	 * Max input characters per translation request for the configured model.
	 */
	public function max_input_chars(): int {
		return self::TRANSLATE_MODELS[ $this->model ]['max_chars'];
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
	 * Translate a single string of text.
	 *
	 * Operates on plain text only (one text node at a time); HTML/block parsing
	 * lives in {@see \Vaani\Translation\BlockTranslator}. The caller keeps
	 * `$text` within {@see self::max_input_chars()}.
	 *
	 * @param string $text        Text to translate.
	 * @param string $source_code Sarvam source language code (e.g. `en-IN`).
	 * @param string $target_code Sarvam target language code (e.g. `hi-IN`).
	 */
	public function translate_text( string $text, string $source_code, string $target_code ): Response {
		if ( '' === trim( $this->api_key ) ) {
			return Response::failure( __( 'No API key set.', 'vaani' ) );
		}

		// Nothing translatable (whitespace/punctuation only) — return as-is.
		if ( '' === trim( $text ) ) {
			return Response::success( $text );
		}

		$response = $this->request( '/translate', $this->translate_body( $text, $source_code, $target_code ) );

		if ( is_wp_error( $response ) ) {
			return Response::failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || ! isset( $body['translated_text'] ) ) {
			return Response::failure( $this->error_message( $code, $body ) );
		}

		return Response::success(
			(string) $body['translated_text'],
			(int) mb_strlen( $text )
		);
	}

	/**
	 * Build the /translate request body, including only the optional parameters
	 * the configured model actually supports.
	 *
	 * @return array<string, string>
	 */
	private function translate_body( string $text, string $source_code, string $target_code ): array {
		$capabilities = self::TRANSLATE_MODELS[ $this->model ];

		$body = array(
			'model'                => $this->model,
			'input'                => $text,
			'source_language_code' => $source_code,
			'target_language_code' => $target_code,
		);

		// sarvam-translate:v1 supports formal mode only.
		$body['mode'] = $capabilities['supports_modes'] && in_array( $this->mode, self::TRANSLATE_MODES, true )
			? $this->mode
			: 'formal';

		if ( $capabilities['supports_gender'] && in_array( $this->speaker_gender, self::SPEAKER_GENDERS, true ) ) {
			$body['speaker_gender'] = $this->speaker_gender;
		}

		return $body;
	}

	/**
	 * POST a JSON body to an endpoint, retrying transient failures with backoff.
	 *
	 * @param string               $path Endpoint path (leading slash).
	 * @param array<string, mixed> $body Request payload.
	 * @return array<string, mixed>|\WP_Error WordPress HTTP response or error.
	 */
	private function request( string $path, array $body ) {
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $body ),
		);

		$response = null;

		for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post( self::BASE_URL . $path, $args );

			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );

				// Only 429 (rate limit) and 5xx are worth retrying.
				if ( 429 !== $code && $code < 500 ) {
					return $response;
				}
			}

			if ( $attempt < self::MAX_RETRIES ) {
				// Exponential backoff: 1s, then 2s.
				sleep( 2 ** $attempt );
			}
		}

		return $response;
	}

	/**
	 * Build a human-readable error message from a failed response.
	 *
	 * @param int   $code HTTP status code.
	 * @param mixed $body Decoded response body.
	 */
	private function error_message( int $code, $body ): string {
		if ( is_array( $body ) && isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			return $body['error']['message'];
		}

		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return __( 'The Sarvam API key was rejected.', 'vaani' );
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Sarvam returned an unexpected response (HTTP %d).', 'vaani' ), $code );
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
