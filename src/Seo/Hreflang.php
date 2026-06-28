<?php
/**
 * hreflang alternate links for translated posts.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Seo;

use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use Vaani\Frontend\AvailableTranslations;
use Vaani\Frontend\Router;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Emits `<link rel="alternate" hreflang="…">` tags so search engines discover
 * each language a post is available in.
 *
 * Runs on both the original and translated views of a singular post (they share
 * the same alternate set), listing only published, non-stale translations via
 * {@see AvailableTranslations}. The original-language and `x-default` links both
 * point at the original permalink; translations point at their `/<lang>/<slug>/`
 * URLs. Yoast title/meta translation is a later phase — this only adds hreflang.
 */
class Hreflang {

	/**
	 * @param AvailableTranslations $available Renderable-translation resolver.
	 * @param Settings              $settings  Settings accessor (source language).
	 */
	public function __construct(
		private AvailableTranslations $available,
		private Settings $settings
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 1 );
	}

	/**
	 * Print the alternate links in `<head>`.
	 */
	public function render(): void {
		if ( ! is_singular( Settings::ALLOWED_POST_TYPES ) ) {
			return;
		}

		$source = get_post( get_queried_object_id() );

		if ( ! $source instanceof WP_Post ) {
			return;
		}

		$available = $this->available->for_source( $source->ID );

		if ( empty( $available ) ) {
			return;
		}

		$original_url = (string) get_permalink( $source );

		$this->print_link( $this->source_hreflang(), $original_url );

		foreach ( $available as $lang => $translation ) {
			$language = Registry::get( $lang );

			if ( null === $language ) {
				continue;
			}

			$this->print_link( $language->hreflang(), Router::url_for( $source, $lang ) );
		}

		$this->print_link( 'x-default', $original_url );
	}

	/**
	 * hreflang value for the original/source language.
	 */
	private function source_hreflang(): string {
		$source_lang = $this->settings->get_source_lang();
		$language    = Registry::get( $source_lang );

		return $language ? $language->hreflang() : $source_lang;
	}

	/**
	 * Output one alternate link tag.
	 */
	private function print_link( string $hreflang, string $url ): void {
		printf(
			'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
			esc_attr( $hreflang ),
			esc_url( $url )
		);
	}
}
