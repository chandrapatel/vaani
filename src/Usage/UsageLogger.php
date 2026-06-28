<?php
/**
 * Usage logging listener.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Catches the `vaani_usage_logged` action that the translation and audio jobs
 * already fire after a successful Sarvam call (CLAUDE.md §6 seam #4) and writes
 * a row with an estimated cost.
 *
 * Logging lives here rather than in `Sarvam\Client` because the client has no
 * `source_id`; the background jobs are the only layer with full context.
 */
class UsageLogger {

	/**
	 * Action fired by TranslationService and AudioService after a billable call.
	 */
	public const HOOK = 'vaani_usage_logged';

	/**
	 * @param UsageRepository $repository Usage storage.
	 */
	public function __construct( private UsageRepository $repository ) {}

	/**
	 * Register the listener.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'record' ), 10, 4 );
	}

	/**
	 * Record a billable Sarvam call.
	 *
	 * @param string $operation Operation name ('translate', 'transliterate', 'tts').
	 * @param string $lang      Target language code.
	 * @param int    $units     Billable units (characters) consumed.
	 * @param int    $source_id Source post ID.
	 */
	public function record( string $operation, string $lang, int $units, int $source_id ): void {
		$units = (int) $units;

		if ( $units <= 0 ) {
			return;
		}

		$this->repository->insert(
			sanitize_key( $operation ),
			sanitize_key( $lang ),
			$units,
			(int) $source_id,
			Pricing::unit_type( $operation ),
			Pricing::estimate( $operation, $units )
		);
	}
}
