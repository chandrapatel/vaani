<?php
/**
 * Plugin orchestrator.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

use Vaani\Admin\EditorSidebar;
use Vaani\Admin\SettingsPage;
use Vaani\Audio\AudioCleanup;
use Vaani\Audio\AudioRepository;
use Vaani\Audio\AudioService;
use Vaani\Audio\AudioStatusPresenter;
use Vaani\Audio\Rest\AudioController;
use Vaani\Core\Crypto;
use Vaani\Core\Queue;
use Vaani\Core\Settings;
use Vaani\Frontend\AudioPlayer;
use Vaani\Frontend\AvailableTranslations;
use Vaani\Frontend\ContentRenderer;
use Vaani\Frontend\LanguageSwitcher;
use Vaani\Frontend\Router;
use Vaani\Seo\Hreflang;
use Vaani\Seo\YoastAdapter;
use Vaani\Translation\Admin\TranslationBulkAction;
use Vaani\Translation\Rest\TranslationController;
use Vaani\Translation\TranslationCleanup;
use Vaani\Translation\TranslationPostType;
use Vaani\Translation\TranslationRepository;
use Vaani\Translation\TranslationService;
use Vaani\Translation\TranslationStatusPresenter;
use Vaani\Usage\Admin\UsageDashboardWidget;
use Vaani\Usage\UsageLogger;
use Vaani\Usage\UsageRepository;
use Vaani\Usage\UsageTable;

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

		// Usage & billing (Phase 5).
		$usage_repo = new UsageRepository();
		( new UsageLogger( $usage_repo ) )->register();
		( new UsageDashboardWidget( $usage_repo ) )->register();
		add_action( 'admin_init', array( UsageTable::class, 'maybe_upgrade' ) );

		// Translation engine (Phase 2).
		( new TranslationPostType() )->register();

		$repository = new TranslationRepository();
		$service    = new TranslationService( $repository, new Queue(), $settings );
		$service->register();

		$translation_presenter = new TranslationStatusPresenter( $settings, $repository, $usage_repo );
		( new TranslationController( $service, $translation_presenter ) )->register();
		( new TranslationBulkAction( $settings, $service ) )->register();
		( new TranslationCleanup( $repository ) )->register();

		// Front-end rendering, URLs & SEO (Phase 3).
		$available = new AvailableTranslations( $repository );
		$available->register();

		( new Router() )->register();
		( new ContentRenderer( $available ) )->register();
		( new LanguageSwitcher( $available, $settings ) )->register();
		( new Hreflang( $available, $settings ) )->register();
		( new YoastAdapter( $available ) )->register();

		// Audio generation (Phase 4).
		$audio_repo    = new AudioRepository();
		$audio_service = new AudioService( $audio_repo, $repository, new Queue(), $settings );
		$audio_service->register();

		$audio_presenter = new AudioStatusPresenter( $settings, $available, $audio_repo );
		( new AudioController( $audio_service, $audio_presenter ) )->register();
		( new AudioPlayer( $audio_repo, $settings ) )->register();
		( new AudioCleanup( $audio_repo ) )->register();

		// Block-editor sidebar replacing the translation/audio meta boxes.
		( new EditorSidebar( $settings, $translation_presenter, $audio_presenter ) )->register();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'vaani', false, dirname( plugin_basename( VAANI_FILE ) ) . '/languages' );
	}
}
