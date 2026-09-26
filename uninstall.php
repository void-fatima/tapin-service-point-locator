<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleanup remains conservative by default to prevent accidental data loss.
 * Custom database tables are only dropped if explicitly requested.
 *
 * @package Tapin\ServicePointLocator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Conservative cleanup: Tables and options are preserved unless explicitly requested
// via the TAPIN_UNINSTALL_DROP_DATA constant or an explicit WordPress option.
$drop_data = defined( 'TAPIN_UNINSTALL_DROP_DATA' ) && TAPIN_UNINSTALL_DROP_DATA;

if ( $drop_data ) {
	global $wpdb;

	$service_points_table = $wpdb->prefix . 'tapin_service_points';
	$providers_table      = $wpdb->prefix . 'tapin_providers';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$service_points_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$providers_table}" );

	delete_option( 'tapin_db_version' );
	delete_option( 'tapin_settings' );
}
