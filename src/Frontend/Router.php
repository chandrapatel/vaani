<?php
/**
 * Path-prefixed translation URLs (`/<lang>/<slug>/`).
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Frontend;

use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use WP;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Maps reader-facing `/<lang>/<original-slug>/` URLs onto the original post.
 *
 * The `vaani_translation` CPT stays private; this router never queries it by URL.
 * Instead it resolves the slug to the **source** post and loads that normally, so
 * the theme picks the right template — {@see ContentRenderer} then swaps the
 * translated title/content in. Owning both the rewrite rules and the URL builder
 * here keeps the URL scheme in one place ({@see self::url_for()}), reused by the
 * switcher and hreflang.
 */
class Router {

	/**
	 * Query var carrying the requested target language.
	 */
	public const QV_LANG = 'vaani_lang';

	/**
	 * Internal query var carrying the matched source path (consumed in parse_request).
	 */
	public const QV_PATH = 'vaani_path';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'resolve_request' ) );
		// A translated URL maps onto the source post's ID; without this WP would
		// "correct" /hi/about/ back to the canonical /about/.
		add_filter( 'redirect_canonical', array( $this, 'suppress_canonical_redirect' ) );
	}

	/**
	 * Register the `/<lang>/<slug>/` rewrite rule.
	 *
	 * Static so {@see \Vaani\Activator} can register the rule before flushing on
	 * activation, when the `init` hook may not have run for this plugin yet.
	 */
	public static function add_rewrite_rules(): void {
		$prefixes = self::prefixes();

		if ( empty( $prefixes ) ) {
			return;
		}

		$alternation = implode( '|', array_map( 'preg_quote', $prefixes ) );

		add_rewrite_rule(
			'^(' . $alternation . ')/(.+?)/?$',
			'index.php?' . self::QV_LANG . '=$matches[1]&' . self::QV_PATH . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Add our query vars so WordPress preserves them through the request.
	 *
	 * @param string[] $vars Registered public query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QV_LANG;
		$vars[] = self::QV_PATH;

		return $vars;
	}

	/**
	 * Resolve a `/<lang>/<slug>/` request to its source post.
	 *
	 * Rewrites the query so WordPress loads the **original** post by ID (correct
	 * template + theme context); {@see ContentRenderer} reads the surviving
	 * `vaani_lang` query var to swap in the translation. An unresolvable path is
	 * left untouched, so it 404s like any unknown URL.
	 *
	 * @param WP $wp Current request, passed by reference by `parse_request`.
	 */
	public function resolve_request( WP $wp ): void {
		$lang = $wp->query_vars[ self::QV_LANG ] ?? '';
		$path = $wp->query_vars[ self::QV_PATH ] ?? '';

		if ( '' === $lang || '' === $path || null === Registry::get( $lang ) ) {
			return;
		}

		$source = $this->resolve_source( (string) $path );

		if ( ! $source instanceof WP_Post ) {
			return;
		}

		$query_vars = array( self::QV_LANG => $lang );

		if ( 'page' === $source->post_type ) {
			$query_vars['page_id'] = $source->ID;
		} else {
			$query_vars['p'] = $source->ID;
		}

		$wp->query_vars = $query_vars;
	}

	/**
	 * Don't canonical-redirect a translated URL back to the original.
	 *
	 * @param string|false $redirect_url Proposed redirect URL.
	 * @return string|false False to cancel the redirect on translation requests.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( '' !== (string) get_query_var( self::QV_LANG ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Build the `/<lang>/<slug>/` URL for a source post in a target language.
	 */
	public static function url_for( WP_Post $source, string $lang ): string {
		return home_url( user_trailingslashit( $lang . '/' . self::source_path( $source ) ) );
	}

	/**
	 * The source post's path relative to the site root, without language prefix.
	 *
	 * Pages use their full nested URI (`parent/child`); posts use their slug, to
	 * match the `/<lang>/<slug>/` scheme regardless of the site's permalink base.
	 */
	private static function source_path( WP_Post $source ): string {
		if ( 'page' === $source->post_type ) {
			return get_page_uri( $source );
		}

		return $source->post_name;
	}

	/**
	 * Language codes usable as a URL prefix.
	 *
	 * Excludes any code that collides with an existing top-level page so a real
	 * page isn't shadowed by the translation router (CLAUDE.md seam #3).
	 *
	 * @return string[]
	 */
	private static function prefixes(): array {
		$codes = array_keys( Registry::all() );

		return array_values(
			array_filter(
				$codes,
				static function ( string $code ): bool {
					return ! ( get_page_by_path( $code ) instanceof WP_Post );
				}
			)
		);
	}

	/**
	 * Resolve a matched path to a published source post (page first, then post).
	 */
	private function resolve_source( string $path ): ?WP_Post {
		$page = get_page_by_path( $path, OBJECT, 'page' );

		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			return $page;
		}

		$segments = explode( '/', trim( $path, '/' ) );
		$slug     = (string) end( $segments );

		$posts = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		$post = $posts[0] ?? null;

		return $post instanceof WP_Post ? $post : null;
	}
}
