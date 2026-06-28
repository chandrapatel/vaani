<?php
/**
 * Deletes a source post's generated audio when the post is deleted.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Audio;

use Vaani\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the media-library attachments a post's audio referenced when the post
 * is permanently deleted (CLAUDE.md §6). The `_vaani_audio*` meta lives on the
 * source post and is removed with it; only the separate attachment posts need an
 * explicit delete. Trashing is not mirrored — audio attachments are plain media
 * that stay valid while the source sits in the trash.
 */
class AudioCleanup {

	/**
	 * @param AudioRepository $repository Audio storage.
	 */
	public function __construct(
		private AudioRepository $repository
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'delete_audio' ) );
	}

	/**
	 * Delete the audio attachments of a deleted source post.
	 *
	 * @param int $post_id Source post ID.
	 */
	public function delete_audio( $post_id ): void {
		$post_id = (int) $post_id;

		if ( ! in_array( get_post_type( $post_id ), Settings::ALLOWED_POST_TYPES, true ) ) {
			return;
		}

		foreach ( $this->repository->all_audio_ids( $post_id ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}
