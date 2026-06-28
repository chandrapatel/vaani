<?php
/**
 * Mirrors source-post trash/untrash/delete onto its linked translations.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation;

use Vaani\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps translation posts in lockstep with their source (CLAUDE.md §6):
 * trashing a source trashes its translations, untrashing restores them, and a
 * permanent delete removes them for good. Without this, deleting a source would
 * leave orphaned `vaani_translation` posts behind.
 *
 * Every handler guards on the affected post being a real source type so it never
 * recurses into the translation CPT or attachments.
 */
class TranslationCleanup {

	/**
	 * @param TranslationRepository $repository Translation storage.
	 */
	public function __construct(
		private TranslationRepository $repository
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_trash_post', array( $this, 'trash_translations' ) );
		add_action( 'untrashed_post', array( $this, 'restore_translations' ) );
		add_action( 'before_delete_post', array( $this, 'delete_translations' ) );
	}

	/**
	 * Trash the translations of a trashed source post.
	 *
	 * @param int $post_id Source post ID.
	 */
	public function trash_translations( $post_id ): void {
		if ( ! $this->is_source( (int) $post_id ) ) {
			return;
		}

		foreach ( $this->repository->find_all_for_source( (int) $post_id ) as $translation ) {
			wp_trash_post( $translation->ID );
		}
	}

	/**
	 * Restore the translations of an untrashed source post.
	 *
	 * @param int $post_id Source post ID.
	 */
	public function restore_translations( $post_id ): void {
		if ( ! $this->is_source( (int) $post_id ) ) {
			return;
		}

		foreach ( $this->repository->find_all_for_source( (int) $post_id ) as $translation ) {
			wp_untrash_post( $translation->ID );
		}
	}

	/**
	 * Permanently delete the translations of a deleted source post.
	 *
	 * @param int $post_id Source post ID.
	 */
	public function delete_translations( $post_id ): void {
		if ( ! $this->is_source( (int) $post_id ) ) {
			return;
		}

		foreach ( $this->repository->find_all_for_source( (int) $post_id ) as $translation ) {
			wp_delete_post( $translation->ID, true );
		}
	}

	/**
	 * Whether a post is a translatable source type (never the translation CPT).
	 */
	private function is_source( int $post_id ): bool {
		return in_array( get_post_type( $post_id ), Settings::ALLOWED_POST_TYPES, true );
	}
}
