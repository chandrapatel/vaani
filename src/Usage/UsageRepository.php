<?php
/**
 * Data access for the usage log.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that reads or writes `wp_vaani_usage`.
 *
 * Callers never touch `$wpdb` directly, so the storage shape (and a future
 * prune/rollup) can change here without touching call sites.
 */
class UsageRepository {

	/**
	 * Record one billable API call.
	 */
	public function insert( string $operation, string $lang, int $units, int $source_id, string $unit_type, float $est_cost ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->insert(
			UsageTable::table_name(),
			array(
				'operation'    => $operation,
				'lang'         => $lang,
				'source_id'    => $source_id,
				'units'        => $units,
				'unit_type'    => $unit_type,
				'est_cost_inr' => $est_cost,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%f', '%s' )
		);
	}

	/**
	 * Aggregate counts and cost for rows on/after a datetime.
	 *
	 * @param string $since_mysql Site-local `Y-m-d H:i:s` lower bound (inclusive).
	 * @return array{translations:int, audio:int, units:int, cost:float}
	 */
	public function monthly_summary( string $since_mysql ): array {
		global $wpdb;

		$table = UsageTable::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is a constant; value is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( CASE WHEN operation = 'tts' THEN 0 ELSE 1 END ) AS translations,
					SUM( CASE WHEN operation = 'tts' THEN 1 ELSE 0 END ) AS audio,
					SUM( units ) AS units,
					SUM( est_cost_inr ) AS cost
				FROM {$table}
				WHERE created_at >= %s",
				$since_mysql
			),
			ARRAY_A
		);

		return array(
			'translations' => (int) ( $row['translations'] ?? 0 ),
			'audio'        => (int) ( $row['audio'] ?? 0 ),
			'units'        => (int) ( $row['units'] ?? 0 ),
			'cost'         => (float) ( $row['cost'] ?? 0 ),
		);
	}

	/**
	 * All-time totals for a single source post (per-post attribution).
	 *
	 * @return array{operations:int, units:int, cost:float}
	 */
	public function source_summary( int $source_id ): array {
		global $wpdb;

		$table = UsageTable::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is a constant; value is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS operations, SUM( units ) AS units, SUM( est_cost_inr ) AS cost
				FROM {$table}
				WHERE source_id = %d",
				$source_id
			),
			ARRAY_A
		);

		return array(
			'operations' => (int) ( $row['operations'] ?? 0 ),
			'units'      => (int) ( $row['units'] ?? 0 ),
			'cost'       => (float) ( $row['cost'] ?? 0 ),
		);
	}
}
