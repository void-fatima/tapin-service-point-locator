<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Original api catalog identities; these are configuration keys, not WP route registrations. */
final class SecurityRouteLimits {
	const DEFAULTS = array( '/api/providers' => 60, '/api/providers/{provider_id}' => 60, '/api/locations' => 60, '/api/price-check' => 4, '/api/bulk' => 60, '/api/bulk-check' => 4, '/api/bulk-validate' => 30 );
	private $settings;
	private $overrides;
	public function __construct( ?callable $settings = null, ?callable $overrides = null ) { $this->settings = $settings ?? array( SecuritySettings::class, 'get' ); $this->overrides = $overrides ?? array( new RouteLimitStorage(), 'get' ); }
	public static function normalize( string $route ): string { $route = V::trim( $route ); return 0 === strpos( $route, '/api/providers/' ) ? '/api/providers/{provider_id}' : $route; }
	public function effective(): array {
		try { $saved = ( $this->settings )(); return array( V::integer( V::get( $saved, 'max_requests' ) ), V::integer( V::get( $saved, 'window_seconds' ) ) ); }
		catch ( \Throwable $error ) { return array( 4, 60 ); }
	}
	public function resolve( string $route ): ?array {
		$route = self::normalize( $route );
		try {
			$hit = V::get( ( $this->overrides )(), $route );
			if ( V::mapping( $hit ) ) { if ( ! V::truth( V::get( $hit, 'enabled', true ) ) ) { return null; } $maximum = V::integer( V::get( $hit, 'max_requests', 0 ) ); $window = V::integer( V::get( $hit, 'window_seconds', 0 ) ); if ( $maximum > 0 && $window > 0 ) { return array( $maximum, $window ); } }
		} catch ( \Throwable $error ) { /* Source ignores failed override reads. */ }
		return isset( self::DEFAULTS[$route] ) ? array( self::DEFAULTS[$route], 60 ) : $this->effective();
	}
}
