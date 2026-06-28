<?php
/**
 * Usage table schema and upgrade.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `wp_vaani_usage` schema (CLAUDE.md §7).
 *
 * One row per billable Sarvam API call. Indexed on `source_id` and `created_at`
 * from day one so a future prune/rollup job is additive (seam #4).
 */
class UsageTable {

	/**
	 * Table name without the `$wpdb->prefix`.
	 */
	public const TABLE = 'vaani_usage';

	/**
	 * Schema version. Bump when the columns/indexes change.
	 */
	public const DB_VERSION = '1';

	/**
	 * Option storing the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'vaani_usage_db_version';

	/**
	 * Fully-qualified table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the table via dbDelta, then record the schema version.
	 */
	public static function create(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			operation VARCHAR(32) NOT NULL DEFAULT '',
			lang VARCHAR(12) NOT NULL DEFAULT '',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			units INT UNSIGNED NOT NULL DEFAULT 0,
			unit_type VARCHAR(16) NOT NULL DEFAULT '',
			est_cost_inr DECIMAL(12,4) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create the table on existing/active installs that predate it, without
	 * requiring a deactivate/reactivate cycle.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create();
	}
}
