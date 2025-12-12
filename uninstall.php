<?php
/**
 * Uninstall Dragon Internal Links
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package DragonInternalLinks
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop all plugin tables.
$tables = [
	$wpdb->prefix . 'dil_links',
	$wpdb->prefix . 'dil_stats',
	$wpdb->prefix . 'dil_suggestions',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete all plugin options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dil\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'dil_scheduled_scan' );
wp_clear_scheduled_hook( 'dil_generate_suggestions' );

// Delete any transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dil\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dil\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
