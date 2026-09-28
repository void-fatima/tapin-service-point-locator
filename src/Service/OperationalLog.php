<?php
namespace Tapin\ServicePointLocator\Service;

defined( 'ABSPATH' ) || exit;

/** Small structured events; no addresses, phone numbers, raw uploads or credentials. */
final class OperationalLog {
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'tapin_logs'; }

	public static function record( string $event, array $context = array() ): bool {
		if ( ! in_array( $event, array( 'import_completed', 'import_failed', 'validation_failed', 'security_event', 'bulk_updated', 'system_error', 'export_completed', 'export_failed' ), true ) ) { return false; }
		global $wpdb;
		$safe = array();
		foreach ( array( 'job_id', 'provider_id', 'inserted', 'updated', 'failed', 'skipped' ) as $key ) {
			if ( isset( $context[$key] ) ) { $safe[$key] = absint( $context[$key] ); }
		}
		if ( in_array( $event, array( 'export_completed', 'export_failed' ), true ) ) {
			$safe['format'] = 'xlsx';
			if ( isset( $context['rows'] ) ) { $safe['rows'] = absint( $context['rows'] ); }
			$filters = (array) ( $context['filters'] ?? array() ); $safe['filters'] = array();
			foreach ( array( 'provider_id', 'province', 'city', 'status', 'issue', 'has_coordinates' ) as $key ) {
				if ( isset( $filters[$key] ) && is_scalar( $filters[$key] ) ) { $safe['filters'][$key] = sanitize_text_field( (string) $filters[$key] ); }
			}
			// Search may contain a phone/address: record its presence, not personal text.
			$safe['filters']['search_applied'] = ! empty( $filters['search'] );
		}
		return false !== $wpdb->insert( self::table(), array(
			'event' => $event, 'user_id' => get_current_user_id(),
			'context' => wp_json_encode( $safe ), 'created_at' => current_time( 'mysql', true ),
		), array( '%s', '%d', '%s', '%s' ) );
	}

	public static function recent_exports() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, event, user_id, context, created_at FROM " . self::table() . " WHERE event IN ('export_completed','export_failed') ORDER BY id DESC LIMIT 50", ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'export_history', 'دریافت تاریخچه خروجی انجام نشد.', array( 'status' => 503 ) ); }
		return array_map( static function( $row ) {
			$context = json_decode( $row['context'], true ) ?: array();
			$row['context'] = array_intersect_key( $context, array_flip( array( 'format', 'rows', 'filters' ) ) );
			return $row;
		}, $rows ?: array() );
	}

	public static function cleanup(): void {
		global $wpdb;
		// Indexed retention cutoff, in UTC and calendar months.
		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE created_at < UTC_TIMESTAMP() - INTERVAL 3 MONTH' );
	}
}
