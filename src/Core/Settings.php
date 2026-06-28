<?php
/**
 * Settings storage and accessors.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core;

use Vaani\Core\Language\SupportedLanguages;
use Vaani\Core\Sarvam\Client;

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
			'api_key'          => '',
			'source_lang'      => 'en',
			'post_types'       => array( 'post' ),
			'target_langs'     => array(),
			'translate_model'  => Client::DEFAULT_MODEL,
			'translate_mode'   => 'formal',
			'translate_gender' => 'Male',
			'audio_model'      => Client::DEFAULT_TTS_MODEL,
			'audio_speaker'    => '',
			'audio_pace'       => 1.0,
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
	 * Globally enabled target languages, as supported language codes.
	 *
	 * @return string[]
	 */
	public function get_target_langs(): array {
		$langs = $this->all()['target_langs'];

		return is_array( $langs ) ? array_values( $langs ) : array();
	}

	/**
	 * Selected translation model (e.g. `mayura:v1`).
	 */
	public function get_translate_model(): string {
		$model = (string) $this->all()['translate_model'];

		return isset( Client::TRANSLATE_MODELS[ $model ] ) ? $model : Client::DEFAULT_MODEL;
	}

	/**
	 * Selected translation tone mode (e.g. `formal`).
	 */
	public function get_translate_mode(): string {
		return (string) $this->all()['translate_mode'];
	}

	/**
	 * Selected speaker gender (`Male`/`Female`).
	 */
	public function get_translate_gender(): string {
		return (string) $this->all()['translate_gender'];
	}

	/**
	 * Translation config for {@see Client}, with model-appropriate values.
	 *
	 * @return array<string, string>
	 */
	public function get_translation_config(): array {
		return array(
			'model'          => $this->get_translate_model(),
			'mode'           => $this->get_translate_mode(),
			'speaker_gender' => $this->get_translate_gender(),
		);
	}

	/**
	 * Selected text-to-speech model (e.g. `bulbul:v3`).
	 */
	public function get_audio_model(): string {
		$model = (string) $this->all()['audio_model'];

		return isset( Client::TTS_MODELS[ $model ] ) ? $model : Client::DEFAULT_TTS_MODEL;
	}

	/**
	 * Selected TTS speaker, or '' to use the model's default voice.
	 */
	public function get_audio_speaker(): string {
		return (string) $this->all()['audio_speaker'];
	}

	/**
	 * Selected TTS speaking pace (0.5–2.0).
	 */
	public function get_audio_pace(): float {
		return (float) $this->all()['audio_pace'];
	}

	/**
	 * Audio config for {@see Client}.
	 *
	 * @return array<string, mixed>
	 */
	public function get_audio_config(): array {
		return array(
			'tts_model'   => $this->get_audio_model(),
			'tts_speaker' => $this->get_audio_speaker(),
			'tts_pace'    => $this->get_audio_pace(),
		);
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

		$target_langs = isset( $input['target_langs'] ) && is_array( $input['target_langs'] )
			? array_values( array_intersect( SupportedLanguages::codes(), array_map( 'sanitize_key', $input['target_langs'] ) ) )
			: array();

		$model = isset( $input['translate_model'] ) ? sanitize_text_field( (string) $input['translate_model'] ) : $defaults['translate_model'];
		$model = isset( Client::TRANSLATE_MODELS[ $model ] ) ? $model : $defaults['translate_model'];

		$mode = isset( $input['translate_mode'] ) ? sanitize_text_field( (string) $input['translate_mode'] ) : $defaults['translate_mode'];
		$mode = in_array( $mode, Client::TRANSLATE_MODES, true ) ? $mode : $defaults['translate_mode'];

		$gender = isset( $input['translate_gender'] ) ? sanitize_text_field( (string) $input['translate_gender'] ) : $defaults['translate_gender'];
		$gender = in_array( $gender, Client::SPEAKER_GENDERS, true ) ? $gender : $defaults['translate_gender'];

		$audio_model = isset( $input['audio_model'] ) ? sanitize_text_field( (string) $input['audio_model'] ) : $defaults['audio_model'];
		$audio_model = isset( Client::TTS_MODELS[ $audio_model ] ) ? $audio_model : $defaults['audio_model'];

		$audio_speaker = isset( $input['audio_speaker'] ) ? sanitize_key( (string) $input['audio_speaker'] ) : '';
		$audio_speaker = in_array( $audio_speaker, self::tts_speaker_choices(), true ) ? $audio_speaker : '';

		$audio_pace = isset( $input['audio_pace'] ) ? (float) $input['audio_pace'] : (float) $defaults['audio_pace'];
		$audio_pace = max( 0.5, min( 2.0, $audio_pace ) );

		return array(
			'api_key'          => $api_key,
			'source_lang'      => $source_lang,
			'post_types'       => $post_types,
			'target_langs'     => $target_langs,
			'translate_model'  => $model,
			'translate_mode'   => $mode,
			'translate_gender' => $gender,
			'audio_model'      => $audio_model,
			'audio_speaker'    => $audio_speaker,
			'audio_pace'       => $audio_pace,
		);
	}

	/**
	 * Every selectable TTS speaker across all models (the empty string — meaning
	 * "model default" — is handled separately by the caller).
	 *
	 * @return string[]
	 */
	public static function tts_speaker_choices(): array {
		$speakers = array();

		foreach ( Client::TTS_MODELS as $caps ) {
			$speakers = array_merge( $speakers, $caps['speakers'] );
		}

		return array_values( array_unique( $speakers ) );
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
