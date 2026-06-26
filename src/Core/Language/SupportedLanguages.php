<?php
/**
 * Supported target-language configuration.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for which target languages Vaani offers, as a
 * `code => label` map.
 *
 * Phase 1 needs only codes and human labels. Phase 2 introduces the canonical
 * registry (CLAUDE.md seam #2) that layers Sarvam API params and hreflang
 * values on top of this list; keeping the codes here means that registry can
 * wrap this config rather than redefining the language set.
 */
class SupportedLanguages {

	/**
	 * Target languages as `code => English label`.
	 *
	 * Codes are plain language codes (matching the planned `/<lang>/` URL
	 * prefix); the Phase 2 registry maps them to Sarvam's API parameters.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'hi' => __( 'Hindi', 'vaani' ),
			'bn' => __( 'Bengali', 'vaani' ),
			'gu' => __( 'Gujarati', 'vaani' ),
			'kn' => __( 'Kannada', 'vaani' ),
			'ml' => __( 'Malayalam', 'vaani' ),
			'mr' => __( 'Marathi', 'vaani' ),
			'or' => __( 'Odia', 'vaani' ),
			'pa' => __( 'Punjabi', 'vaani' ),
			'ta' => __( 'Tamil', 'vaani' ),
			'te' => __( 'Telugu', 'vaani' ),
		);
	}

	/**
	 * Supported language codes.
	 *
	 * @return string[]
	 */
	public static function codes(): array {
		return array_keys( self::all() );
	}

	/**
	 * Whether a code is a supported target language.
	 */
	public static function is_supported( string $code ): bool {
		return array_key_exists( $code, self::all() );
	}

	/**
	 * Reduce a list of codes to a `code => label` map of supported languages,
	 * preserving this config's ordering.
	 *
	 * @param string[] $codes Candidate language codes.
	 * @return array<string, string>
	 */
	public static function filter( array $codes ): array {
		return array_intersect_key( self::all(), array_flip( $codes ) );
	}
}
