<?php
/**
 * "Usage this month" dashboard widget.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Usage\Admin;

use Vaani\Usage\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Shows this month's translation/audio counts, characters used, and a rough INR
 * estimate on the WordPress dashboard. The estimate is explicitly framed as
 * approximate and links to Sarvam's pricing and usage pages, which are the
 * source of truth (Sarvam has no usage/credits API to read from).
 */
class UsageDashboardWidget {

	/**
	 * Sarvam pricing page (verify the per-character rates).
	 */
	private const PRICING_URL = 'https://www.sarvam.ai/api-pricing';

	/**
	 * Sarvam usage dashboard (actual usage and billed cost).
	 */
	private const USAGE_URL = 'https://dashboard.sarvam.ai/usage';

	/**
	 * @param UsageRepository $repository Usage storage.
	 */
	public function __construct( private UsageRepository $repository ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Add the widget for users who can manage the site (cost is sensitive).
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'vaani_usage',
			__( 'Vaani — Usage this month', 'vaani' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the widget.
	 */
	public function render(): void {
		$month_start = gmdate( 'Y-m-01 00:00:00', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- site-local month boundary.
		$summary     = $this->repository->monthly_summary( $month_start );

		echo '<table class="vaani-usage widefat striped"><tbody>';
		$this->row( __( 'Translations', 'vaani' ), number_format_i18n( $summary['translations'] ) );
		$this->row( __( 'Audio files', 'vaani' ), number_format_i18n( $summary['audio'] ) );
		$this->row( __( 'Characters used', 'vaani' ), number_format_i18n( $summary['units'] ) );
		$this->row(
			__( 'Est. cost', 'vaani' ),
			/* translators: %s: estimated cost amount in Indian rupees. */
			sprintf( __( '~₹%s', 'vaani' ), number_format_i18n( $summary['cost'], 2 ) )
		);
		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: Sarvam pricing page link, 2: Sarvam usage dashboard link. */
					__( 'Cost is a rough estimate from published per-character rates and may not match what Sarvam bills. Check the <a href="%1$s" target="_blank" rel="noopener">pricing</a> and your <a href="%2$s" target="_blank" rel="noopener">Sarvam usage dashboard</a> for actual usage and cost.', 'vaani' ),
					esc_url( self::PRICING_URL ),
					esc_url( self::USAGE_URL )
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			)
		);
	}

	/**
	 * Echo one escaped label/value table row.
	 */
	private function row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
