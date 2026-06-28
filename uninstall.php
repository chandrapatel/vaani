<?php
/**
 * Vaani uninstall cleanup.
 *
 * @package Vaani
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vaani_settings' );

global $wpdb;

// Delete every translation post (private CPT, one per source × language).
// The CPT is not registered during uninstall, so query the column directly.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on plugin delete.
$vaani_translation_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'vaani_translation' )
);

foreach ( $vaani_translation_ids as $vaani_translation_id ) {
	wp_delete_post( (int) $vaani_translation_id, true );
}

// Delete the media-library attachments referenced by each post's audio meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on plugin delete.
$vaani_audio_meta = $wpdb->get_col(
	$wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_vaani_audio' )
);

foreach ( $vaani_audio_meta as $vaani_audio_row ) {
	$vaani_audio_map = maybe_unserialize( $vaani_audio_row );

	if ( ! is_array( $vaani_audio_map ) ) {
		continue;
	}

	foreach ( $vaani_audio_map as $vaani_attachment_id ) {
		if ( (int) $vaani_attachment_id > 0 ) {
			wp_delete_attachment( (int) $vaani_attachment_id, true );
		}
	}
}

// Remove leftover Vaani meta from source posts.
foreach ( array( '_vaani_target_langs', '_vaani_audio', '_vaani_audio_hash', '_vaani_audio_status' ) as $vaani_meta_key ) {
	delete_post_meta_by_key( $vaani_meta_key );
}

// Drop the usage log table and its schema-version marker (Phase 5).
$vaani_usage_table = $wpdb->prefix . 'vaani_usage';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifier is built from $wpdb->prefix; DROP cannot use placeholders.
$wpdb->query( "DROP TABLE IF EXISTS {$vaani_usage_table}" );

delete_option( 'vaani_usage_db_version' );
