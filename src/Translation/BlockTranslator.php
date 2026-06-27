<?php
/**
 * Block-aware content translation.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;
use Vaani\Core\Sarvam\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Translates `post_content` block by block: parse → translate visible text →
 * reserialize, preserving block markup and attributes.
 *
 * The HTTP call lives in {@see Client}; this class owns the block/HTML walking
 * (CLAUDE.md §3). Non-translatable blocks (code, HTML, embeds, shortcodes,
 * preformatted) pass through untouched. Within translatable blocks, only text
 * nodes and a small allowlist of attributes (image `alt`) are translated.
 */
class BlockTranslator {

	/**
	 * Block names whose content is passed through verbatim.
	 *
	 * @var string[]
	 */
	private const SKIP_BLOCKS = array(
		'core/code',
		'core/html',
		'core/embed',
		'core/preformatted',
		'core/shortcode',
	);

	/**
	 * Block attribute keys that hold translatable text.
	 *
	 * @var string[]
	 */
	private const TRANSLATABLE_ATTRS = array( 'alt' );

	/**
	 * HTML element names whose text content must never be translated.
	 *
	 * @var string[]
	 */
	private const VERBATIM_TAGS = array( 'code', 'pre', 'kbd', 'samp', 'script', 'style' );

	/**
	 * Element attributes whose values are user-facing text and get translated
	 * in place (e.g. an image's rendered `alt`).
	 *
	 * @var string[]
	 */
	private const TRANSLATABLE_HTML_ATTRS = array( 'alt', 'title' );

	/**
	 * Per-run cache of `text => translation` to avoid duplicate API calls.
	 *
	 * @var array<string, string>
	 */
	private array $memo = array();

	/**
	 * Sarvam source language code for the current run (e.g. `en-IN`).
	 */
	private string $source = '';

	/**
	 * Sarvam target language code for the current run (e.g. `hi-IN`).
	 */
	private string $target = '';

	/**
	 * @param Client $client Sarvam API client.
	 */
	public function __construct( private Client $client ) {}

	/**
	 * Translate a string of plain text (e.g. the post title).
	 *
	 * @param string $text         Text to translate.
	 * @param string $source_code  Sarvam source code.
	 * @param string $target_code  Sarvam target code.
	 *
	 * @throws RuntimeException When the API call fails.
	 */
	public function translate_string( string $text, string $source_code, string $target_code ): string {
		$this->source = $source_code;
		$this->target = $target_code;

		return $this->translate_text( $text );
	}

	/**
	 * Translate block-editor `post_content`.
	 *
	 * @param string $post_content Source post_content.
	 * @param string $source_code  Sarvam source code (e.g. `en-IN`).
	 * @param string $target_code  Sarvam target code (e.g. `hi-IN`).
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	public function translate( string $post_content, string $source_code, string $target_code ): string {
		$this->source = $source_code;
		$this->target = $target_code;
		$this->memo   = array();

		$blocks = parse_blocks( $post_content );
		$blocks = array_map( array( $this, 'translate_block' ), $blocks );

		return serialize_blocks( $blocks );
	}

	/**
	 * Translate one block (recursing into inner blocks).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private function translate_block( array $block ): array {
		$name = $block['blockName'] ?? null;

		if ( is_string( $name ) && in_array( $name, self::SKIP_BLOCKS, true ) ) {
			return $block;
		}

		// Translatable attributes (e.g. image alt).
		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			foreach ( self::TRANSLATABLE_ATTRS as $attr ) {
				if ( isset( $block['attrs'][ $attr ] ) && is_string( $block['attrs'][ $attr ] ) && '' !== trim( $block['attrs'][ $attr ] ) ) {
					$block['attrs'][ $attr ] = $this->translate_text( $block['attrs'][ $attr ] );
				}
			}
		}

		$has_inner_blocks = ! empty( $block['innerBlocks'] );

		if ( $has_inner_blocks ) {
			// Container block: its innerContent chunks are partial wrapper markup
			// (no translatable text of their own). Translate the children only.
			$block['innerBlocks'] = array_map( array( $this, 'translate_block' ), $block['innerBlocks'] );

			return $block;
		}

		// Leaf block: innerContent is a single complete HTML fragment.
		$translated_html = '';

		foreach ( $block['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) ) {
				continue;
			}

			$block['innerContent'][ $index ] = $this->translate_html( $chunk );
			$translated_html                .= $block['innerContent'][ $index ];
		}

		$block['innerHTML'] = $translated_html;

		return $block;
	}

	/**
	 * Translate the text nodes of an HTML fragment, preserving its markup.
	 */
	private function translate_html( string $html ): string {
		// Skip only truly empty chunks; fragments may carry translatable text or
		// attributes (e.g. an image's alt) even with no visible text content.
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$dom = new DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );
		// The XML encoding hint forces UTF-8; the custom root avoids implied
		// <html>/<body> wrappers and collisions with real markup.
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><vaani-root>' . $html . '</vaani-root>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $dom->getElementsByTagName( 'vaani-root' )->item( 0 );
		if ( null === $root ) {
			return $html;
		}

		$this->translate_node( $root );

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Recursively translate text nodes and text-bearing attributes, skipping
	 * verbatim elements.
	 */
	private function translate_node( DOMNode $node ): void {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof DOMText ) {
				if ( '' !== trim( $child->nodeValue ) ) {
					$child->nodeValue = $this->translate_text( $child->nodeValue );
				}
				continue;
			}

			if ( in_array( strtolower( $child->nodeName ), self::VERBATIM_TAGS, true ) ) {
				continue;
			}

			if ( $child instanceof DOMElement ) {
				$this->translate_attributes( $child );
			}

			$this->translate_node( $child );
		}
	}

	/**
	 * Translate text-bearing attributes (alt, title) on an element in place.
	 */
	private function translate_attributes( DOMElement $element ): void {
		foreach ( self::TRANSLATABLE_HTML_ATTRS as $attr ) {
			if ( $element->hasAttribute( $attr ) && '' !== trim( $element->getAttribute( $attr ) ) ) {
				$element->setAttribute( $attr, $this->translate_text( $element->getAttribute( $attr ) ) );
			}
		}
	}

	/**
	 * Translate a single piece of text, with memoisation and chunking.
	 *
	 * @throws RuntimeException When the API call fails.
	 */
	private function translate_text( string $text ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		if ( isset( $this->memo[ $text ] ) ) {
			return $this->memo[ $text ];
		}

		$result = '';
		foreach ( $this->chunk( $text ) as $piece ) {
			$response = $this->client->translate_text( $piece, $this->source, $this->target );

			if ( ! $response->ok() ) {
				throw new RuntimeException( esc_html( $response->error() ) );
			}

			$result .= $response->text();
		}

		$this->memo[ $text ] = $result;

		return $result;
	}

	/**
	 * Split text into pieces within the model's per-request character limit,
	 * preferring sentence then whitespace boundaries.
	 *
	 * @return string[]
	 */
	private function chunk( string $text ): array {
		$limit = $this->client->max_input_chars();

		if ( mb_strlen( $text ) <= $limit ) {
			return array( $text );
		}

		// Split on sentence terminators (Latin + Devanagari danda), keeping them.
		$sentences = preg_split( '/(?<=[.!?।])\s+/u', $text ) ?: array( $text );

		$chunks  = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			// A single sentence longer than the limit: hard-split on whitespace.
			if ( mb_strlen( $sentence ) > $limit ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}
				foreach ( $this->hard_split( $sentence, $limit ) as $part ) {
					$chunks[] = $part;
				}
				continue;
			}

			if ( '' !== $current && mb_strlen( $current ) + mb_strlen( $sentence ) + 1 > $limit ) {
				$chunks[] = $current;
				$current  = '';
			}

			$current .= ( '' === $current ? '' : ' ' ) . $sentence;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Hard-split an over-long string on whitespace within the limit.
	 *
	 * @return string[]
	 */
	private function hard_split( string $text, int $limit ): array {
		$words   = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $text );
		$chunks  = array();
		$current = '';

		foreach ( $words as $word ) {
			if ( mb_strlen( $current ) + mb_strlen( $word ) > $limit && '' !== $current ) {
				$chunks[] = $current;
				$current  = '';
			}
			// A single token longer than the limit gets cut mid-token as a last resort.
			while ( mb_strlen( $word ) > $limit ) {
				$chunks[] = mb_substr( $word, 0, $limit );
				$word     = mb_substr( $word, $limit );
			}
			$current .= $word;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}
}
