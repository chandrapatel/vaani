<?php
/**
 * Per-post translation status & trigger meta box.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation\Admin;

use Vaani\Core\Hash;
use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use Vaani\Translation\Admin\LanguagePanel;
use Vaani\Translation\TranslationPostType;
use Vaani\Translation\TranslationRepository;
use Vaani\Translation\TranslationService;
use Vaani\Usage\UsageRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Vaani Translations" meta box to the editor for enabled post types,
 * showing per-language status, an edit link, a stale badge, and a Translate-now
 * button that queues a background translation over AJAX.
 */
class TranslationMetaBox {

	/**
	 * AJAX action for queueing a translation.
	 */
	private const AJAX_ACTION = 'vaani_translate';

	/**
	 * @param Settings              $settings        Settings accessor.
	 * @param TranslationRepository $repository      Translation storage.
	 * @param TranslationService    $service         Translation orchestration.
	 * @param UsageRepository       $usageRepository Usage log, for per-post cost.
	 */
	public function __construct(
		private Settings $settings,
		private TranslationRepository $repository,
		private TranslationService $service,
		private UsageRepository $usageRepository
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_translate' ) );
	}

	/**
	 * Post types that get the meta box.
	 *
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		return array_values( array_intersect( Settings::ALLOWED_POST_TYPES, $this->settings->get_post_types() ) );
	}

	/**
	 * Register the meta box on enabled post types.
	 */
	public function add_meta_box(): void {
		foreach ( $this->enabled_post_types() as $post_type ) {
			add_meta_box(
				'vaani_translations',
				__( 'Vaani Translations', 'vaani' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box.
	 */
	public function render( WP_Post $post ): void {
		$langs = $this->selected_languages( $post->ID );

		if ( empty( $langs ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Pick target languages in the “Translate into” panel, then return here to translate.', 'vaani' )
			);
			return;
		}

		echo '<p class="description">';
		esc_html_e( 'Translations use the last saved content. Save the post first if you have unsaved changes.', 'vaani' );
		echo '</p>';

		echo '<table class="vaani-translations widefat striped"><tbody>';
		foreach ( $langs as $code => $label ) {
			$button_label = $this->repository->find( $post->ID, $code )
				? __( 'Re-translate', 'vaani' )
				: __( 'Translate now', 'vaani' );

			printf(
				'<tr data-lang="%1$s"><th scope="row">%2$s</th><td class="vaani-status">%3$s</td><td><button type="button" class="button button-small vaani-translate" data-lang="%1$s">%4$s</button></td></tr>',
				esc_attr( $code ),
				esc_html( $label ),
				$this->status_cell_html( $post->ID, $code ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
				esc_html( $button_label )
			);
		}
		echo '</tbody></table>';

		$this->render_cost_estimate( $post->ID );
	}

	/**
	 * One-line all-time Sarvam cost estimate for this post (Phase 5).
	 *
	 * Read-only attribution — the part Sarvam's own dashboard can't show. The
	 * figure is an estimate; the full disclaimer + links live on the dashboard
	 * widget.
	 */
	private function render_cost_estimate( int $source_id ): void {
		$usage = $this->usageRepository->source_summary( $source_id );

		if ( 0 === $usage['operations'] ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: estimated rupee amount, 2: character count. */
					__( 'This post: ~₹%1$s estimated · %2$s characters sent to Sarvam.', 'vaani' ),
					number_format_i18n( $usage['cost'], 2 ),
					number_format_i18n( $usage['units'] )
				)
			)
		);
	}

	/**
	 * Languages selected for this post that are still globally enabled & known.
	 *
	 * @return array<string, string> code => label
	 */
	private function selected_languages( int $post_id ): array {
		$selected = get_post_meta( $post_id, LanguagePanel::META_KEY, true );
		$selected = is_array( $selected ) ? $selected : array();

		$enabled = array_intersect( $this->settings->get_target_langs(), $selected );

		$langs = array();
		foreach ( $enabled as $code ) {
			$language = Registry::get( $code );
			if ( $language ) {
				$langs[ $code ] = $language->label();
			}
		}

		return $langs;
	}

	/**
	 * Escaped HTML for a language's status cell (status text, badges, edit link).
	 */
	private function status_cell_html( int $source_id, string $lang ): string {
		$translation = $this->repository->find( $source_id, $lang );

		if ( ! $translation ) {
			return '<span class="vaani-state">' . esc_html__( 'Not translated', 'vaani' ) . '</span>';
		}

		$status = (string) get_post_meta( $translation->ID, TranslationPostType::META_STATUS, true );

		$html = '';
		switch ( $status ) {
			case TranslationPostType::STATUS_PENDING:
				$html = '<span class="vaani-state">' . esc_html__( 'Queued…', 'vaani' ) . '</span>';
				break;
			case TranslationPostType::STATUS_FAILED:
				$error = (string) get_post_meta( $translation->ID, TranslationPostType::META_ERROR, true );
				$html  = sprintf(
					'<span class="vaani-state" style="color:#b32d2e;"%s>%s</span>',
					'' !== $error ? ' title="' . esc_attr( $error ) . '"' : '',
					esc_html__( 'Failed', 'vaani' )
				);
				if ( '' !== $error ) {
					$html .= '<br><span class="description" style="color:#b32d2e;">' . esc_html( $error ) . '</span>';
				}
				break;
			case TranslationPostType::STATUS_COMPLETED:
				$html = '<span class="vaani-state">' . esc_html__( 'Translated', 'vaani' ) . '</span>';
				if ( $this->is_stale( $source_id, $translation->ID ) ) {
					$html .= ' <span class="vaani-badge" style="color:#bd8600;">' . esc_html__( '(stale)', 'vaani' ) . '</span>';
				}
				break;
			default:
				$html = '<span class="vaani-state">' . esc_html( $status ) . '</span>';
		}

		$edit_url = get_edit_post_link( $translation->ID, 'raw' );
		if ( $edit_url ) {
			$html .= sprintf(
				' &middot; <a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'vaani' )
			);
		}

		return $html;
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

		return $source ? Hash::of( $source->post_content ) !== $stored : false;
	}

	/**
	 * Enqueue the meta-box script on the post edit screen for enabled types.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		$asset_file = VAANI_DIR . 'dist/js/translation-metabox.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => VAANI_VERSION,
		);

		wp_enqueue_script(
			'vaani-translation-metabox',
			VAANI_URL . 'dist/js/translation-metabox.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'vaani-translation-metabox', 'vaani' );

		wp_localize_script(
			'vaani-translation-metabox',
			'vaaniTranslate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'postId'  => (int) get_the_ID(),
				'i18n'    => array(
					'queueing' => __( 'Queueing…', 'vaani' ),
					'error'    => __( 'Could not queue translation. Please try again.', 'vaani' ),
				),
			)
		);
	}

	/**
	 * Handle the Translate-now AJAX request.
	 */
	public function handle_translate(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$source_id = isset( $_POST['postId'] ) ? absint( wp_unslash( $_POST['postId'] ) ) : 0;
		$lang      = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		if ( ! $source_id || ! current_user_can( 'edit_post', $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'vaani' ) ), 403 );
		}

		$queued = $this->service->queue( $source_id, $lang );

		if ( ! $queued && ! $this->repository->find( $source_id, $lang ) ) {
			wp_send_json_error( array( 'message' => __( 'That language cannot be translated.', 'vaani' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'statusHtml' => $this->status_cell_html( $source_id, $lang ),
			)
		);
	}
}
