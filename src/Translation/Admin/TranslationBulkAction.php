<?php
/**
 * "Translate selected" bulk action for the posts/pages list tables.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation\Admin;

use Vaani\Core\Settings;
use Vaani\Translation\TranslationService;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a bulk action that queues translations for every selected post into all
 * globally enabled target languages (CLAUDE.md §6).
 *
 * WordPress core verifies the list-table bulk nonce before
 * `handle_bulk_actions-*` fires; this class adds a per-post capability check and
 * reports queued/skipped counts via an admin notice.
 */
class TranslationBulkAction {

	/**
	 * Bulk action identifier.
	 */
	private const ACTION = 'vaani_translate_all';

	/**
	 * Redirect query arg carrying the queued-translation count.
	 */
	private const ARG_QUEUED = 'vaani_bulk_queued';

	/**
	 * Redirect query arg carrying the affected-post count.
	 */
	private const ARG_POSTS = 'vaani_bulk_posts';

	/**
	 * Redirect query arg carrying the skipped-post count.
	 */
	private const ARG_SKIPPED = 'vaani_bulk_skipped';

	/**
	 * @param Settings           $settings Settings accessor (enabled types + langs).
	 * @param TranslationService $service  Translation orchestration.
	 */
	public function __construct(
		private Settings $settings,
		private TranslationService $service
	) {}

	/**
	 * Register hooks for each enabled source post type.
	 */
	public function register(): void {
		foreach ( $this->enabled_post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle' ), 10, 3 );
		}

		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Post types that get the bulk action.
	 *
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		return array_values( array_intersect( Settings::ALLOWED_POST_TYPES, $this->settings->get_post_types() ) );
	}

	/**
	 * Add the bulk action to the dropdown.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public function add_action( array $actions ): array {
		$actions[ self::ACTION ] = __( 'Vaani: Translate to all enabled languages', 'vaani' );

		return $actions;
	}

	/**
	 * Queue translations for the selected posts.
	 *
	 * @param string $redirect_url Redirect URL after handling.
	 * @param string $action       Bulk action being handled.
	 * @param int[]  $post_ids     Selected post IDs.
	 * @return string Redirect URL with result counts appended.
	 */
	public function handle( string $redirect_url, string $action, array $post_ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect_url;
		}

		$langs   = $this->settings->get_target_langs();
		$queued  = 0;
		$posts   = 0;
		$skipped = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$skipped;
				continue;
			}

			$post_queued = 0;
			foreach ( $langs as $lang ) {
				if ( $this->service->queue( $post_id, $lang ) ) {
					++$post_queued;
				}
			}

			if ( $post_queued > 0 ) {
				$queued += $post_queued;
				++$posts;
			} else {
				++$skipped;
			}
		}

		return add_query_arg(
			array(
				self::ARG_QUEUED  => $queued,
				self::ARG_POSTS   => $posts,
				self::ARG_SKIPPED => $skipped,
			),
			$redirect_url
		);
	}

	/**
	 * Show the result notice after a bulk translate.
	 */
	public function render_notice(): void {
		if ( ! isset( $_GET[ self::ARG_QUEUED ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of counts; the action itself ran under core's bulk nonce.
			return;
		}

		$queued  = isset( $_GET[ self::ARG_QUEUED ] ) ? absint( wp_unslash( $_GET[ self::ARG_QUEUED ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$posts   = isset( $_GET[ self::ARG_POSTS ] ) ? absint( wp_unslash( $_GET[ self::ARG_POSTS ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET[ self::ARG_SKIPPED ] ) ? absint( wp_unslash( $_GET[ self::ARG_SKIPPED ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 0 === $queued ) {
			$message = __( 'Vaani: nothing was queued. Enable target languages in Vaani settings, then try again.', 'vaani' );
			$class   = 'notice-warning';
		} else {
			$message = sprintf(
				/* translators: 1: number of translations queued, 2: number of posts. */
				_n(
					'Vaani queued %1$s translation across %2$s post.',
					'Vaani queued %1$s translations across %2$s posts.',
					$queued,
					'vaani'
				),
				number_format_i18n( $queued ),
				number_format_i18n( $posts )
			);
			$class = 'notice-success';
		}

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of posts skipped. */
				_n( '%s post was skipped.', '%s posts were skipped.', $skipped, 'vaani' ),
				number_format_i18n( $skipped )
			);
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
