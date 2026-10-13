<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Durable non-autoloaded WP option, plus atomic refresh lease. No transient stale-data eviction. */
final class WordPressLocationCache implements LocationCache {
	const DATA = 'tapin_price_locations_snapshot';
	const LOCK = 'tapin_price_locations_refresh';
	const LEASE_SECONDS = 300;
	private OperationalClock $clock;
	public function __construct( ?OperationalClock $clock = null ) { $this->clock = $clock ?? new OperationalClock(); }
	public static function valid( $entry ): bool {
		if ( ! V::mapping( $entry ) || ! is_numeric( V::get( $entry, 'fetched_at' ) ) || ! is_finite( (float) V::get( $entry, 'fetched_at' ) ) ) { return false; }
		$payload = V::get( $entry, 'payload' ); $cities = V::get( $payload, 'cities' );
		if ( ! V::mapping( $payload ) || ! V::listing( $cities ) || ! $cities || ! is_string( V::get( $payload, 'provider_message' ) ) ) { return false; }
		$status = V::get( $payload, 'provider_status' ); if ( null !== $status && ! is_int( $status ) && ! is_string( $status ) ) { return false; }
		foreach ( $cities as $city ) { foreach ( array( 'province_pk', 'province_name', 'city_pk', 'city_name' ) as $key ) { if ( ! is_string( V::get( $city, $key ) ) || '' === V::get( $city, $key ) ) { return false; } } $flags = V::get( $city, 'availability' ); if ( ! V::mapping( $flags ) ) { return false; } foreach ( (array) $flags as $flag ) { if ( ! is_bool( $flag ) ) { return false; } } } return true;
	}
	public function read(): ?array {
		$raw = get_option( self::DATA, null ); if ( ! is_string( $raw ) ) { return null; }
		try { $entry = ConfigurationJson::decode( $raw ); } catch ( \InvalidArgumentException $error ) { return null; } catch ( \DomainException $error ) { return null; }
		return self::valid( $entry ) ? array( 'fetched_at' => (float) $entry->fetched_at, 'payload' => $entry->payload ) : null;
	}
	public function save( array $entry ): void {
		if ( ! self::valid( (object) $entry ) ) { throw new LocationCacheError( 'Invalid location cache entry.' ); }
		$raw = ConfigurationJson::encode( (object) $entry );
		if ( ! update_option( self::DATA, $raw, false ) && get_option( self::DATA, null ) !== $raw ) { throw new LocationCacheError( 'Location snapshot could not be saved.' ); }
	}
	public function clear(): void {
		if ( null !== get_option( self::DATA, null ) && ! delete_option( self::DATA ) ) { throw new LocationCacheError( 'Location snapshot could not be cleared.' ); }
		// A concurrent owner keeps its lease: clearing data must never steal an active refresh.
	}
	/** Conditional deletion prevents expired-owner release from deleting a successor's lease. */
	private function remove_lease( string $raw ): bool {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try { $result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $raw ) ); $failed = false === $result || '' !== $wpdb->last_error; }
		finally { $wpdb->suppress_errors( $previous ); }
		if ( $failed ) { throw new LocationCacheError( 'Location refresh lease could not be released.' ); }
		wp_cache_delete( self::LOCK, 'options' ); wp_cache_delete( 'notoptions', 'options' ); return (bool) $result;
	}
	public function acquire(): ?string {
		$now = (float) $this->clock->now()->format( 'U.u' ); $current = get_option( self::LOCK, null );
		if ( null !== $current ) { $decoded = is_string( $current ) ? json_decode( $current ) : null; if ( null === $decoded || ! isset( $decoded->expires ) || $decoded->expires <= $now ) { $this->remove_lease( is_string( $current ) ? $current : maybe_serialize( $current ) ); } }
		$owner = bin2hex( random_bytes( 16 ) ); $lease = json_encode( array( 'owner' => $owner, 'expires' => $now + self::LEASE_SECONDS ) );
		if ( add_option( self::LOCK, $lease, '', false ) ) { return $owner; }
		if ( null === get_option( self::LOCK, null ) ) { throw new LocationCacheError( 'Location refresh lease could not be acquired.' ); } return null;
	}
	public function release( string $owner ): void { $raw = get_option( self::LOCK, null ); $lease = is_string( $raw ) ? json_decode( $raw ) : null; if ( null !== $lease && ( $lease->owner ?? null ) === $owner ) { $this->remove_lease( $raw ); } }
}
