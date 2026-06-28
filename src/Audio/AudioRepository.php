<?php
/**
 * Data access for per-language audio.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Audio;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that reads or writes a post's generated audio.
 *
 * Audio lives in the media library and is referenced by meta on the **original**
 * post (CLAUDE.md §2): one attachment per (source post × language), keyed by
 * language code. There is no custom table. Callers go through this repository so
 * the storage shape stays in one place.
 */
class AudioRepository {

	/**
	 * Original-post meta: `array<lang, attachment_id>`.
	 */
	public const META_AUDIO = '_vaani_audio';

	/**
	 * Original-post meta: `array<lang, source_hash>` for the stale badge. The hash
	 * tracks the *voiced* content — the translation's `post_content` for a target
	 * language, the original's for English (CLAUDE.md seam #5).
	 */
	public const META_HASH = '_vaani_audio_hash';

	/**
	 * Original-post meta: `array<lang, status>` lifecycle for editor feedback.
	 */
	public const META_STATUS = '_vaani_audio_status';

	/**
	 * Lifecycle status values for {@see self::META_STATUS}.
	 */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Attachment ID of a language's audio, or 0 if none.
	 */
	public function get_audio_id( int $source_id, string $lang ): int {
		$audio = $this->array_meta( $source_id, self::META_AUDIO );

		return isset( $audio[ $lang ] ) ? (int) $audio[ $lang ] : 0;
	}

	/**
	 * Every audio attachment ID linked to a source post, across all languages.
	 *
	 * Used by cleanup when the source is permanently deleted (CLAUDE.md §6).
	 *
	 * @return int[]
	 */
	public function all_audio_ids( int $source_id ): array {
		$audio = $this->array_meta( $source_id, self::META_AUDIO );

		$ids = array();
		foreach ( $audio as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id > 0 ) {
				$ids[] = $attachment_id;
			}
		}

		return $ids;
	}

	/**
	 * Public URL of a language's audio, or '' if none exists.
	 */
	public function get_audio_url( int $source_id, string $lang ): string {
		$id = $this->get_audio_id( $source_id, $lang );

		if ( 0 === $id ) {
			return '';
		}

		$url = wp_get_attachment_url( $id );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Lifecycle status for a language ('' when never generated).
	 */
	public function get_status( int $source_id, string $lang ): string {
		$status = $this->array_meta( $source_id, self::META_STATUS );

		return isset( $status[ $lang ] ) ? (string) $status[ $lang ] : '';
	}

	/**
	 * Set the lifecycle status for a language.
	 */
	public function set_status( int $source_id, string $lang, string $status ): void {
		$this->update_array_meta( $source_id, self::META_STATUS, $lang, $status );
	}

	/**
	 * Whether the voiced content has changed since the audio was generated.
	 *
	 * @param string $current_hash Hash of the content the audio would be voiced
	 *                             from now (translation or original).
	 */
	public function is_stale( int $source_id, string $lang, string $current_hash ): bool {
		$hashes = $this->array_meta( $source_id, self::META_HASH );
		$stored = isset( $hashes[ $lang ] ) ? (string) $hashes[ $lang ] : '';

		if ( '' === $stored ) {
			return false;
		}

		return $stored !== $current_hash;
	}

	/**
	 * Record a completed generation: attachment ID, content hash, status.
	 */
	public function set_meta( int $source_id, string $lang, int $attachment_id, string $hash ): void {
		$this->update_array_meta( $source_id, self::META_AUDIO, $lang, $attachment_id );
		$this->update_array_meta( $source_id, self::META_HASH, $lang, $hash );
		$this->set_status( $source_id, $lang, self::STATUS_COMPLETED );
	}

	/**
	 * Write a language's MP3 into the media library, overwriting in place.
	 *
	 * The filename is deterministic (`{post_type}-{source_id}-{lang}.mp3`) so a
	 * regeneration replaces the same file and reuses the same attachment ID
	 * rather than piling up duplicates (CLAUDE.md §2).
	 *
	 * @param string $mp3_bytes Decoded MP3 binary.
	 * @return int Attachment ID, or 0 on failure.
	 */
	public function save( int $source_id, string $lang, string $post_type, string $mp3_bytes ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename    = sanitize_file_name( "{$post_type}-{$source_id}-{$lang}.mp3" );
		$existing_id = $this->get_audio_id( $source_id, $lang );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return 0;
		}

		// Reuse the existing file path on regeneration; otherwise the current
		// uploads directory keyed by the deterministic filename.
		$path = $existing_id ? (string) get_attached_file( $existing_id ) : '';
		if ( '' === $path ) {
			$path = trailingslashit( $uploads['path'] ) . $filename;
		}

		if ( ! $this->write_file( $path, $mp3_bytes ) ) {
			return 0;
		}

		if ( $existing_id ) {
			wp_update_attachment_metadata( $existing_id, wp_generate_attachment_metadata( $existing_id, $path ) );

			return $existing_id;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'audio/mpeg',
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$path,
			$source_id,
			true
		);

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			return 0;
		}

		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $path ) );

		return (int) $attachment_id;
	}

	/**
	 * Write binary contents to a path via the WP filesystem API.
	 */
	private function write_file( string $path, string $bytes ): bool {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $bytes, FS_CHMOD_FILE );
	}

	/**
	 * Read an array-typed meta value, normalising non-arrays to `[]`.
	 *
	 * @return array<string, mixed>
	 */
	private function array_meta( int $source_id, string $key ): array {
		$value = get_post_meta( $source_id, $key, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Set one language entry within an array-typed meta value.
	 *
	 * @param mixed $value Entry value.
	 */
	private function update_array_meta( int $source_id, string $key, string $lang, $value ): void {
		$data          = $this->array_meta( $source_id, $key );
		$data[ $lang ] = $value;

		update_post_meta( $source_id, $key, $data );
	}
}
