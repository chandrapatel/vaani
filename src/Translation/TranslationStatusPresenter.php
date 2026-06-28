<?php
/**
 * Translation status as plain data for the editor sidebar and REST.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation;

use Vaani\Core\Hash;
use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use Vaani\Usage\UsageRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes per-language translation status into the JSON the Vaani sidebar renders.
 *
 * The single source of truth for that shape: the REST controller and the initial
 * localized payload both read from here, so the sidebar paints from one contract.
 * Values are returned raw (no HTML) — React escapes them on render.
 *
 * Per-post language selection was removed: every globally enabled target language
 * is offered on every post, mirroring the bulk-translate action.
 */
class TranslationStatusPresenter {

	/**
	 * @param Settings              $settings   Settings accessor (enabled languages).
	 * @param TranslationRepository $repository Translation storage.
	 * @param UsageRepository       $usage      Usage log, for per-post cost.
	 */
	public function __construct(
		private Settings $settings,
		private TranslationRepository $repository,
		private UsageRepository $usage
	) {}

	/**
	 * Status of every enabled target language for a source post, plus the cost line.
	 *
	 * @return array{languages: array<int, array<string, mixed>>, cost: array{operations:int, text:string}}
	 */
	public function for_source( int $source_id ): array {
		$languages = array();
		foreach ( $this->enabled_languages() as $code => $label ) {
			$languages[] = $this->row( $source_id, $code, $label );
		}

		return array(
			'languages' => $languages,
			'cost'      => $this->cost( $source_id ),
		);
	}

	/**
	 * Status for a single language, or null when it is not an enabled target.
	 *
	 * @return array<string, mixed>|null
	 */
	public function language( int $source_id, string $code ): ?array {
		$enabled = $this->enabled_languages();

		return isset( $enabled[ $code ] ) ? $this->row( $source_id, $code, $enabled[ $code ] ) : null;
	}

	/**
	 * Globally enabled target languages, as `code => label`, in registry order.
	 *
	 * @return array<string, string>
	 */
	private function enabled_languages(): array {
		$langs = array();
		foreach ( $this->settings->get_target_langs() as $code ) {
			$language = Registry::get( $code );
			if ( $language ) {
				$langs[ $code ] = $language->label();
			}
		}

		return $langs;
	}

	/**
	 * One language's status row.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $source_id, string $code, string $label ): array {
		$row = array(
			'code'    => $code,
			'label'   => $label,
			'status'  => 'none',
			'exists'  => false,
			'stale'   => false,
			'error'   => '',
			'editUrl' => '',
		);

		$translation = $this->repository->find( $source_id, $code );
		if ( ! $translation ) {
			return $row;
		}

		$row['exists'] = true;
		$status        = (string) get_post_meta( $translation->ID, TranslationPostType::META_STATUS, true );

		switch ( $status ) {
			case TranslationPostType::STATUS_PENDING:
				$row['status'] = 'pending';
				break;
			case TranslationPostType::STATUS_FAILED:
				$row['status'] = 'failed';
				$row['error']  = (string) get_post_meta( $translation->ID, TranslationPostType::META_ERROR, true );
				break;
			case TranslationPostType::STATUS_COMPLETED:
				$row['status'] = 'completed';
				$row['stale']  = $this->is_stale( $source_id, (int) $translation->ID );
				break;
		}

		$edit_url = get_edit_post_link( $translation->ID, 'raw' );
		if ( $edit_url ) {
			$row['editUrl'] = $edit_url;
		}

		return $row;
	}

	/**
	 * Whether the source content has changed since the translation was made.
	 */
	private function is_stale( int $source_id, int $translation_id ): bool {
		$stored = (string) get_post_meta( $translation_id, TranslationPostType::META_SOURCE_HASH, true );
		if ( '' === $stored ) {
			return false;
		}

		$source = get_post( $source_id );

		return $source instanceof WP_Post ? Hash::of( $source->post_content ) !== $stored : false;
	}

	/**
	 * One-line all-time Sarvam cost estimate for this post (preformatted).
	 *
	 * @return array{operations:int, text:string}
	 */
	private function cost( int $source_id ): array {
		$usage = $this->usage->source_summary( $source_id );

		if ( 0 === $usage['operations'] ) {
			return array(
				'operations' => 0,
				'text'       => '',
			);
		}

		$text = sprintf(
			/* translators: 1: estimated rupee amount, 2: character count. */
			__( 'This post: ~₹%1$s estimated · %2$s characters sent to Sarvam.', 'vaani' ),
			number_format_i18n( $usage['cost'], 2 ),
			number_format_i18n( $usage['units'] )
		);

		return array(
			'operations' => $usage['operations'],
			'text'       => $text,
		);
	}
}
