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
	 * The plugin's own language-agnostic dynamic blocks (switcher, player). They
	 * belong to the original post rather than the translation snapshot, so they are
	 * injected at render time (see {@see self::inject_vaani_blocks()}) — adding,
	 * moving, or removing one on the original updates every language without a
	 * re-translation that would overwrite reviewed edits.
	 *
	 * @var string[]
	 */
	private const VAANI_BLOCKS = array(
		'vaani/language-switcher',
		'vaani/audio-player',
	);

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
		// translated title also flows into the document <title>. $source still
		// holds the original content here, the source for the injected Vaani blocks.
		$source->post_title   = $translation->post_title;
		$source->post_content = $this->inject_vaani_blocks( $translation->post_content, $source->post_content );

		// Swap the excerpt too when the translation has one, so archives, search
		// results, and meta-description fallbacks on /<lang>/ stay translated.
		if ( '' !== trim( (string) $translation->post_excerpt ) ) {
			$source->post_excerpt = $translation->post_excerpt;
		}

		return $posts;
	}

	/**
	 * Merge the original's Vaani blocks into the translation's content at the same
	 * relative position.
	 *
	 * A translation is a snapshot taken when it was generated, so Vaani blocks added
	 * to the original afterward are missing from it. Instead of storing these
	 * language-agnostic dynamic blocks in every translation — and forcing a
	 * re-translation to refresh them — the original is their single source of
	 * truth and they are spliced in on each render.
	 *
	 * Position is anchored to the surrounding top-level content blocks, whose order
	 * the translator preserves 1:1. Any Vaani block already in the snapshot is dropped
	 * first so nothing is duplicated. Only top-level placement is handled; a Vaani
	 * block nested inside a container block stays with the original.
	 *
	 * @param string $translation_content Stored translation post_content.
	 * @param string $original_content    Current original post_content.
	 * @return string
	 */
	private function inject_vaani_blocks( string $translation_content, string $original_content ): string {
		// Fast path: no Vaani block on either side, so skip the parse/serialize round trip.
		if ( ! $this->mentions_vaani_block( $original_content ) && ! $this->mentions_vaani_block( $translation_content ) ) {
			return $translation_content;
		}

		$blocks_at    = $this->map_vaani_block_positions( parse_blocks( $original_content ) );
		$merged       = array();
		$content_seen = 0;

		foreach ( parse_blocks( $translation_content ) as $block ) {
			if ( $this->is_vaani_block( $block ) ) {
				continue; // Drop the snapshot copy; the original is the source of truth.
			}

			if ( null !== ( $block['blockName'] ?? null ) ) {
				$this->append_vaani_blocks( $merged, $blocks_at[ $content_seen ] ?? array() );
				++$content_seen;
			}

			$merged[] = $block;
		}

		// Vaani blocks positioned after the last content block.
		$this->append_vaani_blocks( $merged, $blocks_at[ $content_seen ] ?? array() );

		return serialize_blocks( $merged );
	}

	/**
	 * Map each original Vaani block to the count of content blocks before it,
	 * i.e. the index of the content block it should precede.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed top-level blocks.
	 * @return array<int, array<int, array<string, mixed>>> Content index => Vaani blocks.
	 */
	private function map_vaani_block_positions( array $blocks ): array {
		$map          = array();
		$content_seen = 0;

		foreach ( $blocks as $block ) {
			if ( $this->is_vaani_block( $block ) ) {
				$map[ $content_seen ][] = $block;
				continue;
			}

			if ( null !== ( $block['blockName'] ?? null ) ) {
				++$content_seen;
			}
		}

		return $map;
	}

	/**
	 * Append Vaani blocks to the merged list, each followed by block-separator
	 * whitespace so the serialized output stays tidy.
	 *
	 * @param array<int, array<string, mixed>> $merged Merged block list (by reference).
	 * @param array<int, array<string, mixed>> $blocks Vaani blocks to append.
	 */
	private function append_vaani_blocks( array &$merged, array $blocks ): void {
		foreach ( $blocks as $block ) {
			$merged[] = $block;
			$merged[] = array(
				'blockName'    => null,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => "\n\n",
				'innerContent' => array( "\n\n" ),
			);
		}
	}

	/**
	 * Whether a block is one of the plugin's own injectable blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function is_vaani_block( array $block ): bool {
		return in_array( $block['blockName'] ?? '', self::VAANI_BLOCKS, true );
	}

	/**
	 * Cheap pre-check: does the content reference a Vaani block at all?
	 */
	private function mentions_vaani_block( string $content ): bool {
		return false !== strpos( $content, '<!-- wp:vaani/language-switcher' )
			|| false !== strpos( $content, '<!-- wp:vaani/audio-player' );
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
