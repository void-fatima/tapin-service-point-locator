<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** MySQL repository semantics; duplicates remain separate rows, never automatically blocked. */
final class BlockedClientStorage {
	private static function row( array $row ): BlockedClient { $row['is_active'] = (bool) $row['is_active']; return new BlockedClient( $row ); }
	public static function find_active( string $type, string $value, int $now_ms ): ?BlockedClient {
		$rows = ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'blocked_clients' ) . ' WHERE type = %s AND value = %s AND is_active = 1 AND (expires_at_ms IS NULL OR expires_at_ms > %d) ORDER BY id DESC LIMIT 1', array( $type, $value, $now_ms ) );
		return $rows ? self::row( $rows[0] ) : null;
	}
	public static function add( BlockedClient $entry ): BlockedClient {
		$data = get_object_vars( $entry ); unset( $data['id'] ); ConfigurationDatabase::insert( 'blocked_clients', $data );
		$entry->id = ConfigurationDatabase::insert_id(); return $entry;
	}
	public static function set_active( int $id, bool $is_active ): bool { return ConfigurationDatabase::update( 'blocked_clients', array( 'is_active' => $is_active ), array( 'id' => $id ) ) > 0; }
	public static function update( int $id, string $reason, ?int $expires_at_ms ): bool { return ConfigurationDatabase::update( 'blocked_clients', array( 'reason' => $reason, 'expires_at_ms' => $expires_at_ms ), array( 'id' => $id ) ) > 0; }
	public static function delete( int $id ): bool { return ConfigurationDatabase::delete( 'blocked_clients', array( 'id' => $id ) ) > 0; }
	public static function list_rows( string $query = '', string $type = '', string $active = '', int $now_ms = 0, $limit = 200 ): array {
		$where = $args = array();
		if ( '' !== $query ) { $where[] = '(value LIKE %s OR reason LIKE %s OR created_by LIKE %s)'; $args = array_fill( 0, 3, '%' . $query . '%' ); }
		if ( '' !== $type ) { $where[] = 'type = %s'; $args[] = $type; }
		if ( '1' === $active ) { $where[] = 'is_active = 1 AND (expires_at_ms IS NULL OR expires_at_ms > %d)'; $args[] = $now_ms; }
		elseif ( '0' === $active ) { $where[] = '(is_active = 0 OR (expires_at_ms IS NOT NULL AND expires_at_ms <= %d))'; $args[] = $now_ms; }
		$args[] = max( 1, min( PythonValue::integer( PythonValue::fallback( $limit, 200 ) ), 1000 ) );
		return array_map( array( self::class, 'row' ), ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'blocked_clients' ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY id DESC LIMIT %d', $args ) );
	}
}
