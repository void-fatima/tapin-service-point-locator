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

wp_clear_scheduled_hook( 'tapin_cleanup_imports' );
wp_clear_scheduled_hook( 'tapin_geocode_points' );
	wp_clear_scheduled_hook( 'tapin_cleanup_logs' );

// Conservative cleanup: Tables and options are preserved unless explicitly requested
// via the TAPIN_UNINSTALL_DROP_DATA constant or an explicit WordPress option.
$drop_data = defined( 'TAPIN_UNINSTALL_DROP_DATA' ) && TAPIN_UNINSTALL_DROP_DATA;

if ( $drop_data ) {
	global $wpdb;

	$service_points_table = $wpdb->prefix . 'tapin_service_points';
	$providers_table      = $wpdb->prefix . 'tapin_providers';
	$imports_table        = $wpdb->prefix . 'tapin_imports';
	// Remove only private staging files created by this plugin.
	$jobs = $wpdb->get_col( "SELECT data FROM {$imports_table}" );
	foreach ( $jobs ?: array() as $json ) {
		$data = json_decode( $json, true );
		$path = $data['path'] ?? '';
		if ( $path && realpath( dirname( $path ) ) === realpath( sys_get_temp_dir() ) && 0 === strpos( basename( $path ), 'tapin-import-' ) ) { wp_delete_file( $path ); }
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$service_points_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$providers_table}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$imports_table}" );

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tapin_logs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tapin_geocoding_jobs" );
	delete_option( 'tapin_geocoding_next_request' );
	delete_option( 'tapin_db_version' );
	delete_option( 'tapin_settings' );
	delete_option( 'tapin_provider_styles' );
	delete_option( 'tapin_directory_snapshot' );
}
