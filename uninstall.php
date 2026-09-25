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

/*
 * Clean up one site. Respect the site owner's data: nothing is removed unless
 * they explicitly opted in (the "Delete all data on uninstall" setting) on that
 * site. Without the opt-in, tables and options survive so a reinstall picks up
 * exactly where it left off.
 */
$dragoninternallinks_uninstall_site = static function (): void {
	global $wpdb;

	if ( ! get_option( 'dragoninternallinks_delete_data_on_uninstall' ) ) {
		return;
	}

	// Drop all plugin tables.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Schema removal on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'dil_links' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'dil_stats' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'dil_suggestions' ) );
	// phpcs:enable

	// Delete all plugin options (current namespace-derived prefix and the pre-1.0.1
	// dil_ prefix, in case an install was removed before the migration ran).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dragoninternallinks\_%' OR option_name LIKE 'dil\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Clear the daily-scan cron (current and pre-1.0.1 hook names).
	wp_clear_scheduled_hook( 'dragoninternallinks_daily_scan' );
	wp_clear_scheduled_hook( 'dil_daily_scan' );

	// Delete any transients (both prefixes).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dragoninternallinks\_%' OR option_name LIKE '%\_transient\_dil\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dragoninternallinks\_%' OR option_name LIKE '%\_transient\_timeout\_dil\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $dragoninternallinks_site_id ) {
		switch_to_blog( (int) $dragoninternallinks_site_id );
		$dragoninternallinks_uninstall_site();
		restore_current_blog();
	}
} else {
	$dragoninternallinks_uninstall_site();
}
