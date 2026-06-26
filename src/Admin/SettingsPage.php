<?php
/**
 * Vaani settings page.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Admin;

use Vaani\Core\Sarvam\Client;
use Vaani\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API page under Settings → Vaani, plus the Test Connection AJAX
 * handler and the missing-key admin notice.
 */
class SettingsPage {

	/**
	 * Settings group / page slug used across the Settings API calls.
	 */
	private const PAGE_SLUG = 'vaani';

	/**
	 * AJAX action name for the connection test.
	 */
	private const AJAX_ACTION = 'vaani_test_connection';

	/**
	 * Source-language choices for Phase 0.
	 *
	 * A minimal local list; Phase 2 introduces the canonical language
	 * registry (CLAUDE.md seam #2) that replaces this.
	 *
	 * @var array<string, string>
	 */
	private const SOURCE_LANGUAGES = array(
		'en' => 'English',
	);

	/**
	 * @param Settings $settings Settings accessor.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_key_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_test_connection' ) );
	}

	/**
	 * Add the settings submenu page.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Vaani', 'vaani' ),
			__( 'Vaani', 'vaani' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting, section, and fields via the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::PAGE_SLUG,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section(
			'vaani_main',
			__( 'Sarvam AI', 'vaani' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_key',
			__( 'API key', 'vaani' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			'vaani_main',
			array( 'label_for' => 'vaani_api_key' )
		);

		add_settings_field(
			'source_lang',
			__( 'Default source language', 'vaani' ),
			array( $this, 'render_source_lang_field' ),
			self::PAGE_SLUG,
			'vaani_main',
			array( 'label_for' => 'vaani_source_lang' )
		);

		add_settings_field(
			'post_types',
			__( 'Translatable content', 'vaani' ),
			array( $this, 'render_post_types_field' ),
			self::PAGE_SLUG,
			'vaani_main'
		);
	}

	/**
	 * Render the API key field.
	 *
	 * The saved secret is never re-rendered. When a key exists the input is
	 * left blank (blank submit keeps it) with a "remove" option to clear it.
	 */
	public function render_api_key_field(): void {
		$has_key = $this->settings->has_api_key();

		printf(
			'<input type="password" id="vaani_api_key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $has_key ? __( 'Saved — leave blank to keep', 'vaani' ) : __( 'Enter your Sarvam API key', 'vaani' ) )
		);

		if ( $has_key ) {
			printf(
				'<label style="display:block;margin-top:6px;"><input type="checkbox" name="%1$s[remove_api_key]" value="1" /> %2$s</label>',
				esc_attr( Settings::OPTION_NAME ),
				esc_html__( 'Remove the saved key', 'vaani' )
			);
		}

		echo '<p class="description">' . esc_html__( 'Stored encrypted in the database.', 'vaani' ) . '</p>';
	}

	/**
	 * Render the source-language select.
	 */
	public function render_source_lang_field(): void {
		$current = $this->settings->get_source_lang();

		printf( '<select id="vaani_source_lang" name="%s[source_lang]">', esc_attr( Settings::OPTION_NAME ) );
		foreach ( self::SOURCE_LANGUAGES as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render the post-types checkboxes.
	 */
	public function render_post_types_field(): void {
		$enabled = $this->settings->get_post_types();
		$labels  = array(
			'post' => __( 'Posts', 'vaani' ),
			'page' => __( 'Pages', 'vaani' ),
		);

		echo '<fieldset>';
		foreach ( Settings::ALLOWED_POST_TYPES as $type ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Settings::OPTION_NAME ),
				esc_attr( $type ),
				checked( in_array( $type, $enabled, true ), true, false ),
				esc_html( $labels[ $type ] ?? $type )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Vaani', 'vaani' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php echo esc_html__( 'Connection', 'vaani' ); ?></h2>
			<p>
				<button type="button" class="button" id="vaani-test-connection">
					<?php echo esc_html__( 'Test connection', 'vaani' ); ?>
				</button>
				<span id="vaani-test-connection-result" role="status" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue the settings-page script on the Vaani settings screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = VAANI_DIR . 'dist/js/admin-settings.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => VAANI_VERSION,
		);

		wp_enqueue_script(
			'vaani-admin-settings',
			VAANI_URL . 'dist/js/admin-settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'vaani-admin-settings',
			'vaaniSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'testing' => __( 'Testing…', 'vaani' ),
					'error'   => __( 'Something went wrong. Please try again.', 'vaani' ),
				),
			)
		);
	}

	/**
	 * Handle the Test Connection AJAX request.
	 */
	public function handle_test_connection(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'vaani' ) ), 403 );
		}

		$client = new Client( $this->settings->get_api_key() );
		$result = $client->test_connection();

		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Show a warning notice when no API key is configured.
	 */
	public function maybe_show_missing_key_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || $this->settings->has_api_key() ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses( __( 'Vaani needs a Sarvam API key to translate content. <a href="%s">Add your key</a>.', 'vaani' ), array( 'a' => array( 'href' => array() ) ) ),
					esc_url( $url )
				);
				?>
			</p>
		</div>
		<?php
	}
}
