<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** MysqlSecurityEventStore: prune on writes using the event's timestamp, never on listing. */
final class SecurityEventStorage {
	public static function record( SecurityEvent $event ): void {
		$data = get_object_vars( $event ); unset( $data['id'] ); LogSafety::assert_safe( $data ); LogSafety::endpoint( $event->route );
		ConfigurationDatabase::insert( 'security_events', $data );
		ConfigurationDatabase::query( 'DELETE FROM ' . ConfigurationDatabase::table( 'security_events' ) . ' WHERE created_at_ms < %d', array( $event->created_at_ms - CacheSettings::get()['security_events'] * 1000 ) );
	}
	public static function list_rows( string $kind = '', string $value = '', $limit = 200 ): array {
		$where = $args = array();
		if ( '' !== $kind ) { $where[] = 'kind = %s'; $args[] = $kind; }
		if ( '' !== $value ) { $where[] = '(ip = %s OR client_id = %s OR client_fingerprint = %s)'; $args = array_merge( $args, array_fill( 0, 3, $value ) ); }
		$args[] = max( 1, min( PythonValue::integer( PythonValue::fallback( $limit, 200 ) ), 1000 ) );
		$rows = ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'security_events' ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY id DESC LIMIT %d', $args );
		foreach ( $rows as &$row ) { $row['detail'] = PythonValue::fallback( $row['detail'], '' ); $row = new SecurityEvent( $row ); } unset( $row ); return $rows;
	}
	public static function clear(): int { return ConfigurationDatabase::query( 'DELETE FROM ' . ConfigurationDatabase::table( 'security_events' ) ); }
}
