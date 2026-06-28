<?php
/**
 * Data access for translation posts.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that queries or writes `vaani_translation` posts.
 *
 * Callers go through this repository rather than touching `WP_Query`/`wpdb`
 * directly, so the storage shape can change in one place. Translations link to
 * their source via meta only — never `post_parent` (CLAUDE.md §2).
 */
class TranslationRepository {

	/**
	 * Find the translation for a given source post and language, if any.
	 *
	 * Matches any post status (a translation may be draft, pending, etc.) so the
	 * uniqueness invariant holds regardless of publication state.
	 */
	public function find( int $source_id, string $lang ): ?WP_Post {
		$query = new \WP_Query(
			array(
				'post_type'              => TranslationPostType::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => TranslationPostType::META_SOURCE_ID,
						'value' => $source_id,
					),
					array(
						'key'   => TranslationPostType::META_LANG,
						'value' => $lang,
					),
				),
			)
		);

		$post = $query->posts[0] ?? null;

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Find a front-end-renderable translation for a source post and language.
	 *
	 * Stricter than {@see self::find()}: only a published post whose lifecycle
	 * status is `completed` qualifies, so drafts, pending placeholders, and
	 * failed jobs are never served to readers. Staleness is intentionally not
	 * considered — a stale translation still renders for readers and is flagged
	 * only in the editor (see {@see \Vaani\Frontend\AvailableTranslations}).
	 */
	public function find_renderable( int $source_id, string $lang ): ?WP_Post {
		$query = new \WP_Query(
			array(
				'post_type'              => TranslationPostType::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => TranslationPostType::META_SOURCE_ID,
						'value' => $source_id,
					),
					array(
						'key'   => TranslationPostType::META_LANG,
						'value' => $lang,
					),
					array(
						'key'   => TranslationPostType::META_STATUS,
						'value' => TranslationPostType::STATUS_COMPLETED,
					),
				),
			)
		);

		$post = $query->posts[0] ?? null;

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Create a translation post linked to a source.
	 *
	 * @param array<string, mixed> $data {
	 *     @type string $title        Post title.
	 *     @type string $content      Translated post_content.
	 *     @type string $status       wp post_status (default 'draft').
	 *     @type string $source_hash  Source content hash at translation time.
	 *     @type string $vaani_status Lifecycle status (pending|completed|failed).
	 *     @type string $slug         Source slug (mirrored into translated-slug meta).
	 * }
	 * @return int New post ID, or 0 on failure.
	 */
	public function create( int $source_id, string $lang, array $data ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => TranslationPostType::POST_TYPE,
				'post_status'  => $data['status'] ?? 'draft',
				'post_title'   => $data['title'] ?? '',
				'post_content' => $data['content'] ?? '',
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, TranslationPostType::META_SOURCE_ID, $source_id );
		update_post_meta( $post_id, TranslationPostType::META_LANG, $lang );

		$this->apply_meta( (int) $post_id, $data );

		return (int) $post_id;
	}

	/**
	 * Update an existing translation post's content and meta.
	 *
	 * @param array<string, mixed> $data See {@see self::create()}.
	 */
	public function update( int $post_id, array $data ): void {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_status'  => $data['status'] ?? 'draft',
				'post_title'   => $data['title'] ?? '',
				'post_content' => $data['content'] ?? '',
			)
		);

		$this->apply_meta( $post_id, $data );
	}

	/**
	 * Update only the lifecycle status meta, leaving content untouched.
	 *
	 * Used to flag a translation pending/failed without rewriting its post.
	 */
	public function set_status( int $post_id, string $vaani_status ): void {
		update_post_meta( $post_id, TranslationPostType::META_STATUS, $vaani_status );
	}

	/**
	 * Write the status/hash/slug meta common to create and update.
	 *
	 * @param array<string, mixed> $data See {@see self::create()}.
	 */
	private function apply_meta( int $post_id, array $data ): void {
		if ( isset( $data['vaani_status'] ) ) {
			update_post_meta( $post_id, TranslationPostType::META_STATUS, (string) $data['vaani_status'] );
		}

		if ( isset( $data['source_hash'] ) ) {
			update_post_meta( $post_id, TranslationPostType::META_SOURCE_HASH, (string) $data['source_hash'] );
		}

		if ( isset( $data['slug'] ) ) {
			update_post_meta( $post_id, TranslationPostType::META_TRANSLATED_SLUG, (string) $data['slug'] );
		}
	}
}
