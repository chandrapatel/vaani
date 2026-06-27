<?php
/**
 * Translation orchestration.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation;

use Throwable;
use Vaani\Core\Hash;
use Vaani\Core\Language\Registry;
use Vaani\Core\Queue;
use Vaani\Core\Sarvam\Client;
use Vaani\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates translating a source post into one language: queues the work,
 * then (in the background) translates and stores the linked translation post.
 *
 * Enforces exactly one translation per (source, language) — CLAUDE.md seam #1 —
 * via a check-before-create in {@see self::queue()} plus a transient lock and a
 * repository lookup in {@see self::run()} so double-clicks and races cannot
 * create duplicates.
 */
class TranslationService {

	/**
	 * Action Scheduler hook for a queued translation job.
	 */
	public const HOOK = 'vaani_translate_post';

	/**
	 * Transient lock prefix guarding concurrent runs for the same (source, lang).
	 */
	private const LOCK_PREFIX = 'vaani_xlate_';

	/**
	 * @param TranslationRepository $repository Translation storage.
	 * @param Queue                 $queue      Background job queue.
	 * @param Settings              $settings   Settings accessor (API key, source lang).
	 */
	public function __construct(
		private TranslationRepository $repository,
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
	 * Queue a translation for a source post + language.
	 *
	 * Validates the language, dedupes against any already-queued job, marks the
	 * translation pending for immediate UI feedback, then enqueues the work.
	 *
	 * @return bool True if a job was queued, false if rejected or already queued.
	 */
	public function queue( int $source_id, string $lang ): bool {
		$source = get_post( $source_id );

		if ( ! $source || ! in_array( $source->post_type, Settings::ALLOWED_POST_TYPES, true ) ) {
			return false;
		}

		if ( null === Registry::get( $lang ) ) {
			return false;
		}

		$args = array( $source_id, $lang );

		if ( $this->queue->is_scheduled( self::HOOK, $args ) ) {
			return false;
		}

		$this->mark_pending( $source_id, $lang );

		return $this->queue->enqueue( self::HOOK, $args );
	}

	/**
	 * Background job: translate the source post and store the result.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Target language code.
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
			$source   = get_post( $source_id );
			$language = Registry::get( $lang );

			if ( ! $source || null === $language ) {
				return;
			}

			$source_code = Registry::sarvam_code( $this->settings->get_source_lang() ) ?? 'en-IN';
			$target_code = $language->sarvam_code();

			$translator = new BlockTranslator(
				new Client( $this->settings->get_api_key(), $this->settings->get_translation_config() )
			);

			$content = $translator->translate( $source->post_content, $source_code, $target_code );
			$title   = $translator->translate_string( $source->post_title, $source_code, $target_code );

			$payload = array(
				'title'        => $title,
				'content'      => $content,
				'status'       => 'publish',
				'vaani_status' => TranslationPostType::STATUS_COMPLETED,
				'source_hash'  => Hash::of( $source->post_content ),
				'slug'         => $source->post_name,
			);

			$existing = $this->repository->find( $source_id, $lang );

			if ( $existing ) {
				$this->repository->update( $existing->ID, $payload );
			} else {
				$this->repository->create( $source_id, $lang, $payload );
			}

			/**
			 * Fires after a successful Sarvam API operation, for usage logging.
			 *
			 * The `wp_vaani_usage` table and its listener are built in Phase 5;
			 * this is the forward-declared seam (CLAUDE.md §6 Phase 2, seam #4).
			 *
			 * @param string $operation Operation name (e.g. 'translate').
			 * @param string $lang      Target language code.
			 * @param int    $units     Billable units (characters) consumed.
			 * @param int    $source_id Source post ID.
			 */
			do_action( 'vaani_usage_logged', 'translate', $lang, mb_strlen( $source->post_content ), $source_id );
		} catch ( Throwable $e ) {
			$existing = $this->repository->find( $source_id, $lang );
			if ( $existing ) {
				$this->repository->set_status( $existing->ID, TranslationPostType::STATUS_FAILED );
			}
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Mark a translation pending, creating a placeholder post if none exists yet.
	 */
	private function mark_pending( int $source_id, string $lang ): void {
		$existing = $this->repository->find( $source_id, $lang );

		if ( $existing ) {
			$this->repository->set_status( $existing->ID, TranslationPostType::STATUS_PENDING );
			return;
		}

		$source = get_post( $source_id );

		$this->repository->create(
			$source_id,
			$lang,
			array(
				'title'        => $source ? $source->post_title : '',
				'content'      => '',
				'status'       => 'draft',
				'vaani_status' => TranslationPostType::STATUS_PENDING,
				'slug'         => $source ? $source->post_name : '',
			)
		);
	}
}
