<?php
/**
 * Plugin activation tasks.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once on activation.
 */
class Activator {

	/**
	 * Activation handler.
	 *
	 * Flushes rewrite rules so the lifecycle hook is in place for the
	 * path-prefixed translation URLs added in Phase 3. No rules are
	 * registered yet, and no tables are created in Phase 0.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}
}
