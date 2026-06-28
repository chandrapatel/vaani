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
 * Translates `post_content` while preserving block markup and attributes.
 *
 * Earlier the translator sent one text node per API call, which fragmented
 * sentences split by inline markup (a `<strong>` mid-sentence became three
 * separate requests). With no surrounding context Mayura could not resolve
 * speaker gender or word sense, so it drifted to its default gender and lost
 * accuracy.
 *
 * This version translates **whole units** and batches them:
 *
 * 1. Each leaf block's HTML fragment is converted to one *masked* string —
 *    visible text kept intact, every tag replaced by an inert placeholder
 *    token (CAT-tool style). Mayura sees the full sentence, with its inline
 *    formatting reduced to opaque tokens it copies through verbatim.
 * 2. Consecutive fragments are packed into a single request up to the model's
 *    character budget, joined by a sentinel separator, then split back.
 * 3. Two fallbacks guarantee output never breaks: if the separator does not
 *    survive translation the batch is retranslated one fragment at a time; if a
 *    fragment's tag tokens do not survive, that fragment falls back to the old
 *    node-by-node path ({@see self::translate_html_nodewise()}).
 *
 * The HTTP call lives in {@see Client}; this class owns the block/HTML walking
 * (CLAUDE.md §3). Non-translatable blocks (code, HTML, embeds, shortcodes,
 * preformatted) pass through untouched. Within translatable blocks, only text
 * and a small allowlist of attributes (`alt`, `title`) are translated.
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
	 * Block attribute keys (in the block's JSON attrs) that hold translatable
	 * text.
	 *
	 * @var string[]
	 */
	private const TRANSLATABLE_ATTRS = array( 'alt' );

	/**
	 * HTML element names whose text content must never be translated. Their
	 * whole markup is captured as a single opaque token.
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
	 * HTML void elements: rendered without a closing tag.
	 *
	 * @var string[]
	 */
	private const VOID_TAGS = array(
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	);

	/**
	 * First code point used for inline-tag placeholder tokens. Each tag becomes
	 * one unique code point from the Supplementary Private-Use Area — a single
	 * opaque character with no digit or letter for the model to transliterate,
	 * so tokens survive translation intact.
	 */
	private const TAG_BASE = 0xF0000;

	/**
	 * Separates fragments inside a batched request.
	 */
	private const SEG_SENTINEL = "\u{E001}";

	/**
	 * Wraps the marker left in the block tree in place of a translatable chunk;
	 * resolved to the translated HTML in the apply pass.
	 */
	private const MARKER_SENTINEL = "\u{E002}";

	/**
	 * Per-fragment store. Keyed by integer id; each entry holds the masked
	 * string, its token map, the original HTML (for fallback), whether it is a
	 * plain-text segment, and the eventual result.
	 *
	 * @var array<int, array{masked: ?string, tokens: array<string, string>, orig: string, plain: bool, result: ?string}>
	 */
	private array $fragments = array();

	/**
	 * Next fragment id.
	 */
	private int $next_id = 0;

	/**
	 * Per-run cache of `text => translation` for plain strings (titles, attrs).
	 *
	 * @var array<string, string>
	 */
	private array $plain_memo = array();

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

		return $this->translate_plain( $text );
	}

	/**
	 * Translate block-editor `post_content`.
	 *
	 * Runs in three passes: collect (replace each translatable chunk with a
	 * marker and record a fragment), translate (batch + translate the
	 * fragments), apply (swap markers for the translated HTML).
	 *
	 * @param string $post_content Source post_content.
	 * @param string $source_code  Sarvam source code (e.g. `en-IN`).
	 * @param string $target_code  Sarvam target code (e.g. `hi-IN`).
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	public function translate( string $post_content, string $source_code, string $target_code ): string {
		$this->source     = $source_code;
		$this->target     = $target_code;
		$this->fragments  = array();
		$this->next_id    = 0;
		$this->plain_memo = array();

		$blocks = parse_blocks( $post_content );

		$blocks = array_map( array( $this, 'collect_block' ), $blocks );
		$this->translate_fragments();
		$blocks = array_map( array( $this, 'apply_block' ), $blocks );

		return serialize_blocks( $blocks );
	}

	/**
	 * Pass 1: replace translatable text in a block with markers, recording a
	 * fragment for each (recursing into inner blocks).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private function collect_block( array $block ): array {
		$name = $block['blockName'] ?? null;

		if ( is_string( $name ) && in_array( $name, self::SKIP_BLOCKS, true ) ) {
			return $block;
		}

		// Translatable block-JSON attributes (e.g. image alt).
		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			foreach ( self::TRANSLATABLE_ATTRS as $attr ) {
				if ( isset( $block['attrs'][ $attr ] ) && is_string( $block['attrs'][ $attr ] ) && '' !== trim( $block['attrs'][ $attr ] ) ) {
					$block['attrs'][ $attr ] = $this->register_text( $block['attrs'][ $attr ] );
				}
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			// Container block: its own innerContent chunks are partial wrapper
			// markup with no translatable text. Recurse into the children only.
			$block['innerBlocks'] = array_map( array( $this, 'collect_block' ), $block['innerBlocks'] );

			return $block;
		}

		// Leaf block: each innerContent string is a complete HTML fragment.
		foreach ( $block['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) || '' === trim( $chunk ) ) {
				continue;
			}

			$block['innerContent'][ $index ] = $this->register_fragment( $chunk );
		}

		return $block;
	}

	/**
	 * Pass 3: swap markers for their translated HTML (recursing into inner
	 * blocks) and rebuild leaf innerHTML.
	 *
	 * @param array<string, mixed> $block Parsed block (with markers).
	 * @return array<string, mixed>
	 */
	private function apply_block( array $block ): array {
		$name = $block['blockName'] ?? null;

		if ( is_string( $name ) && in_array( $name, self::SKIP_BLOCKS, true ) ) {
			return $block;
		}

		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			foreach ( self::TRANSLATABLE_ATTRS as $attr ) {
				if ( isset( $block['attrs'][ $attr ] ) && is_string( $block['attrs'][ $attr ] ) ) {
					$id = $this->resolve_marker( $block['attrs'][ $attr ] );
					if ( null !== $id ) {
						$block['attrs'][ $attr ] = (string) $this->fragments[ $id ]['result'];
					}
				}
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = array_map( array( $this, 'apply_block' ), $block['innerBlocks'] );

			return $block;
		}

		$inner_html = '';

		foreach ( $block['innerContent'] as $index => $chunk ) {
			if ( is_string( $chunk ) ) {
				$id = $this->resolve_marker( $chunk );
				if ( null !== $id ) {
					$block['innerContent'][ $index ] = (string) $this->fragments[ $id ]['result'];
				}
				$inner_html .= $block['innerContent'][ $index ];
			}
		}

		$block['innerHTML'] = $inner_html;

		return $block;
	}

	/**
	 * Register a plain-text segment (no markup) and return its marker.
	 */
	private function register_text( string $text ): string {
		$id = $this->next_id++;

		$this->fragments[ $id ] = array(
			'masked' => $text,
			'tokens' => array(),
			'orig'   => $text,
			'plain'  => true,
			'result' => null,
		);

		return $this->marker( $id );
	}

	/**
	 * Register an HTML fragment and return its marker.
	 *
	 * Builds the masked form now (which also translates any in-markup `alt`/
	 * `title` attributes). A fragment with no body text is resolved immediately;
	 * one whose markup could not be parsed is flagged for the node-by-node
	 * fallback.
	 */
	private function register_fragment( string $html ): string {
		$id    = $this->next_id++;
		$built = $this->build_masked( $html );

		if ( null === $built ) {
			$this->fragments[ $id ] = array(
				'masked' => null,
				'tokens' => array(),
				'orig'   => $html,
				'plain'  => false,
				'result' => null,
			);

			return $this->marker( $id );
		}

		list( $masked, $tokens, $has_text ) = $built;

		$record = array(
			'masked' => $masked,
			'tokens' => $tokens,
			'orig'   => $html,
			'plain'  => false,
			'result' => null,
		);

		// Nothing to translate (e.g. an image-only fragment); its attributes are
		// already translated inside the tokens, so reconstruct it now.
		if ( ! $has_text ) {
			$record['result'] = $this->unmask( $masked, $tokens );
			$record['masked'] = null;
		}

		$this->fragments[ $id ] = $record;

		return $this->marker( $id );
	}

	/**
	 * Pass 2: translate every pending fragment, batching consecutive ones up to
	 * the model's character budget.
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	private function translate_fragments(): void {
		$pending = array();

		foreach ( $this->fragments as $id => $fragment ) {
			if ( null !== $fragment['result'] ) {
				continue;
			}

			if ( null === $fragment['masked'] ) {
				// Unparseable markup: safe node-by-node fallback.
				$this->fragments[ $id ]['result'] = $this->translate_html_nodewise( $fragment['orig'] );
				continue;
			}

			$pending[] = $id;
		}

		$budget    = max( 1, $this->client->max_input_chars() );
		$separator = ' ' . self::SEG_SENTINEL . ' ';
		$sep_len   = mb_strlen( $separator );

		$count = count( $pending );
		$i     = 0;

		while ( $i < $count ) {
			$batch = array();
			$len   = 0;

			while ( $i < $count ) {
				$piece_len = mb_strlen( (string) $this->fragments[ $pending[ $i ] ]['masked'] );

				if ( empty( $batch ) ) {
					$batch[] = $pending[ $i ];
					$len     = $piece_len;
					$i++;
					// An oversize fragment stands alone; translate_one() chunks it.
					if ( $piece_len >= $budget ) {
						break;
					}
					continue;
				}

				if ( $len + $sep_len + $piece_len <= $budget ) {
					$batch[] = $pending[ $i ];
					$len    += $sep_len + $piece_len;
					$i++;
				} else {
					break;
				}
			}

			$this->translate_batch( $batch, $separator );
		}
	}

	/**
	 * Translate one batch of fragment ids, splitting the joined result back.
	 *
	 * @param int[]  $ids       Fragment ids in the batch.
	 * @param string $separator Sentinel separator joining the masked strings.
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	private function translate_batch( array $ids, string $separator ): void {
		if ( count( $ids ) === 1 ) {
			$id = $ids[0];
			$this->finalize_fragment( $id, $this->translate_one( (string) $this->fragments[ $id ]['masked'] ) );

			return;
		}

		$masked = array();
		foreach ( $ids as $id ) {
			$masked[] = (string) $this->fragments[ $id ]['masked'];
		}

		$translated = $this->translate_one( implode( $separator, $masked ) );
		$parts      = preg_split( '/\s*' . preg_quote( self::SEG_SENTINEL, '/' ) . '\s*/u', $translated );

		// Separator survived intact: map parts back 1:1.
		if ( is_array( $parts ) && count( $parts ) === count( $ids ) ) {
			foreach ( $ids as $k => $id ) {
				$this->finalize_fragment( $id, trim( $parts[ $k ] ) );
			}

			return;
		}

		// Fallback: translate each fragment on its own (still whole-unit).
		foreach ( $ids as $id ) {
			$this->finalize_fragment( $id, $this->translate_one( (string) $this->fragments[ $id ]['masked'] ) );
		}
	}

	/**
	 * Store a fragment's result, unmasking tags and verifying token survival.
	 *
	 * @param int    $id         Fragment id.
	 * @param string $translated Translated masked string.
	 */
	private function finalize_fragment( int $id, string $translated ): void {
		$fragment = $this->fragments[ $id ];

		if ( $fragment['plain'] ) {
			$this->fragments[ $id ]['result'] = $translated;

			return;
		}

		foreach ( array_keys( $fragment['tokens'] ) as $token ) {
			if ( false === mb_strpos( $translated, (string) $token ) ) {
				// A tag token was dropped/altered: fall back to node-by-node so
				// markup is never corrupted.
				$this->fragments[ $id ]['result'] = $this->translate_html_nodewise( $fragment['orig'] );

				return;
			}
		}

		$this->fragments[ $id ]['result'] = $this->unmask( $translated, $fragment['tokens'] );
	}

	/**
	 * Translate one masked string, chunking only if it exceeds the budget.
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	private function translate_one( string $masked ): string {
		if ( '' === trim( $masked ) ) {
			return $masked;
		}

		if ( mb_strlen( $masked ) <= $this->client->max_input_chars() ) {
			return $this->client_translate( $masked );
		}

		$out = '';
		foreach ( $this->chunk( $masked ) as $piece ) {
			$out .= $this->client_translate( $piece );
		}

		return $out;
	}

	/**
	 * Translate a single piece via the client, surfacing API failures.
	 *
	 * @throws RuntimeException When the API call fails.
	 */
	private function client_translate( string $text ): string {
		$response = $this->client->translate_text( $text, $this->source, $this->target );

		if ( ! $response->ok() ) {
			throw new RuntimeException( esc_html( $response->error() ) );
		}

		return $response->text();
	}

	/**
	 * Translate a plain string (title, attribute, fallback text node), with
	 * memoisation and chunking.
	 *
	 * @throws RuntimeException When the API call fails.
	 */
	private function translate_plain( string $text ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		if ( isset( $this->plain_memo[ $text ] ) ) {
			return $this->plain_memo[ $text ];
		}

		$result = $this->translate_one( $text );

		$this->plain_memo[ $text ] = $result;

		return $result;
	}

	/**
	 * Build the masked form of an HTML fragment.
	 *
	 * @return array{0: string, 1: array<string, string>, 2: bool}|null
	 *               [ masked text, token => markup map, has translatable text ],
	 *               or null when the fragment could not be parsed.
	 */
	private function build_masked( string $html ): ?array {
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
			return null;
		}

		$tokens   = array();
		$counter  = 0;
		$has_text = false;

		$masked = $this->mask_node( $root, $dom, $tokens, $counter, $has_text );

		return array( $masked, $tokens, $has_text );
	}

	/**
	 * Recursively mask an element's children: text is kept, each tag becomes an
	 * opaque token, verbatim elements become a single token.
	 *
	 * @param DOMNode               $node     Node to walk.
	 * @param DOMDocument           $dom      Owner document (for serialisation).
	 * @param array<string, string> $tokens   Token => markup map (by reference).
	 * @param int                   $counter  Token counter (by reference).
	 * @param bool                  $has_text Set true when translatable text is found (by reference).
	 */
	private function mask_node( DOMNode $node, DOMDocument $dom, array &$tokens, int &$counter, bool &$has_text ): string {
		$out = '';

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof DOMText ) {
				$out .= $child->nodeValue;
				if ( '' !== trim( $child->nodeValue ) ) {
					$has_text = true;
				}
				continue;
			}

			if ( ! ( $child instanceof DOMElement ) ) {
				// Comments / processing instructions: keep verbatim.
				$token            = $this->make_token( $counter );
				$tokens[ $token ] = (string) $dom->saveHTML( $child );
				$out             .= $token;
				continue;
			}

			$name = strtolower( $child->nodeName );

			if ( in_array( $name, self::VERBATIM_TAGS, true ) ) {
				$token            = $this->make_token( $counter );
				$tokens[ $token ] = (string) $dom->saveHTML( $child );
				$out             .= $token;
				continue;
			}

			$open            = $this->make_token( $counter );
			$tokens[ $open ] = $this->open_tag_html( $child );
			$out            .= $open;

			$out .= $this->mask_node( $child, $dom, $tokens, $counter, $has_text );

			if ( ! in_array( $name, self::VOID_TAGS, true ) ) {
				$close            = $this->make_token( $counter );
				$tokens[ $close ] = '</' . $name . '>';
				$out             .= $close;
			}
		}

		return $out;
	}

	/**
	 * Serialise an element's opening tag, translating `alt`/`title` in place.
	 *
	 * @throws RuntimeException When an attribute translation API call fails.
	 */
	private function open_tag_html( DOMElement $element ): string {
		$html = '<' . strtolower( $element->nodeName );

		if ( $element->hasAttributes() ) {
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$attr_name  = $attribute->name;
				$attr_value = $attribute->value;

				if ( in_array( $attr_name, self::TRANSLATABLE_HTML_ATTRS, true ) && '' !== trim( $attr_value ) ) {
					$attr_value = $this->translate_plain( $attr_value );
				}

				$html .= ' ' . $attr_name . '="' . htmlspecialchars( $attr_value, ENT_QUOTES, 'UTF-8' ) . '"';
			}
		}

		return $html . '>';
	}

	/**
	 * Reconstruct an HTML fragment from a translated masked string: escape the
	 * text, then restore tag markup from tokens.
	 *
	 * @param array<string, string> $tokens Token => markup map.
	 */
	private function unmask( string $masked, array $tokens ): string {
		// Escape body text only; tokens are private-use code points untouched by
		// htmlspecialchars, and their markup is already well-formed.
		$escaped = htmlspecialchars( $masked, ENT_NOQUOTES, 'UTF-8' );

		return strtr( $escaped, $tokens );
	}

	/**
	 * Build the next inline-tag token: one unique Private-Use code point.
	 *
	 * @param int $counter Token counter (by reference).
	 */
	private function make_token( int &$counter ): string {
		return mb_chr( self::TAG_BASE + $counter++, 'UTF-8' );
	}

	/**
	 * Build a block-tree marker for a fragment id.
	 */
	private function marker( int $id ): string {
		return self::MARKER_SENTINEL . $id . self::MARKER_SENTINEL;
	}

	/**
	 * Extract a fragment id from a string that is exactly a marker.
	 *
	 * @return int|null The id, or null when the string is not a marker.
	 */
	private function resolve_marker( string $value ): ?int {
		$sentinel = preg_quote( self::MARKER_SENTINEL, '/' );

		if ( preg_match( '/^' . $sentinel . '(\d+)' . $sentinel . '$/u', $value, $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	/**
	 * Fallback: translate the text nodes of an HTML fragment one at a time,
	 * preserving markup exactly. Used when tag tokens do not survive a batched
	 * translation, so markup is never corrupted.
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	private function translate_html_nodewise( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$dom = new DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );
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
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	private function translate_node( DOMNode $node ): void {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof DOMText ) {
				if ( '' !== trim( $child->nodeValue ) ) {
					$child->nodeValue = $this->translate_plain( $child->nodeValue );
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
	 *
	 * @throws RuntimeException When any API call fails.
	 */
	private function translate_attributes( DOMElement $element ): void {
		foreach ( self::TRANSLATABLE_HTML_ATTRS as $attr ) {
			if ( $element->hasAttribute( $attr ) && '' !== trim( $element->getAttribute( $attr ) ) ) {
				$element->setAttribute( $attr, $this->translate_plain( $element->getAttribute( $attr ) ) );
			}
		}
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
