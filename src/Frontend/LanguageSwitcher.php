<?php
/**
 * Per-post language switcher (block, widget, and optional content filter).
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Frontend;

use Vaani\Core\Language\Registry;
use Vaani\Core\Settings;
use WP_Post;
use WP_Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Renders links to each language a post is available in, plus a link back to the
 * original.
 *
 * Selection is **per-post, not sticky** (CLAUDE.md product model): the switcher
 * emits plain links to `/<lang>/<slug>/`, sets no cookie, and stores no
 * preference — a reader who switches post #482 to Tamil returns to English
 * everywhere else. The same markup ({@see templates/switcher.php}, theme-overridable)
 * is shared by all three surfaces: the editor block, a classic widget, and an
 * opt-in `the_content` append.
 */
class LanguageSwitcher {

	/**
	 * Shared instance so the {@see LanguageSwitcherWidget} — which WordPress
	 * instantiates itself, with no constructor args — can reach the injected
	 * dependencies. Not a general singleton; only the widget bridge uses it.
	 */
	private static ?LanguageSwitcher $instance = null;

	/**
	 * Front-end style handle registered with the block, reused for the widget and
	 * content-filter surfaces (which don't enqueue block assets automatically).
	 */
	private string $style_handle = '';

	/**
	 * @param AvailableTranslations $available Renderable-translation resolver.
	 * @param Settings              $settings  Settings accessor (source language).
	 */
	public function __construct(
		private AvailableTranslations $available,
		private Settings $settings
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		self::$instance = $this;

		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_filter( 'the_content', array( $this, 'maybe_append_to_content' ) );
	}

	/**
	 * Register the dynamic switcher block from its built metadata.
	 */
	public function register_block(): void {
		$dir = VAANI_DIR . 'dist/blocks/language-switcher';

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
	 * Register the classic-widget surface.
	 */
	public function register_widget(): void {
		register_widget( LanguageSwitcherWidget::class );
	}

	/**
	 * Dynamic block render callback.
	 *
	 * @return string Switcher HTML, or empty string when nothing to show.
	 */
	public function render_block(): string {
		return $this->render();
	}

	/**
	 * Append the switcher to singular content when explicitly opted in.
	 *
	 * Off by default (themes place the block/widget instead); enable with
	 * `add_filter( 'vaani_append_switcher', '__return_true' )`.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_append_to_content( string $content ): string {
		if ( ! apply_filters( 'vaani_append_switcher', false ) ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() || ! is_singular( Settings::ALLOWED_POST_TYPES ) ) {
			return $content;
		}

		return $content . $this->render();
	}

	/**
	 * Render the switcher for the current singular view.
	 *
	 * Returns empty when not on a translatable singular post, or when the post has
	 * no published, non-stale translation (a lone English link is pointless).
	 */
	public function render(): string {
		if ( ! is_singular( Settings::ALLOWED_POST_TYPES ) ) {
			return '';
		}

		$source = get_post( get_queried_object_id() );

		if ( ! $source instanceof WP_Post ) {
			return '';
		}

		$available = $this->available->for_source( $source->ID );

		if ( empty( $available ) ) {
			return '';
		}

		$current_lang   = (string) get_query_var( Router::QV_LANG );
		$vaani_switcher = array(
			'aria_label' => __( 'Choose a language', 'vaani' ),
			'items'      => $this->build_items( $source, $available, $current_lang ),
		);

		if ( '' !== $this->style_handle && wp_style_is( $this->style_handle, 'registered' ) ) {
			wp_enqueue_style( $this->style_handle );
		}

		$template = locate_template( 'vaani/switcher.php' );

		if ( '' === $template ) {
			$template = VAANI_DIR . 'templates/switcher.php';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Build the switcher entries: the original first, then each translation.
	 *
	 * @param array<string, WP_Post> $available Renderable translations by language.
	 * @return array<int, array{label:string,url:string,is_current:bool}>
	 */
	private function build_items( WP_Post $source, array $available, string $current_lang ): array {
		$items = array(
			array(
				'label'      => $this->original_label(),
				'url'        => (string) get_permalink( $source ),
				'is_current' => '' === $current_lang,
			),
		);

		foreach ( $available as $lang => $translation ) {
			$language = Registry::get( $lang );

			if ( null === $language ) {
				continue;
			}

			$items[] = array(
				'label'      => $language->label(),
				'url'        => Router::url_for( $source, $lang ),
				'is_current' => $lang === $current_lang,
			);
		}

		return $items;
	}

	/**
	 * Label for the original-language link.
	 *
	 * The source language (English in v1) isn't a target, so it has no registry
	 * entry; default to "English" and let sites override via filter.
	 */
	private function original_label(): string {
		$source_lang = $this->settings->get_source_lang();
		$language    = Registry::get( $source_lang );
		$label       = $language ? $language->label() : __( 'English', 'vaani' );

		return (string) apply_filters( 'vaani_original_language_label', $label, $source_lang );
	}

	/**
	 * Render via the shared instance, for the widget bridge.
	 */
	public static function render_shared(): string {
		return self::$instance ? self::$instance->render() : '';
	}
}

/**
 * Classic widget wrapper around {@see LanguageSwitcher}.
 *
 * Defined here (not its own PSR-4 file) because it's a thin bridge loaded with
 * the switcher and instantiated by WordPress, not via the autoloader by name.
 */
class LanguageSwitcherWidget extends WP_Widget {

	/**
	 * Register the widget definition.
	 */
	public function __construct() {
		parent::__construct(
			'vaani_language_switcher',
			__( 'Vaani Language Switcher', 'vaani' ),
			array( 'description' => __( 'Links to this post\'s available translations.', 'vaani' ) )
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array<string, mixed> $args     Theme widget wrappers.
	 * @param array<string, mixed> $instance Saved widget settings.
	 */
	public function widget( $args, $instance ): void {
		$switcher = LanguageSwitcher::render_shared();

		if ( '' === $switcher ) {
			return;
		}

		echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.

		if ( ! empty( $instance['title'] ) ) {
			$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );
			echo ( $args['before_title'] ?? '' ) . esc_html( $title ) . ( $args['after_title'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
		}

		echo $switcher; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in template.
		echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
	}

	/**
	 * Settings form.
	 *
	 * @param array<string, mixed> $instance Saved settings.
	 * @return string
	 */
	public function form( $instance ): string {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s" /></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'vaani' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title )
		);

		return '';
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string, mixed> $new_instance Submitted values.
	 * @param array<string, mixed> $old_instance Previous values.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		return array( 'title' => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ) );
	}
}
