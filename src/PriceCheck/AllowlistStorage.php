<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** MySQL allowlist persistence; no IP normalization, authorization or request bypass. */
final class AllowlistStorage {
	public static function contains( string $ip ): bool { return (bool) ConfigurationDatabase::rows( 'SELECT 1 FROM ' . ConfigurationDatabase::table( 'security_allowlist' ) . ' WHERE ip = %s', array( $ip ) ); }
	public static function add( string $ip, string $reason, string $created_by, int $now_ms ): int {
		ConfigurationDatabase::upsert( 'security_allowlist', array( 'ip' => $ip, 'reason' => $reason, 'created_at_ms' => $now_ms, 'created_by' => $created_by ), array( 'reason' ) );
		$rows = ConfigurationDatabase::rows( 'SELECT id FROM ' . ConfigurationDatabase::table( 'security_allowlist' ) . ' WHERE ip = %s', array( $ip ) );
		if ( ! $rows ) { throw new StorageError( 'allowlist_id' ); } return $rows[0]['id'];
	}
	public static function delete( int $id ): bool { return ConfigurationDatabase::delete( 'security_allowlist', array( 'id' => $id ) ) > 0; }
	public static function list_rows( string $query = '', $limit = 200 ): array {
		$args = '' !== $query ? array_fill( 0, 2, '%' . $query . '%' ) : array();
		$args[] = max( 1, min( PythonValue::integer( PythonValue::fallback( $limit, 200 ) ), 1000 ) );
		return ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'security_allowlist' ) . ( '' !== $query ? ' WHERE ip LIKE %s OR reason LIKE %s' : '' ) . ' ORDER BY id DESC LIMIT %d', $args );
	}
}
