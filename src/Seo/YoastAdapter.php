<?php
/**
 * Yoast SEO meta translation + injection.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Seo;

use Vaani\Frontend\AvailableTranslations;
use Vaani\Frontend\Router;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that knows Yoast's meta keys (CLAUDE.md seam #6).
 *
 * On translation it copies the source post's manually-set Yoast title /
 * description / OG / Twitter fields onto the translation post (translated). On a
 * `/<lang>/` request it returns those stored values through Yoast's own output
 * filters, so the tags Yoast already emits carry the translated text — no
 * duplicate `<meta>` tags. Adding RankMath/SEOPress later means a new adapter,
 * not edits scattered across the codebase.
 *
 * Only manually-set fields are translated; template-derived titles fall back to
 * Yoast's original output.
 */
class YoastAdapter {

	/**
	 * Source/translation meta key => Yoast output filter that surfaces it.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_FILTERS = array(
		'_yoast_wpseo_title'                 => 'wpseo_title',
		'_yoast_wpseo_metadesc'              => 'wpseo_metadesc',
		'_yoast_wpseo_opengraph-title'       => 'wpseo_opengraph_title',
		'_yoast_wpseo_opengraph-description' => 'wpseo_opengraph_desc',
		'_yoast_wpseo_twitter-title'         => 'wpseo_twitter_title',
		'_yoast_wpseo_twitter-description'   => 'wpseo_twitter_description',
	);

	/**
	 * @param AvailableTranslations $available Renderable-translation resolver.
	 */
	public function __construct(
		private AvailableTranslations $available
	) {}

	/**
	 * Whether Yoast SEO is active.
	 */
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Register the front-end injection filters (only when Yoast is active).
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		foreach ( self::FIELD_FILTERS as $meta_key => $filter ) {
			add_filter(
				$filter,
				function ( $value ) use ( $meta_key ) {
					return $this->filter_value( (string) $value, $meta_key );
				}
			);
		}
	}

	/**
	 * The source post's manually-set Yoast fields (non-empty only).
	 *
	 * @return array<string, string> meta key => value
	 */
	public static function read_source( int $source_id ): array {
		$fields = array();

		foreach ( array_keys( self::FIELD_FILTERS ) as $meta_key ) {
			$value = (string) get_post_meta( $source_id, $meta_key, true );
			if ( '' !== trim( $value ) ) {
				$fields[ $meta_key ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Store translated Yoast fields on the translation post.
	 *
	 * @param array<string, string> $translated meta key => translated value
	 */
	public static function store( int $translation_id, array $translated ): void {
		foreach ( $translated as $meta_key => $value ) {
			if ( isset( self::FIELD_FILTERS[ $meta_key ] ) ) {
				update_post_meta( $translation_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Replace a Yoast output value with the translation's stored one on a
	 * `/<lang>/` request, when present.
	 */
	private function filter_value( string $value, string $meta_key ): string {
		$lang = (string) get_query_var( Router::QV_LANG );

		if ( '' === $lang || ! is_singular() ) {
			return $value;
		}

		$source = get_queried_object();

		if ( ! $source instanceof WP_Post ) {
			return $value;
		}

		$translation = $this->available->renderable( $source->ID, $lang );

		if ( ! $translation instanceof WP_Post ) {
			return $value;
		}

		$translated = (string) get_post_meta( $translation->ID, $meta_key, true );

		return '' !== trim( $translated ) ? $translated : $value;
	}
}
