<?php
/**
 * Private translation custom post type.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the private `vaani_translation` CPT — one post per
 * (source post × language) — and its linking meta.
 *
 * Storage is private (hidden from search/feeds/sitemaps, no front-end query);
 * translations are *rendered* publicly later at path-prefixed URLs (Phase 3).
 * `show_in_rest` stays true so each translation opens in the block editor and
 * edits as native Gutenberg blocks. The relationship to the source post lives
 * entirely in meta — never `post_parent` (CLAUDE.md §2).
 */
class TranslationPostType {

	/**
	 * Post type key.
	 */
	public const POST_TYPE = 'vaani_translation';

	/**
	 * Source post ID this translation belongs to.
	 */
	public const META_SOURCE_ID = '_vaani_source_id';

	/**
	 * Target language code (internal code, e.g. `hi`).
	 */
	public const META_LANG = '_vaani_lang';

	/**
	 * Hash of the source `post_content` at translation time (staleness).
	 */
	public const META_SOURCE_HASH = '_vaani_source_hash';

	/**
	 * Translation lifecycle status: pending | completed | failed.
	 */
	public const META_STATUS = '_vaani_status';

	/**
	 * Per-translation slug. Stored but unused in v1 (CLAUDE.md seam #3): v1
	 * mirrors the source slug, so persisting this now makes translated slugs
	 * additive later instead of a data migration.
	 */
	public const META_TRANSLATED_SLUG = '_vaani_translated_slug';

	/**
	 * Status values for {@see self::META_STATUS}.
	 */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'exclude_from_sitemaps' ) );
	}

	/**
	 * Register the private CPT.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Translations', 'vaani' ),
					'singular_name' => __( 'Translation', 'vaani' ),
					'menu_name'     => __( 'Vaani Translations', 'vaani' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-translation',
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Register the CPT's linking and status meta over the REST API.
	 */
	public function register_meta(): void {
		$auth = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		$string_meta = array(
			self::META_LANG,
			self::META_SOURCE_HASH,
			self::META_STATUS,
			self::META_TRANSLATED_SLUG,
		);

		foreach ( $string_meta as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $auth,
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_SOURCE_ID,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * Keep the private CPT out of core sitemaps.
	 *
	 * @param array<string, \WP_Post_Type> $post_types Sitemap-eligible types.
	 * @return array<string, \WP_Post_Type>
	 */
	public function exclude_from_sitemaps( array $post_types ): array {
		unset( $post_types[ self::POST_TYPE ] );

		return $post_types;
	}
}
