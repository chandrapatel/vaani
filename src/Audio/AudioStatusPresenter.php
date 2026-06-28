<?php
/**
 * Audio status as plain data for the editor sidebar and REST.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Audio;

use Vaani\Core\Hash;
use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use Vaani\Frontend\AvailableTranslations;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes per-language audio status into the JSON the Vaani sidebar renders.
 *
 * Rows cover the English original plus every language the post already has a
 * published translation in — the only languages there is text to voice. Values
 * are returned raw (no HTML); React escapes them on render. The stale check hashes
 * the *voiced* content per language (CLAUDE.md seam #5).
 */
class AudioStatusPresenter {

	/**
	 * @param Settings              $settings   Settings accessor (source language).
	 * @param AvailableTranslations $available  Renderable-translation resolver.
	 * @param AudioRepository       $repository Audio storage.
	 */
	public function __construct(
		private Settings $settings,
		private AvailableTranslations $available,
		private AudioRepository $repository
	) {}

	/**
	 * Audio status of every eligible language for a source post.
	 *
	 * @return array{languages: array<int, array<string, mixed>>}
	 */
	public function for_source( int $source_id ): array {
		$languages = array();
		foreach ( $this->audio_languages( $source_id ) as $code => $label ) {
			$languages[] = $this->row( $source_id, $code, $label );
		}

		return array( 'languages' => $languages );
	}

	/**
	 * Status for a single language, or null when it is not audio-eligible.
	 *
	 * @return array<string, mixed>|null
	 */
	public function language( int $source_id, string $code ): ?array {
		$langs = $this->audio_languages( $source_id );

		return isset( $langs[ $code ] ) ? $this->row( $source_id, $code, $langs[ $code ] ) : null;
	}

	/**
	 * Languages eligible for audio: the source language (English) plus every
	 * language with a renderable translation, source first then registry order.
	 *
	 * @return array<string, string>
	 */
	private function audio_languages( int $source_id ): array {
		$langs = array( $this->settings->get_source_lang() => $this->original_label() );

		foreach ( array_keys( $this->available->for_source( $source_id ) ) as $code ) {
			$language = Registry::get( $code );
			if ( $language ) {
				$langs[ $code ] = $language->label();
			}
		}

		return $langs;
	}

	/**
	 * Label for the source (original) language.
	 */
	private function original_label(): string {
		$source_lang = $this->settings->get_source_lang();
		$language    = Registry::get( $source_lang );
		$label       = $language ? $language->label() : __( 'English', 'vaani' );

		return (string) apply_filters( 'vaani_original_language_label', $label, $source_lang );
	}

	/**
	 * One language's audio status row.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $source_id, string $code, string $label ): array {
		$status   = $this->repository->get_status( $source_id, $code );
		$audio_id = $this->repository->get_audio_id( $source_id, $code );

		$row = array(
			'code'   => $code,
			'label'  => $label,
			'status' => 'none',
			'exists' => $audio_id > 0,
			'stale'  => false,
			'url'    => '',
		);

		if ( AudioRepository::STATUS_PENDING === $status ) {
			$row['status'] = 'pending';
			return $row;
		}

		if ( AudioRepository::STATUS_FAILED === $status ) {
			$row['status'] = 'failed';
			return $row;
		}

		if ( 0 === $audio_id ) {
			return $row;
		}

		$row['status'] = 'completed';
		$row['stale']  = $this->repository->is_stale( $source_id, $code, $this->current_hash( $source_id, $code ) );
		$row['url']    = $this->repository->get_audio_url( $source_id, $code );

		return $row;
	}

	/**
	 * Hash of the content that would be voiced for a language right now — the
	 * original for English, the translation otherwise (CLAUDE.md seam #5).
	 */
	private function current_hash( int $source_id, string $lang ): string {
		if ( $lang === $this->settings->get_source_lang() ) {
			$source = get_post( $source_id );

			return $source instanceof WP_Post ? Hash::of( $source->post_content ) : '';
		}

		$translation = $this->available->renderable( $source_id, $lang );

		return $translation instanceof WP_Post ? Hash::of( $translation->post_content ) : '';
	}
}
