<?php
/**
 * Estimated Sarvam cost calculation.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that turns billable units into an estimated INR cost.
 *
 * These rates are a ROUGH ESTIMATE. Sarvam bills per character and its pricing
 * and models change over time, so the figure shown to users is explicitly
 * labelled an estimate and the dashboard links out to Sarvam for the actual
 * amount. Update the rates below if Sarvam's published pricing changes, or
 * override per-call via the `vaani_usage_rate_inr_per_char` filter.
 *
 * Current published rates (sarvam.ai/api-pricing):
 *   - Translation: ₹20 / 10,000 chars  = ₹0.002 / char
 *   - TTS (Bulbul v3): ₹30 / 10,000 chars = ₹0.003 / char
 */
class Pricing {

	/**
	 * Unit type recorded for character-billed operations (translation + TTS).
	 * `tokens` is reserved for a future LLM operation.
	 */
	public const UNIT_CHARACTERS = 'characters';

	/**
	 * Estimated INR per character, keyed by {@see self::category()}.
	 *
	 * @var array<string, float>
	 */
	private const RATE_INR_PER_CHAR = array(
		'translation' => 0.002,
		'tts'         => 0.003,
	);

	/**
	 * Group a logged operation into a billing category.
	 *
	 * Translation methods ('translate', 'transliterate') share one rate; TTS has
	 * its own. Anything unknown falls back to the translation rate.
	 */
	public static function category( string $operation ): string {
		return 'tts' === $operation ? 'tts' : 'translation';
	}

	/**
	 * The unit type stored alongside a logged operation.
	 */
	public static function unit_type( string $operation ): string {
		return self::UNIT_CHARACTERS;
	}

	/**
	 * Estimated INR cost for an operation's units.
	 */
	public static function estimate( string $operation, int $units ): float {
		$category = self::category( $operation );
		$rate     = self::RATE_INR_PER_CHAR[ $category ] ?? self::RATE_INR_PER_CHAR['translation'];

		/**
		 * Filters the per-character INR rate used for cost estimates.
		 *
		 * Lets advanced users align the estimate with their actual Sarvam plan
		 * without editing the plugin.
		 *
		 * @param float  $rate     INR per character.
		 * @param string $category Billing category ('translation' or 'tts').
		 */
		$rate = (float) apply_filters( 'vaani_usage_rate_inr_per_char', $rate, $category );

		return $units * $rate;
	}
}
