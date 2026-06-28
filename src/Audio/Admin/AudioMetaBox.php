<?php
/**
 * Per-post audio status & generation meta box.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Audio\Admin;

use Vaani\Audio\AudioRepository;
use Vaani\Audio\AudioService;
use Vaani\Core\Hash;
use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use Vaani\Frontend\AvailableTranslations;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Vaani Audio" meta box to the editor for enabled post types, showing
 * per-language audio status, a file link, a stale badge, and a Generate button
 * that queues background TTS over AJAX.
 *
 * Rows cover the English original plus every language the post already has a
 * published translation in — those are the only languages there is text to voice.
 */
class AudioMetaBox {

	/**
	 * AJAX action for queueing audio generation.
	 */
	private const AJAX_ACTION = 'vaani_generate_audio';

	/**
	 * @param Settings              $settings   Settings accessor.
	 * @param AvailableTranslations $available  Renderable-translation resolver.
	 * @param AudioRepository       $repository Audio storage.
	 * @param AudioService          $service    Audio orchestration.
	 */
	public function __construct(
		private Settings $settings,
		private AvailableTranslations $available,
		private AudioRepository $repository,
		private AudioService $service
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_generate' ) );
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
				'vaani_audio',
				__( 'Vaani Audio', 'vaani' ),
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
		$langs = $this->audio_languages( $post->ID );

		echo '<p class="description">';
		esc_html_e( 'Generate spoken audio of the original and each published translation.', 'vaani' );
		echo '</p>';

		echo '<table class="vaani-audio widefat striped"><tbody>';
		foreach ( $langs as $code => $label ) {
			$button_label = $this->repository->get_audio_id( $post->ID, $code )
				? __( 'Regenerate', 'vaani' )
				: __( 'Generate', 'vaani' );

			printf(
				'<tr data-lang="%1$s"><th scope="row">%2$s</th><td class="vaani-status">%3$s</td><td><button type="button" class="button button-small vaani-generate-audio" data-lang="%1$s">%4$s</button></td></tr>',
				esc_attr( $code ),
				esc_html( $label ),
				$this->status_cell_html( $post->ID, $code ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
				esc_html( $button_label )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Languages eligible for audio: the source language (English) plus every
	 * language with a renderable translation, in registry order.
	 *
	 * @return array<string, string> code => label
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
	 * Escaped HTML for a language's status cell (status text, stale badge, file link).
	 */
	private function status_cell_html( int $source_id, string $lang ): string {
		$status   = $this->repository->get_status( $source_id, $lang );
		$audio_id = $this->repository->get_audio_id( $source_id, $lang );

		if ( AudioRepository::STATUS_PENDING === $status ) {
			return '<span class="vaani-state">' . esc_html__( 'Queued…', 'vaani' ) . '</span>';
		}

		if ( AudioRepository::STATUS_FAILED === $status ) {
			return '<span class="vaani-state" style="color:#b32d2e;">' . esc_html__( 'Failed', 'vaani' ) . '</span>';
		}

		if ( 0 === $audio_id ) {
			return '<span class="vaani-state">' . esc_html__( 'Not generated', 'vaani' ) . '</span>';
		}

		$html = '<span class="vaani-state">' . esc_html__( 'Generated', 'vaani' ) . '</span>';

		if ( $this->repository->is_stale( $source_id, $lang, $this->current_hash( $source_id, $lang ) ) ) {
			$html .= ' <span class="vaani-badge" style="color:#bd8600;">' . esc_html__( '(stale)', 'vaani' ) . '</span>';
		}

		$url = $this->repository->get_audio_url( $source_id, $lang );
		if ( '' !== $url ) {
			$html .= sprintf(
				' &middot; <a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $url ),
				esc_html__( 'File', 'vaani' )
			);
		}

		return $html;
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

		$asset_file = VAANI_DIR . 'dist/js/audio-metabox.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => VAANI_VERSION,
		);

		wp_enqueue_script(
			'vaani-audio-metabox',
			VAANI_URL . 'dist/js/audio-metabox.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'vaani-audio-metabox', 'vaani' );

		wp_localize_script(
			'vaani-audio-metabox',
			'vaaniAudio',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'postId'  => (int) get_the_ID(),
				'i18n'    => array(
					'queueing' => __( 'Queueing…', 'vaani' ),
					'error'    => __( 'Could not queue audio. Please try again.', 'vaani' ),
				),
			)
		);
	}

	/**
	 * Handle the Generate-audio AJAX request.
	 */
	public function handle_generate(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$source_id = isset( $_POST['postId'] ) ? absint( wp_unslash( $_POST['postId'] ) ) : 0;
		$lang      = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		if ( ! $source_id || ! current_user_can( 'edit_post', $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'vaani' ) ), 403 );
		}

		$queued = $this->service->queue( $source_id, $lang );

		if ( ! $queued && AudioRepository::STATUS_PENDING !== $this->repository->get_status( $source_id, $lang ) ) {
			wp_send_json_error( array( 'message' => __( 'Audio cannot be generated for that language yet.', 'vaani' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'statusHtml' => $this->status_cell_html( $source_id, $lang ),
			)
		);
	}
}
