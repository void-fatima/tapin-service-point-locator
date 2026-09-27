<?php
namespace Tapin\ServicePointLocator\Service;

defined( 'ABSPATH' ) || exit;

/** Small structured events; no addresses, phone numbers, raw uploads or credentials. */
final class OperationalLog {
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'tapin_logs'; }

	public static function record( string $event, array $context = array() ): bool {
		if ( ! in_array( $event, array( 'import_completed', 'import_failed', 'validation_failed', 'security_event', 'bulk_updated', 'system_error' ), true ) ) { return false; }
		global $wpdb;
		$safe = array();
		foreach ( array( 'job_id', 'provider_id', 'inserted', 'updated', 'failed', 'skipped' ) as $key ) {
			if ( isset( $context[$key] ) ) { $safe[$key] = absint( $context[$key] ); }
		}
		return false !== $wpdb->insert( self::table(), array(
			'event' => $event, 'user_id' => get_current_user_id(),
			'context' => wp_json_encode( $safe ), 'created_at' => current_time( 'mysql', true ),
		), array( '%s', '%d', '%s', '%s' ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		// Indexed retention cutoff, in UTC and calendar months.
		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE created_at < UTC_TIMESTAMP() - INTERVAL 3 MONTH' );
	}
}
