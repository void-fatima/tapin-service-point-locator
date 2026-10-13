<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Shared fixed windows in non-autoloaded options. Atomic insert/value-CAS, no object-cache reads. */
final class WordPressRateLimitStore implements RateLimitStore {
	const PREFIX = 'tapin_price_rate_';
	private function name( string $key ): string {
		if ( 0 !== strpos( $key, 'tapin:rate:' ) ) { throw new \InvalidArgumentException( 'Invalid rate counter namespace.' ); }
		return self::PREFIX . hash( 'sha256', $key );
	}
	private function table(): string { global $wpdb; return '`' . str_replace( '`', '``', $wpdb->options ) . '`'; }
	private function invalidate( string $name ): void { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	private function raw( string $name ): ?string {
		$rows = ConfigurationDatabase::rows( 'SELECT option_value FROM ' . $this->table() . ' WHERE option_name = %s', array( $name ) );
		return $rows ? (string) $rows[0]['option_value'] : null;
	}
	private static function slot( string $raw ): array {
		if ( ! preg_match( '/^([1-9][0-9]*):(-?[0-9]+)$/D', $raw, $parts ) ) { throw new StorageError( 'rate_counter_decode' ); }
		try { return array( PythonValue::integer( $parts[1] ), PythonValue::integer( $parts[2] ) ); }
		catch ( \Throwable $error ) { throw new StorageError( 'rate_counter_decode' ); }
	}
	private static function ttl( int $start, int $window, int $now ): int { return max( 1, (int) ceil( ( $window * 1000 - ( $now - $start ) ) / 1000 ) ); }
	public function increment( string $key, int $window_seconds, int $now_ms ): array {
		if ( $window_seconds <= 0 ) { throw new \InvalidArgumentException( 'Rate window must be positive.' ); } $name = $this->name( $key );
		for ( $attempt = 0; $attempt < 128; $attempt++ ) {
			$raw = $this->raw( $name );
			if ( null === $raw ) {
				$inserted = ConfigurationDatabase::query( 'INSERT IGNORE INTO ' . $this->table() . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", array( $name, '1:' . $now_ms ) );
				if ( $inserted ) { $this->invalidate( $name ); return array( 1, $window_seconds ); } continue;
			}
			[$count, $start] = self::slot( $raw );
			if ( $now_ms - $start >= $window_seconds * 1000 ) { $count = 0; $start = $now_ms; }
			if ( PHP_INT_MAX === $count ) { throw new StorageError( 'rate_counter_overflow' ); }
			$count++; $next = $count . ':' . $start;
			$updated = ConfigurationDatabase::query( 'UPDATE ' . $this->table() . ' SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s', array( $next, $name, $raw ) );
			if ( $updated ) { $this->invalidate( $name ); return array( $count, self::ttl( $start, $window_seconds, $now_ms ) ); }
		}
		throw new StorageError( 'rate_counter_contention' );
	}
	public function read( string $key, int $window_seconds, int $now_ms ): array {
		$raw = $this->raw( $this->name( $key ) ); if ( null === $raw ) { return array( 0, 0 ); } [$count, $start] = self::slot( $raw );
		return $now_ms - $start >= $window_seconds * 1000 ? array( 0, 0 ) : array( $count, self::ttl( $start, $window_seconds, $now_ms ) );
	}
	public function delete( string $key ): void { $name = $this->name( $key ); ConfigurationDatabase::query( 'DELETE FROM ' . $this->table() . ' WHERE option_name = %s', array( $name ) ); $this->invalidate( $name ); }
	public function clear(): void {
		global $wpdb;
		// Exact option names prevent wildcard deletion or touching unrelated price/locator options.
		$rows = ConfigurationDatabase::rows( 'SELECT option_name FROM ' . $this->table() . ' WHERE option_name LIKE %s', array( $wpdb->esc_like( self::PREFIX ) . '%' ) );
		foreach ( $rows as $row ) { if ( preg_match( '/^' . self::PREFIX . '[0-9a-f]{64}$/D', $row['option_name'] ) ) { ConfigurationDatabase::query( 'DELETE FROM ' . $this->table() . ' WHERE option_name = %s', array( $row['option_name'] ) ); $this->invalidate( $row['option_name'] ); } }
	}
}
