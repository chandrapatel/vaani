<?php
/**
 * Plugin deactivation tasks.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once on deactivation.
 */
class Deactivator {

	/**
	 * Deactivation handler.
	 *
	 * Flushes rewrite rules to drop any Vaani routes from the rewrite cache.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
