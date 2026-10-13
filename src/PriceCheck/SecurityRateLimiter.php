<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** security/rate_limiter.py; dimensions increment independently, including denied requests. */
final class SecurityRateLimiter {
	private RateLimitStore $store;
	private OperationalClock $clock;
	private int $maximum;
	private int $window;
	private $limits;
	private $route_key;
	public function __construct( RateLimitStore $store, ?OperationalClock $clock = null, int $maximum = 4, int $window = 60, ?callable $limits = null, ?callable $route_key = null ) {
		$this->store = $store; $this->clock = $clock ?? new OperationalClock(); $this->maximum = $maximum; $this->window = $window; $this->limits = $limits; $this->route_key = $route_key;
	}
	private function route( string $route ): string { if ( null !== $this->route_key ) { try { $normalized = ( $this->route_key )( $route ); if ( PythonValue::truth( $normalized ) ) { return PythonValue::string( $normalized ); } } catch ( \Throwable $error ) {} } return $route; }
	private function limits( string $route ): ?array {
		if ( null !== $this->limits ) { try { $hit = ( $this->limits )( $route ); if ( null === $hit ) { return null; } $maximum = PythonValue::integer( $hit[0] ); $window = PythonValue::integer( $hit[1] ); if ( $maximum > 0 && $window > 0 ) { return array( $maximum, $window ); } } catch ( \Throwable $error ) {} }
		return array( $this->maximum, $this->window );
	}
	private function key( string $dimension, string $route, string ...$parts ): string { return 'tapin:rate:' . $dimension . ':' . ( '' !== $route ? $route . ':' : '' ) . implode( ':', $parts ); }
	public function dimensions( string $ip, string $id, string $fp, int $present, string $route = '' ): array {
		$route = $this->route( $route ); $dims = array();
		if ( '' !== $ip ) { $dims[] = array( 'ip', $this->key( 'ip', $route, $ip ) ); }
		if ( '' !== $id ) { $dims[] = array( 'client', $this->key( 'client', $route, $id ) ); }
		if ( $present > 0 || '' !== $id ) { $dims[] = array( 'fp', $this->key( 'fp', $route, $fp ) ); }
		if ( '' !== $ip && '' !== $id ) { $dims[] = array( 'ip_client', $this->key( 'ip_client', $route, $ip, $id ) ); } return $dims;
	}
	public function consume( string $ip, string $id, string $fp, int $present, string $route = '' ): array {
		$route = $this->route( $route ); $now = $this->clock->milliseconds(); $limits = $this->limits( $route ); $exceeded = array(); $retry = 0;
		if ( null !== $limits ) { [$maximum, $window] = $limits; foreach ( $this->dimensions( $ip, $id, $fp, $present, $route ) as [$dimension, $key] ) { [$count, $ttl] = $this->store->increment( $key, $window, $now ); if ( $count > $maximum ) { $exceeded[] = $dimension; $retry = max( $retry, $ttl ); } } }
		return array( 'allowed' => ! $exceeded, 'retry_after' => $exceeded ? max( 1, $retry ) : 0, 'exceeded_dimensions' => $exceeded );
	}
	public function reset_identity( string $ip = '', string $id = '', string $fp = '', string $route = '' ): void {
		$route = $this->route( $route ); foreach ( array( 'ip' => $ip, 'client' => $id, 'fp' => $fp ) as $dim => $value ) { if ( '' !== $value ) { $this->store->delete( $this->key( $dim, $route, $value ) ); } }
		if ( '' !== $ip && '' !== $id ) { $this->store->delete( $this->key( 'ip_client', $route, $ip, $id ) ); }
	}
	public function reset_all(): void { $this->store->clear(); }
	public function inspect_identity( string $ip = '', string $id = '', string $fp = '', string $route = '' ): array {
		$route = $this->route( $route ); $now = $this->clock->milliseconds(); $limits = $this->limits( $route ); $dims = array(); $ends = 0;
		if ( null === $limits ) { return array( 'limited' => false, 'ends_at_ms' => 0, 'dimensions' => array() ); } [$maximum, $window] = $limits;
		$candidates = array(); foreach ( array( 'ip' => $ip, 'client' => $id, 'fp' => $fp ) as $dim => $value ) { if ( '' !== $value ) { $candidates[] = array( $dim, $this->key( $dim, $route, $value ) ); } } if ( '' !== $ip && '' !== $id ) { $candidates[] = array( 'ip_client', $this->key( 'ip_client', $route, $ip, $id ) ); }
		foreach ( $candidates as [$dimension, $key] ) { try { [$count, $ttl] = $this->store->read( $key, $window, $now ); } catch ( \Throwable $error ) { $count = $ttl = 0; } $limited = $count > $maximum; if ( $limited ) { $ends = max( $ends, $now + max( 1, (int) $ttl ) * 1000 ); } $dims[] = array( 'dimension' => $dimension, 'count' => $count, 'limited' => $limited ); }
		return array( 'limited' => (bool) array_filter( $dims, static fn( $dim ) => $dim['limited'] ), 'ends_at_ms' => $ends, 'dimensions' => $dims );
	}
	public function peek( string $ip, string $id, string $fp, int $present, string $route = '' ): \stdClass {
		$route = $this->route( $route ); $now = $this->clock->milliseconds(); $limits = $this->limits( $route ); $out = new \stdClass();
		if ( null !== $limits ) { foreach ( $this->dimensions( $ip, $id, $fp, $present, $route ) as [$dim, $key] ) { $out->$dim = $this->store->read( $key, $limits[1], $now ); } } return $out;
	}
}
