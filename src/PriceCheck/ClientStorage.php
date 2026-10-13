<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Optional issued-client registry; no client-ID generation or fingerprint computation. */
final class ClientStorage {
	public static function remember( string $client_id, int $now_ms ): void {
		// Equivalent to INSERT IGNORE for a valid row's duplicate key, without hiding other SQL errors.
		ConfigurationDatabase::query( 'INSERT INTO ' . ConfigurationDatabase::table( 'clients' ) . ' (client_id, first_seen_ms) VALUES (%s, %d) ON DUPLICATE KEY UPDATE client_id = client_id', array( $client_id, $now_ms ) );
	}
	public static function exists( string $client_id ): bool { return (bool) ConfigurationDatabase::rows( 'SELECT 1 FROM ' . ConfigurationDatabase::table( 'clients' ) . ' WHERE client_id = %s', array( $client_id ) ); }
}
