<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source admin inspection/aggregation only; never automatically blocks an offender. */
final class SecurityReports {
	private SecurityRouteLimits $routes; private SecurityRateLimiter $limiter; private OperationalClock $clock; private $events;
	public function __construct( SecurityRateLimiter $limiter, ?SecurityRouteLimits $routes = null, ?OperationalClock $clock = null, ?callable $events = null ) { $this->limiter = $limiter; $this->routes = $routes ?? new SecurityRouteLimits(); $this->clock = $clock ?? new OperationalClock(); $this->events = $events ?? array( SecurityEventStorage::class, 'list_rows' ); }
	public static function catalog(): array { return json_decode( file_get_contents( __DIR__ . '/rest-catalog.json' ), true, 512, JSON_THROW_ON_ERROR ); }
	public static function entry( string $route, $overrides ): array {
		$catalog = self::catalog()['PUBLIC_API_CATALOG'][$route]; $base = array( 'route' => $route, 'methods' => $catalog['methods'], 'title' => $catalog['title'] ?: $route, 'description' => $catalog['description'] ?: '', 'guarded' => (bool) $catalog['guarded'] ); $hit = V::get( $overrides, $route );
		if ( V::mapping( $hit ) ) { $off = ! V::truth( V::get( $hit, 'enabled', true ) ); return $base + array( 'max_requests' => $off ? null : V::integer( V::get( $hit, 'max_requests' ) ), 'window_seconds' => $off ? null : V::integer( V::get( $hit, 'window_seconds' ) ), 'custom' => true, 'unlimited' => $off, 'source' => $off ? 'off' : 'custom' ); }
		return $base + array( 'max_requests' => $catalog['default_max'], 'window_seconds' => $catalog['default_max'] ? ( $catalog['default_window'] ?: 60 ) : null, 'custom' => false, 'unlimited' => false, 'source' => 'default' );
	}
	public function active( string $query, int $limit ): array {
		$now = SecurityTime::milliseconds( $this->clock ); $recent = ( $this->events )( 'rate_limit_exceeded', '', 500 ); $seen = $rows = array();
		foreach ( $recent as $event ) { $route = SecurityRouteLimits::normalize( $event->route ); if ( ! isset( SecurityRouteLimits::DEFAULTS[$route] ) ) { continue; } $key = json_encode( array( $event->ip, $event->client_id, $event->client_fingerprint, $route ) ); if ( isset( $seen[$key] ) ) { continue; } $seen[$key] = true; if ( '' !== $query && false === strpos( $event->ip, $query ) ) { continue; } $resolved = $this->routes->resolve( $route ); if ( null === $resolved ) { continue; } [$max, $window] = $resolved; $state = $this->limiter->inspect_identity( $event->ip, $event->client_id, $event->client_fingerprint, $route ); if ( ! $state['limited'] ) { continue; }
			$times = array(); foreach ( $recent as $item ) { if ( $item->ip === $event->ip && $item->client_id === $event->client_id && $item->client_fingerprint === $event->client_fingerprint && SecurityRouteLimits::normalize( $item->route ) === $route && $item->created_at_ms > $now - $window * 1000 ) { $times[] = $item->created_at_ms; } }
			$rows[] = array( 'ip' => $event->ip, 'client_id' => $event->client_id, 'fingerprint' => $event->client_fingerprint, 'route' => $route, 'last_at_ms' => $event->created_at_ms, 'started_at_ms' => $times ? min( $times ) : $state['ends_at_ms'] - $window * 1000, 'ends_at_ms' => $state['ends_at_ms'], 'max_requests' => $max, 'window_seconds' => $window, 'dimensions' => array_values( array_map( static fn( $dim ) => $dim['dimension'], array_filter( $state['dimensions'], static fn( $dim ) => $dim['limited'] ) ) ) ); if ( count( $rows ) >= max( 1, min( $limit, 200 ) ) ) { break; }
		} [$max, $window] = $this->routes->effective(); return array( 'limits' => $rows, 'max_requests' => $max, 'window_seconds' => $window );
	}
	public function offenders( string $query, int $limit, int $threshold ): array {
		$threshold = max( 1, min( $threshold, 1000 ) ); [, $window] = $this->routes->effective(); $bucket = max( 1000, $window * 1000 ); $groups = array();
		foreach ( ( $this->events )( 'rate_limit_exceeded', '', 2000 ) as $event ) { $key = json_encode( array( $event->ip, $event->client_id, $event->client_fingerprint ) ); if ( ! isset( $groups[$key] ) ) { $groups[$key] = array( 'identity' => $event, 'buckets' => array(), 'hits' => 0, 'first' => $event->created_at_ms, 'last' => $event->created_at_ms, 'route' => $event->route ); } $row = &$groups[$key]; $row['buckets'][(int) floor( $event->created_at_ms / $bucket )] = true; $row['hits']++; $row['first'] = min( $row['first'], $event->created_at_ms ); $row['last'] = max( $row['last'], $event->created_at_ms ); $row['route'] = V::fallback( $event->route, $row['route'] ); unset( $row ); }
		$rows = array(); foreach ( $groups as $row ) { $event = $row['identity']; if ( '' !== $query && false === strpos( $event->ip, $query ) ) { continue; } if ( count( $row['buckets'] ) < $threshold ) { continue; } $rows[] = array( 'ip' => $event->ip, 'client_id' => $event->client_id, 'fingerprint' => $event->client_fingerprint, 'route' => $row['route'], 'windows' => count( $row['buckets'] ), 'hits' => $row['hits'], 'first_at_ms' => $row['first'], 'last_at_ms' => $row['last'], 'window_seconds' => $window ); if ( count( $rows ) >= max( 1, min( $limit, 200 ) ) ) { break; } } return array( 'offenders' => $rows, 'min_windows' => $threshold, 'window_seconds' => $window );
	}
}
