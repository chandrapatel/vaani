<?php
/**
 * Resolves which translations are shown to readers.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Frontend;

use Vaani\Core\Language\Registry;
use Vaani\Translation\TranslationRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The single answer to "which languages can a reader view this post in?"
 *
 * A language qualifies when its translation is published and `completed`
 * ({@see TranslationRepository::find_renderable()}). Staleness (a source-hash
 * mismatch) is deliberately *not* a factor here: any edit to the source — even
 * adding a language-switcher block — changes the hash, and hiding a slightly
 * outdated translation behind the English original would be worse than serving
 * it. Staleness is surfaced as an editor-only warning (the translation meta box),
 * prompting the admin to re-translate; the reader keeps seeing the translation
 * meanwhile. The renderer, switcher, and hreflang all read from here so the rule
 * stays in one place.
 */
class AvailableTranslations {

	/**
	 * @param TranslationRepository $repository Translation storage.
	 */
	public function __construct(
		private TranslationRepository $repository
	) {}

	/**
	 * Every renderable translation for a source post.
	 *
	 * @return array<string, WP_Post> Map of language code => translation post,
	 *                                 in the registry's configured order.
	 */
	public function for_source( int $source_id ): array {
		$available = array();

		foreach ( array_keys( Registry::all() ) as $lang ) {
			$translation = $this->repository->find_renderable( $source_id, $lang );

			if ( $translation ) {
				$available[ $lang ] = $translation;
			}
		}

		return $available;
	}

	/**
	 * The renderable translation for one language, or null.
	 */
	public function renderable( int $source_id, string $lang ): ?WP_Post {
		return $this->repository->find_renderable( $source_id, $lang );
	}
}
