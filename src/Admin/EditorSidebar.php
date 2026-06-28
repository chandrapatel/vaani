<?php
/**
 * Enqueues the Vaani block-editor sidebar.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Admin;

use Vaani\Audio\AudioStatusPresenter;
use Vaani\Core\Settings;
use Vaani\Translation\TranslationStatusPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues the single "Vaani" plugin sidebar (PluginSidebar) on the
 * block editor for enabled post types, replacing the old translation and audio
 * meta boxes.
 *
 * The current post's translation + audio status is localized up-front so the
 * sidebar paints instantly; mutations and refreshes go through the REST routes.
 */
class EditorSidebar {

	/**
	 * @param Settings                   $settings     Settings accessor (post types).
	 * @param TranslationStatusPresenter $translations Translation status shaper.
	 * @param AudioStatusPresenter       $audio        Audio status shaper.
	 */
	public function __construct(
		private Settings $settings,
		private TranslationStatusPresenter $translations,
		private AudioStatusPresenter $audio
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Post types that get the sidebar: the v1-allowed types enabled in settings.
	 *
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		return array_values( array_intersect( Settings::ALLOWED_POST_TYPES, $this->settings->get_post_types() ) );
	}

	/**
	 * Enqueue the sidebar script on the editor for enabled post types only.
	 */
	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		$post    = get_post();
		$post_id = $post ? (int) $post->ID : 0;

		$asset_file = VAANI_DIR . 'dist/js/editor-sidebar.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => VAANI_VERSION,
		);

		wp_enqueue_script(
			'vaani-editor-sidebar',
			VAANI_URL . 'dist/js/editor-sidebar.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'vaani-editor-sidebar', 'vaani' );

		wp_localize_script(
			'vaani-editor-sidebar',
			'vaaniSidebar',
			array(
				'postId'       => $post_id,
				'rest'         => array(
					'translations' => '/vaani/v1/translations',
					'audio'        => '/vaani/v1/audio',
				),
				'translations' => $this->translations->for_source( $post_id ),
				'audio'        => $this->audio->for_source( $post_id ),
				'settingsUrl'  => admin_url( 'options-general.php?page=vaani' ),
			)
		);
	}
}
