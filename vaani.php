<?php
/**
 * Plugin Name:       Vaani – AI Translation & Audio for Indian Blogs
 * Description:        Translate posts and pages into Indian languages and generate per-language audio using Sarvam AI.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Chandra Patel
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vaani
 * Domain Path:       /languages
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

defined( 'ABSPATH' ) || exit;

define( 'VAANI_VERSION', '0.1.0' );
define( 'VAANI_FILE', __FILE__ );
define( 'VAANI_DIR', plugin_dir_path( __FILE__ ) );
define( 'VAANI_URL', plugin_dir_url( __FILE__ ) );

require_once VAANI_DIR . 'vendor/autoload.php';

// Action Scheduler ships as a bundled library; load its entry point so the
// background-job functions (as_enqueue_async_action, etc.) are available.
$vaani_action_scheduler = VAANI_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $vaani_action_scheduler ) ) {
	require_once $vaani_action_scheduler;
}

register_activation_hook( VAANI_FILE, array( Activator::class, 'activate' ) );
register_deactivation_hook( VAANI_FILE, array( Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new Plugin() )->register();
	}
);
