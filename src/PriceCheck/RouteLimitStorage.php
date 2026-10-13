<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Original /api/ catalog identities are configuration keys, not registered WP routes. */
final class RouteLimitStorage {
	private OperationalClock $clock;
	public function __construct( ?OperationalClock $clock = null ) { $this->clock = $clock ?? new OperationalClock(); }
	public static function enabled( $value ): bool {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return PythonValue::truth( $value ); }
		$text = strtolower( PythonValue::text( PythonValue::fallback( $value, '' ) ) );
		if ( in_array( $text, array( '0', 'false', 'no', 'n', 'off', 'disabled' ), true ) ) { return false; }
		return true;
	}
	public function get(): \stdClass {
		$rows = ConfigurationDatabase::rows( 'SELECT route, max_requests, window_seconds, enabled FROM ' . ConfigurationDatabase::table( 'route_limits' ) ); $out = new \stdClass();
		foreach ( $rows as $row ) { $out->{$row['route']} = (object) array( 'max_requests' => $row['max_requests'], 'window_seconds' => $row['window_seconds'], 'enabled' => (bool) $row['enabled'] ); }
		return $out;
	}
	public function save( $route, $max_requests, $window_seconds, $enabled = true ): array {
		$route = PythonValue::text( PythonValue::fallback( $route, '' ) );
		if ( 0 !== strpos( $route, '/api/' ) ) { throw new \InvalidArgumentException( 'مسیر API معتبر نیست.' ); }
		$values = SecuritySettings::clean( (object) array( 'max_requests' => $max_requests, 'window_seconds' => $window_seconds ) );
		$cleaned = array( 'route' => $route, 'max_requests' => $values['max_requests'], 'window_seconds' => $values['window_seconds'], 'enabled' => self::enabled( $enabled ) );
		ConfigurationDatabase::upsert( 'route_limits', $cleaned + array( 'updated_at_ms' => $this->clock->milliseconds() ), array( 'max_requests', 'window_seconds', 'enabled', 'updated_at_ms' ) ); return $cleaned;
	}
	public function delete( $route ): bool { return ConfigurationDatabase::delete( 'route_limits', array( 'route' => PythonValue::text( PythonValue::fallback( $route, '' ) ) ) ) > 0; }
}
