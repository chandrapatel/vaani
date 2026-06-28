<?php
/**
 * Audio (text-to-speech) orchestration.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Audio;

use RuntimeException;
use Throwable;
use Vaani\Core\Hash;
use Vaani\Core\Language\Registry;
use Vaani\Core\Queue;
use Vaani\Core\Sarvam\Client;
use Vaani\Core\Settings;
use Vaani\Translation\TranslationRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates generating spoken audio for one post in one language: queues the
 * work, then (in the background) synthesises and stores the MP3.
 *
 * The text comes from whatever is *voiced* in that language — the published
 * translation for a target language, or the original post for English (the
 * source language). Long content is chunked to the model's per-request limit and
 * the decoded MP3 segments are concatenated into a single file. A transient lock
 * plus a queue-dedupe check stop double-clicks and races from generating twice.
 */
class AudioService {

	/**
	 * Action Scheduler hook for a queued audio job.
	 */
	public const HOOK = 'vaani_generate_audio';

	/**
	 * Transient lock prefix guarding concurrent runs for the same (source, lang).
	 */
	private const LOCK_PREFIX = 'vaani_tts_';

	/**
	 * @param AudioRepository       $repository   Audio storage.
	 * @param TranslationRepository $translations Translation lookup (voiced text).
	 * @param Queue                 $queue        Background job queue.
	 * @param Settings              $settings     Settings accessor.
	 */
	public function __construct(
		private AudioRepository $repository,
		private TranslationRepository $translations,
		private Queue $queue,
		private Settings $settings
	) {}

	/**
	 * Register the background-job callback.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Queue audio generation for a source post + language.
	 *
	 * @return bool True if a job was queued, false if rejected or already queued.
	 */
	public function queue( int $source_id, string $lang ): bool {
		$source = get_post( $source_id );

		if ( ! $source instanceof WP_Post || ! in_array( $source->post_type, Settings::ALLOWED_POST_TYPES, true ) ) {
			return false;
		}

		if ( ! $this->is_audio_lang( $source_id, $lang ) ) {
			return false;
		}

		$args = array( $source_id, $lang );

		if ( $this->queue->is_scheduled( self::HOOK, $args ) ) {
			return false;
		}

		$this->repository->set_status( $source_id, $lang, AudioRepository::STATUS_PENDING );

		return $this->queue->enqueue( self::HOOK, $args );
	}

	/**
	 * Background job: synthesise the audio and store it.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Language code.
	 */
	public function run( $source_id, $lang ): void {
		$source_id = (int) $source_id;
		$lang      = (string) $lang;

		$lock = self::LOCK_PREFIX . $source_id . '_' . $lang;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$source = get_post( $source_id );

			if ( ! $source instanceof WP_Post || ! in_array( $source->post_type, Settings::ALLOWED_POST_TYPES, true ) ) {
				return;
			}

			$voiced      = $this->resolve_content( $source, $lang );
			$target_code = Registry::sarvam_code( $lang );

			if ( null === $voiced || null === $target_code ) {
				$this->repository->set_status( $source_id, $lang, AudioRepository::STATUS_FAILED );
				return;
			}

			$client = new Client( $this->settings->get_api_key(), $this->settings->get_audio_config() );
			$chunks = $this->chunk( $this->speech_text( $voiced['title'], $voiced['content'] ), $client->tts_max_input_chars() );

			$audio = '';
			$units = 0;

			foreach ( $chunks as $chunk ) {
				$response = $client->text_to_speech( $chunk, $target_code );

				if ( ! $response->ok() ) {
					throw new RuntimeException( $response->error() );
				}

				$decoded = base64_decode( $response->text(), true );

				if ( false === $decoded ) {
					throw new RuntimeException( 'Sarvam returned audio that could not be decoded.' );
				}

				$audio .= $decoded;
				$units += $response->units();
			}

			$attachment_id = '' === $audio ? 0 : $this->repository->save( $source_id, $lang, $source->post_type, $audio );

			if ( 0 === $attachment_id ) {
				throw new RuntimeException( 'Audio file could not be saved to the media library.' );
			}

			$this->repository->set_meta( $source_id, $lang, $attachment_id, Hash::of( $voiced['content'] ) );

			/**
			 * Fires after a successful Sarvam API operation, for usage logging.
			 *
			 * Shares the seam declared by the translation engine; the listener and
			 * `wp_vaani_usage` table are built in Phase 5 (CLAUDE.md §6).
			 *
			 * @param string $operation Operation name ('tts').
			 * @param string $lang      Language code.
			 * @param int    $units     Billable units (characters) consumed.
			 * @param int    $source_id Source post ID.
			 */
			do_action( 'vaani_usage_logged', 'tts', $lang, $units, $source_id );
		} catch ( Throwable $e ) {
			$this->repository->set_status( $source_id, $lang, AudioRepository::STATUS_FAILED );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Whether a language can have audio for this post: English (the source
	 * language) always qualifies; a target language needs a renderable translation.
	 */
	private function is_audio_lang( int $source_id, string $lang ): bool {
		if ( $lang === $this->settings->get_source_lang() ) {
			return true;
		}

		if ( null === Registry::get( $lang ) ) {
			return false;
		}

		return null !== $this->translations->find_renderable( $source_id, $lang );
	}

	/**
	 * Resolve the title + content to voice for a language.
	 *
	 * English uses the original post; a target language uses its published
	 * translation. Returns null when no voiceable content exists.
	 *
	 * @return array{title: string, content: string}|null
	 */
	private function resolve_content( WP_Post $source, string $lang ): ?array {
		if ( $lang === $this->settings->get_source_lang() ) {
			return array(
				'title'   => $source->post_title,
				'content' => $source->post_content,
			);
		}

		$translation = $this->translations->find_renderable( $source->ID, $lang );

		if ( ! $translation instanceof WP_Post ) {
			return null;
		}

		return array(
			'title'   => $translation->post_title,
			'content' => $translation->post_content,
		);
	}

	/**
	 * Reduce post title + block content to clean, speakable plain text.
	 */
	private function speech_text( string $title, string $content ): string {
		$plain = wp_strip_all_tags( do_blocks( $content ) );
		$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = (string) preg_replace( '/[ \t]+/', ' ', $plain );
		$plain = (string) preg_replace( '/\n{3,}/', "\n\n", $plain );

		$title = trim( wp_strip_all_tags( $title ) );
		$plain = trim( $plain );

		if ( '' === $title ) {
			return $plain;
		}

		return '' === $plain ? $title : $title . ".\n\n" . $plain;
	}

	/**
	 * Split text into chunks no longer than the model's per-request character cap,
	 * breaking on paragraph then sentence boundaries, hard-splitting only as a
	 * last resort.
	 *
	 * @return string[]
	 */
	private function chunk( string $text, int $max ): array {
		if ( '' === $text ) {
			return array();
		}

		if ( mb_strlen( $text ) <= $max ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';

		foreach ( preg_split( '/\n{2,}/', $text ) ?: array() as $paragraph ) {
			$paragraph = trim( (string) $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			$units = mb_strlen( $paragraph ) > $max
				? ( preg_split( '/(?<=[.!?।॥])\s+/u', $paragraph ) ?: array( $paragraph ) )
				: array( $paragraph );

			foreach ( $units as $unit ) {
				$unit = trim( (string) $unit );

				if ( '' === $unit ) {
					continue;
				}

				if ( mb_strlen( $unit ) > $max ) {
					if ( '' !== $current ) {
						$chunks[] = $current;
						$current  = '';
					}

					foreach ( $this->hard_split( $unit, $max ) as $piece ) {
						$chunks[] = $piece;
					}
					continue;
				}

				$candidate = '' === $current ? $unit : $current . "\n" . $unit;

				if ( mb_strlen( $candidate ) > $max ) {
					$chunks[] = $current;
					$current  = $unit;
				} else {
					$current = $candidate;
				}
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Break an over-long unit into fixed-size pieces as a final fallback.
	 *
	 * @return string[]
	 */
	private function hard_split( string $text, int $max ): array {
		$pieces = array();

		for ( $offset = 0, $length = mb_strlen( $text ); $offset < $length; $offset += $max ) {
			$pieces[] = mb_substr( $text, $offset, $max );
		}

		return $pieces;
	}
}
