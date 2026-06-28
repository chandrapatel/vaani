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
	 * Text-to-speech (Bulbul) models, their per-request character caps, and the
	 * named voices each one accepts.
	 *
	 * Speaker names are model-specific: a `bulbul:v2` voice is rejected by
	 * `bulbul:v3` and vice versa, so {@see self::tts_speaker()} validates the
	 * configured speaker against the active model and falls back to that model's
	 * default. Lists are curated subsets of Sarvam's catalogue (docs.sarvam.ai).
	 *
	 * @var array<string, array{max_chars: int, default_speaker: string, speakers: string[]}>
	 */
	public const TTS_MODELS = array(
		'bulbul:v3' => array(
			'max_chars'       => 2500,
			'default_speaker' => 'shubh',
			'speakers'        => array( 'shubh', 'aditya', 'rahul', 'rohan', 'kavya', 'priya', 'neha', 'pooja', 'simran', 'ritu' ),
		),
		'bulbul:v2' => array(
			'max_chars'       => 1500,
			'default_speaker' => 'anushka',
			'speakers'        => array( 'anushka', 'manisha', 'vidya', 'arya', 'abhilash', 'karun', 'hitesh' ),
		),
	);

	/**
	 * Default text-to-speech model.
	 */
	public const DEFAULT_TTS_MODEL = 'bulbul:v3';

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
	 * Text-to-speech model in use.
	 */
	private string $tts_model;

	/**
	 * Configured TTS speaker, or '' to use the model's default voice.
	 */
	private string $tts_speaker;

	/**
	 * TTS speaking pace (0.5–2.0; 1.0 = normal).
	 */
	private float $tts_pace;

	/**
	 * @param string               $api_key Sarvam API key.
	 * @param array<string, mixed> $config  Optional config. Translation keys:
	 *                                        `model`, `mode`, `speaker_gender`.
	 *                                        Audio keys: `tts_model`, `tts_speaker`,
	 *                                        `tts_pace`.
	 */
	public function __construct( string $api_key, array $config = array() ) {
		$this->api_key = $api_key;

		$model         = $config['model'] ?? self::DEFAULT_MODEL;
		$this->model   = isset( self::TRANSLATE_MODELS[ $model ] ) ? $model : self::DEFAULT_MODEL;
		$this->mode    = $config['mode'] ?? 'formal';
		$this->speaker_gender = $config['speaker_gender'] ?? '';

		$tts_model       = $config['tts_model'] ?? self::DEFAULT_TTS_MODEL;
		$this->tts_model = isset( self::TTS_MODELS[ $tts_model ] ) ? $tts_model : self::DEFAULT_TTS_MODEL;
		$this->tts_speaker = (string) ( $config['tts_speaker'] ?? '' );
		$this->tts_pace    = $this->clamp_pace( (float) ( $config['tts_pace'] ?? 1.0 ) );
	}

	/**
	 * Max input characters per translation request for the configured model.
	 */
	public function max_input_chars(): int {
		return self::TRANSLATE_MODELS[ $this->model ]['max_chars'];
	}

	/**
	 * Max input characters per text-to-speech request for the configured model.
	 */
	public function tts_max_input_chars(): int {
		return self::TTS_MODELS[ $this->tts_model ]['max_chars'];
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
	 * Synthesise speech for a single chunk of plain text.
	 *
	 * Operates on one chunk at a time; the caller keeps `$text` within
	 * {@see self::tts_max_input_chars()} and concatenates the resulting audio
	 * across chunks. Requests MP3 directly (`output_audio_codec`), so no codec
	 * conversion is needed.
	 *
	 * @param string $text        Plain text to speak.
	 * @param string $target_code Sarvam language code (e.g. `hi-IN`).
	 * @return Response On success, {@see Response::text()} holds the **base64-encoded**
	 *                  MP3 audio and {@see Response::units()} the character count.
	 */
	public function text_to_speech( string $text, string $target_code ): Response {
		if ( '' === trim( $this->api_key ) ) {
			return Response::failure( __( 'No API key set.', 'vaani' ) );
		}

		if ( '' === trim( $text ) ) {
			return Response::failure( __( 'Nothing to convert to speech.', 'vaani' ) );
		}

		$response = $this->request( '/text-to-speech', $this->tts_body( $text, $target_code ) );

		if ( is_wp_error( $response ) ) {
			return Response::failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['audios'][0] ) || ! is_string( $body['audios'][0] ) ) {
			return Response::failure( $this->error_message( $code, $body ) );
		}

		return Response::success( $body['audios'][0], (int) mb_strlen( $text ) );
	}

	/**
	 * Build the /text-to-speech request body.
	 *
	 * @return array<string, mixed>
	 */
	private function tts_body( string $text, string $target_code ): array {
		return array(
			'model'                => $this->tts_model,
			'text'                 => $text,
			'target_language_code' => $target_code,
			'speaker'              => $this->tts_speaker(),
			'output_audio_codec'   => 'mp3',
			'pace'                 => $this->tts_pace,
		);
	}

	/**
	 * The voice to request: the configured speaker when valid for the active
	 * model, otherwise that model's default.
	 */
	private function tts_speaker(): string {
		$capabilities = self::TTS_MODELS[ $this->tts_model ];

		if ( '' !== $this->tts_speaker && in_array( $this->tts_speaker, $capabilities['speakers'], true ) ) {
			return $this->tts_speaker;
		}

		return $capabilities['default_speaker'];
	}

	/**
	 * Constrain pace to Sarvam's accepted 0.5–2.0 range.
	 */
	private function clamp_pace( float $pace ): float {
		return max( 0.5, min( 2.0, $pace ) );
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
