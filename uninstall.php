<?php
/**
 * Vaani uninstall cleanup.
 *
 * @package Vaani
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vaani_settings' );

global $wpdb;

// Drop the usage log table and its schema-version marker (Phase 5).
$vaani_usage_table = $wpdb->prefix . 'vaani_usage';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifier is built from $wpdb->prefix; DROP cannot use placeholders.
$wpdb->query( "DROP TABLE IF EXISTS {$vaani_usage_table}" );

delete_option( 'vaani_usage_db_version' );
