<?php
/**
 * Plugin orchestrator.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

use Vaani\Admin\SettingsPage;
use Vaani\Audio\Admin\AudioMetaBox;
use Vaani\Audio\AudioRepository;
use Vaani\Audio\AudioService;
use Vaani\Core\Crypto;
use Vaani\Core\Queue;
use Vaani\Core\Settings;
use Vaani\Frontend\AudioPlayer;
use Vaani\Frontend\AvailableTranslations;
use Vaani\Frontend\ContentRenderer;
use Vaani\Frontend\LanguageSwitcher;
use Vaani\Frontend\Router;
use Vaani\Seo\Hreflang;
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

		// Front-end rendering, URLs & SEO (Phase 3).
		$available = new AvailableTranslations( $repository );

		( new Router() )->register();
		( new ContentRenderer( $available ) )->register();
		( new LanguageSwitcher( $available, $settings ) )->register();
		( new Hreflang( $available, $settings ) )->register();

		// Audio generation (Phase 4).
		$audio_repo    = new AudioRepository();
		$audio_service = new AudioService( $audio_repo, $repository, new Queue(), $settings );
		$audio_service->register();

		( new AudioMetaBox( $settings, $available, $audio_repo, $audio_service ) )->register();
		( new AudioPlayer( $audio_repo, $settings ) )->register();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'vaani', false, dirname( plugin_basename( VAANI_FILE ) ) . '/languages' );
	}
}
