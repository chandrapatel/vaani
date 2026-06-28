<?php
/**
 * Resolves which translations are shown to readers.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Frontend;

use Vaani\Core\Language\Registry;
use Vaani\Translation\TranslationPostType;
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
	 * Transient key prefix for a source's renderable-translation map.
	 */
	private const CACHE_PREFIX = 'vaani_avail_';

	/**
	 * Cache lifetime. Short, since explicit invalidation does the real work — the
	 * TTL is only a backstop against a missed invalidation.
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * @param TranslationRepository $repository Translation storage.
	 */
	public function __construct(
		private TranslationRepository $repository
	) {}

	/**
	 * Register cache-invalidation hooks.
	 *
	 * Any change to a translation post — a manual editor save, trash/untrash, or
	 * delete — flushes its source's cached map. Repository status-only writes fire
	 * `vaani_translation_changed` (they bypass `save_post`), which flushes too.
	 */
	public function register(): void {
		add_action( 'save_post_' . TranslationPostType::POST_TYPE, array( $this, 'flush_for_translation' ) );
		add_action( 'trashed_post', array( $this, 'flush_for_translation' ) );
		add_action( 'untrashed_post', array( $this, 'flush_for_translation' ) );
		add_action( 'before_delete_post', array( $this, 'flush_for_translation' ) );
		add_action( 'vaani_translation_changed', array( $this, 'flush' ) );
	}

	/**
	 * Every renderable translation for a source post.
	 *
	 * @return array<string, WP_Post> Map of language code => translation post,
	 *                                 in the registry's configured order.
	 */
	public function for_source( int $source_id ): array {
		$map = $this->resolve_map( $source_id );

		$available = array();
		foreach ( $map as $lang => $translation_id ) {
			$translation = get_post( $translation_id );
			if ( $translation instanceof WP_Post ) {
				$available[ $lang ] = $translation;
			}
		}

		return $available;
	}

	/**
	 * The renderable translation for one language, or null.
	 */
	public function renderable( int $source_id, string $lang ): ?WP_Post {
		$map = $this->resolve_map( $source_id );

		if ( ! isset( $map[ $lang ] ) ) {
			return null;
		}

		$translation = get_post( $map[ $lang ] );

		return $translation instanceof WP_Post ? $translation : null;
	}

	/**
	 * Resolve a source's `lang => translation_id` map, from cache when present.
	 *
	 * Only renderable (published + completed) translations appear in the map. IDs
	 * are cached rather than post objects so an edited translation's new content is
	 * always re-fetched fresh; the cache only saves the lookup queries.
	 *
	 * @return array<string, int>
	 */
	private function resolve_map( int $source_id ): array {
		$cached = get_transient( self::CACHE_PREFIX . $source_id );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = array();
		foreach ( array_keys( Registry::all() ) as $lang ) {
			$translation = $this->repository->find_renderable( $source_id, $lang );

			if ( $translation ) {
				$map[ $lang ] = (int) $translation->ID;
			}
		}

		set_transient( self::CACHE_PREFIX . $source_id, $map, self::CACHE_TTL );

		return $map;
	}

	/**
	 * Flush the cached map for a source post.
	 *
	 * @param int $source_id Source post ID.
	 */
	public function flush( $source_id ): void {
		delete_transient( self::CACHE_PREFIX . (int) $source_id );
	}

	/**
	 * Flush by translation post: resolve its source and flush that source's map.
	 *
	 * No-op for non-translation posts, so the shared `trashed_post` /
	 * `before_delete_post` hooks ignore unrelated content.
	 *
	 * @param int $post_id Possibly a translation post ID.
	 */
	public function flush_for_translation( $post_id ): void {
		if ( TranslationPostType::POST_TYPE !== get_post_type( (int) $post_id ) ) {
			return;
		}

		$source_id = (int) get_post_meta( (int) $post_id, TranslationPostType::META_SOURCE_ID, true );

		if ( $source_id > 0 ) {
			$this->flush( $source_id );
		}
	}
}
