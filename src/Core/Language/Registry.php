<?php
/**
 * Canonical language registry.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth that maps an internal language code to its Sarvam
 * API parameter and hreflang value (CLAUDE.md seam #2).
 *
 * Every subsystem — translation calls, meta, filenames, URL routes, hreflang —
 * reads language data from here so codes never drift (`hi` vs `hi-IN`, or the
 * load-bearing case where Odia is `or` internally but `od-IN` to Sarvam).
 *
 * Labels and the set of target languages still come from
 * {@see SupportedLanguages}; this registry layers Sarvam + hreflang data on top
 * rather than redefining the language list.
 */
class Registry {

	/**
	 * Source language code → Sarvam API code.
	 *
	 * Includes English, which is a valid translation source but never a target,
	 * so it is intentionally absent from {@see SupportedLanguages}.
	 *
	 * @var array<string, string>
	 */
	private const SARVAM_CODES = array(
		'en' => 'en-IN',
		'hi' => 'hi-IN',
		'bn' => 'bn-IN',
		'gu' => 'gu-IN',
		'kn' => 'kn-IN',
		'ml' => 'ml-IN',
		'mr' => 'mr-IN',
		'or' => 'od-IN',
		'pa' => 'pa-IN',
		'ta' => 'ta-IN',
		'te' => 'te-IN',
	);

	/**
	 * Every target language as `code => Language`, in the configured order.
	 *
	 * @return array<string, Language>
	 */
	public static function all(): array {
		$languages = array();

		foreach ( SupportedLanguages::all() as $code => $label ) {
			$languages[ $code ] = new Language(
				$code,
				$label,
				self::SARVAM_CODES[ $code ] ?? $code,
				$code
			);
		}

		return $languages;
	}

	/**
	 * A single target language, or null if the code is not a target language.
	 */
	public static function get( string $code ): ?Language {
		return self::all()[ $code ] ?? null;
	}

	/**
	 * Sarvam API code for any known language code (target or source `en`).
	 *
	 * @return string|null Sarvam code, or null when unknown.
	 */
	public static function sarvam_code( string $code ): ?string {
		return self::SARVAM_CODES[ $code ] ?? null;
	}
}
