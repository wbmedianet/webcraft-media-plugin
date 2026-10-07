<?php
/**
 * Removes the plugin's settings and cached checks.
 *
 * @package WebcraftMedia
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'webcraft_media_token' );
delete_option( 'webcraft_media_notice' );
delete_option( 'webcraft_media_checks' );

global $wpdb;
$table  = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
$column = is_multisite() ? 'meta_key' : 'option_name';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached release lookups have generated names.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$table} WHERE {$column} LIKE %s OR {$column} LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->esc_like( '_site_transient_webcraft_media_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_webcraft_media_' ) . '%'
	)
);
