<?php
/**
 * Per-language "Listen" audio player (block and optional content filter).
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Frontend;

use Vaani\Audio\AudioRepository;
use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a "Listen" audio player for the language currently being viewed.
 *
 * Selection is per-post, not sticky (CLAUDE.md product model): on the original
 * URL it offers the English audio, and on `/<lang>/<slug>/` it offers that
 * language's audio — each read from {@see Router::QV_LANG}. The same markup
 * ({@see templates/audio-player.php}, theme-overridable) backs both the editor
 * block and an opt-in `the_content` prepend. Nothing renders unless audio for the
 * current language has been generated; staleness never hides it — that's an
 * editor-only signal (see {@see AvailableTranslations}).
 */
class AudioPlayer {

	/**
	 * Front-end style handle registered with the block, reused by the content
	 * filter (which doesn't enqueue block assets automatically).
	 */
	private string $style_handle = '';

	/**
	 * @param AudioRepository $repository Audio storage.
	 * @param Settings        $settings   Settings accessor (source language).
	 */
	public function __construct(
		private AudioRepository $repository,
		private Settings $settings
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'the_content', array( $this, 'maybe_prepend_to_content' ) );
	}

	/**
	 * Register the dynamic audio-player block from its built metadata.
	 */
	public function register_block(): void {
		$dir = VAANI_DIR . 'dist/blocks/audio-player';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$type = register_block_type(
			$dir,
			array( 'render_callback' => array( $this, 'render_block' ) )
		);

		if ( $type && ! empty( $type->style_handles ) ) {
			$this->style_handle = $type->style_handles[0];
		}
	}

	/**
	 * Dynamic block render callback.
	 *
	 * @return string Player HTML, or empty string when there's no audio to play.
	 */
	public function render_block(): string {
		return $this->render();
	}

	/**
	 * Prepend the player to singular content when explicitly opted in.
	 *
	 * Off by default (themes place the block instead); enable with
	 * `add_filter( 'vaani_append_audio_player', '__return_true' )`.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_prepend_to_content( string $content ): string {
		if ( ! apply_filters( 'vaani_append_audio_player', false ) ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() || ! is_singular( Settings::ALLOWED_POST_TYPES ) ) {
			return $content;
		}

		return $this->render() . $content;
	}

	/**
	 * Render the player for the current singular view's language.
	 */
	public function render(): string {
		if ( ! is_singular( Settings::ALLOWED_POST_TYPES ) ) {
			return '';
		}

		$source = get_post( get_queried_object_id() );

		if ( ! $source instanceof WP_Post ) {
			return '';
		}

		$current_lang = (string) get_query_var( Router::QV_LANG );
		$lang         = '' === $current_lang ? $this->settings->get_source_lang() : $current_lang;

		$url = $this->repository->get_audio_url( $source->ID, $lang );

		if ( '' === $url ) {
			return '';
		}

		if ( '' !== $this->style_handle && wp_style_is( $this->style_handle, 'registered' ) ) {
			wp_enqueue_style( $this->style_handle );
		}

		$vaani_audio_player = array(
			'url'   => $url,
			'mime'  => 'audio/mpeg',
			'label' => $this->language_label( $lang ),
			'title' => __( 'Listen to this article', 'vaani' ),
		);

		$template = locate_template( 'vaani/audio-player.php' );

		if ( '' === $template ) {
			$template = VAANI_DIR . 'templates/audio-player.php';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Human-readable label for a language code (source language falls back to a
	 * filterable default since it isn't a registry target).
	 */
	private function language_label( string $lang ): string {
		$language = Registry::get( $lang );

		if ( $language ) {
			return $language->label();
		}

		return (string) apply_filters( 'vaani_original_language_label', __( 'English', 'vaani' ), $lang );
	}
}
