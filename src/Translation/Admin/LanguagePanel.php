<?php
/**
 * Per-post target-language selection panel.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation\Admin;

use Vaani\Core\Language\SupportedLanguages;
use Vaani\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `_vaani_target_langs` post meta and the block-editor sidebar
 * panel that lets an author choose, per post/page, which of the globally
 * enabled languages to translate into.
 */
class LanguagePanel {

	/**
	 * Meta key storing the per-post selected target-language codes.
	 */
	public const META_KEY = '_vaani_target_langs';

	/**
	 * @param Settings $settings Settings accessor (global enabled languages + post types).
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_panel' ) );
	}

	/**
	 * Post types that get the panel: the v1-allowed types enabled in settings.
	 *
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		return array_values( array_intersect( Settings::ALLOWED_POST_TYPES, $this->settings->get_post_types() ) );
	}

	/**
	 * Register the target-languages meta on each enabled post type so the
	 * block editor can read and write it over the REST API.
	 */
	public function register_meta(): void {
		foreach ( $this->enabled_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'sanitize_callback' => array( $this, 'sanitize_target_langs' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ): bool {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	/**
	 * Sanitize the stored selection: keep only supported language codes.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string[]
	 */
	public function sanitize_target_langs( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$codes = array_map( 'sanitize_key', $value );

		return array_values( array_intersect( SupportedLanguages::codes(), $codes ) );
	}

	/**
	 * Enqueue the sidebar-panel script on the editor for enabled post types only.
	 */
	public function enqueue_panel(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		$asset_file = VAANI_DIR . 'dist/js/editor-language-panel.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => VAANI_VERSION,
		);

		wp_enqueue_script(
			'vaani-editor-language-panel',
			VAANI_URL . 'dist/js/editor-language-panel.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'vaani-editor-language-panel', 'vaani' );

		wp_localize_script(
			'vaani-editor-language-panel',
			'vaaniLanguagePanel',
			array(
				'metaKey'     => self::META_KEY,
				'languages'   => SupportedLanguages::filter( $this->settings->get_target_langs() ),
				'settingsUrl' => admin_url( 'options-general.php?page=vaani' ),
			)
		);
	}
}
