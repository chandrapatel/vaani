<?php
/**
 * Plugin activation tasks.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

use Vaani\Frontend\Router;
use Vaani\Usage\UsageTable;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once on activation.
 */
class Activator {

	/**
	 * Activation handler.
	 *
	 * Registers the path-prefixed translation rewrite rules and flushes so they
	 * take effect immediately — the plugin's own `init` hook may not have run on
	 * the activation request, so we add the rules here rather than relying on it.
	 */
	public static function activate(): void {
		Router::add_rewrite_rules();
		flush_rewrite_rules();

		UsageTable::create();
	}
}
