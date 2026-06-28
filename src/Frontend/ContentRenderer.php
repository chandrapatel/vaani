<?php
/**
 * Swaps translated content onto a `/<lang>/<slug>/` request.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Frontend;

use Vaani\Core\Language\Registry;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * On a translation request, replaces the source post's title and content with the
 * translation's before the loop renders.
 *
 * The swap happens on `the_posts` for the main query so the mutated post object is
 * also the queried object: `the_title()`, `the_content()`, and the document
 * `<title>` all pick up the translated values without duplicating the block/shortcode
 * rendering pipeline. If no published, non-stale translation exists the source is
 * left untouched — the reader sees the original (the spec's fallback).
 */
class ContentRenderer {

	/**
	 * @param AvailableTranslations $available Renderable-translation resolver.
	 */
	public function __construct(
		private AvailableTranslations $available
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'the_posts', array( $this, 'swap_translation' ), 10, 2 );
		add_filter( 'language_attributes', array( $this, 'translate_lang_attribute' ) );
	}

	/**
	 * Replace the queried source post's title/content with its translation.
	 *
	 * @param WP_Post[] $posts Posts for this query.
	 * @param WP_Query  $query The query being filtered.
	 * @return WP_Post[]
	 */
	public function swap_translation( array $posts, WP_Query $query ): array {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}

		$lang = (string) $query->get( Router::QV_LANG );

		if ( '' === $lang || empty( $posts ) ) {
			return $posts;
		}

		$source = $posts[0];

		if ( ! $source instanceof WP_Post ) {
			return $posts;
		}

		$translation = $this->available->renderable( $source->ID, $lang );

		if ( ! $translation instanceof WP_Post ) {
			return $posts;
		}

		// Mutate in place: $posts[0] becomes the query's queried object, so the
		// translated title also flows into the document <title>.
		$source->post_title   = $translation->post_title;
		$source->post_content = $translation->post_content;

		return $posts;
	}

	/**
	 * Set `<html lang>` to the target language on a translation request.
	 *
	 * @param string $output Existing language attributes (e.g. `lang="en-US"`).
	 * @return string
	 */
	public function translate_lang_attribute( string $output ): string {
		$lang = (string) get_query_var( Router::QV_LANG );

		if ( '' === $lang ) {
			return $output;
		}

		$language = Registry::get( $lang );

		if ( null === $language ) {
			return $output;
		}

		return (string) preg_replace(
			'/lang="[^"]*"/',
			'lang="' . esc_attr( $language->hreflang() ) . '"',
			$output
		);
	}
}
