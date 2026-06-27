<?php
/**
 * Plugin orchestrator.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

use Vaani\Admin\SettingsPage;
use Vaani\Core\Crypto;
use Vaani\Core\Queue;
use Vaani\Core\Settings;
use Vaani\Translation\Admin\LanguagePanel;
use Vaani\Translation\Admin\TranslationMetaBox;
use Vaani\Translation\TranslationPostType;
use Vaani\Translation\TranslationRepository;
use Vaani\Translation\TranslationService;

defined( 'ABSPATH' ) || exit;

/**
 * Wires features together and registers their hooks.
 */
class Plugin {

	/**
	 * Register the text domain and all features.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$settings = new Settings( new Crypto() );

		( new SettingsPage( $settings ) )->register();
		( new LanguagePanel( $settings ) )->register();

		// Translation engine (Phase 2).
		( new TranslationPostType() )->register();

		$repository = new TranslationRepository();
		$service    = new TranslationService( $repository, new Queue(), $settings );
		$service->register();

		( new TranslationMetaBox( $settings, $repository, $service ) )->register();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'vaani', false, dirname( plugin_basename( VAANI_FILE ) ) . '/languages' );
	}
}
