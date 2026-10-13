<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Persisted defaults only; no rate-limit enforcement. */
final class SecuritySettings {
	public static function defaults(): array { return array( 'max_requests' => 4, 'window_seconds' => 60 ); }
	public static function clean( $raw ): array {
		try { $maximum = PythonValue::integer( PythonValue::get( $raw, 'max_requests' ) ); }
		catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( 'تعداد درخواست باید عدد باشد.' ); }
		try { $window = PythonValue::integer( PythonValue::get( $raw, 'window_seconds' ) ); }
		catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( 'بازه زمانی باید عدد باشد (ثانیه).' ); }
		if ( $maximum < 1 || $maximum > 100000 ) { throw new \InvalidArgumentException( 'تعداد درخواست باید بین 1 تا 100000 باشد.' ); }
		if ( $window < 1 || $window > 86400 ) { throw new \InvalidArgumentException( 'بازه زمانی باید بین 1 ثانیه تا 86400 ثانیه باشد.' ); }
		return array( 'max_requests' => $maximum, 'window_seconds' => $window );
	}
	public static function get(): array {
		$rows = ConfigurationDatabase::rows( 'SELECT data_json FROM ' . ConfigurationDatabase::table( 'security_settings' ) . ' WHERE id = 1' );
		if ( ! $rows ) { return self::defaults(); }
		try {
			$raw = ConfigurationJson::decode( $rows[0]['data_json'] );
			return PythonValue::mapping( $raw ) ? self::clean( $raw ) : self::defaults();
		} catch ( \InvalidArgumentException $error ) { return self::defaults(); }
	}
	public static function save( $raw ): array {
		$cleaned = self::clean( $raw );
		ConfigurationDatabase::upsert( 'security_settings', array( 'id' => 1, 'data_json' => ConfigurationJson::encode( (object) $cleaned ) ), array( 'data_json' ) ); return $cleaned;
	}
}
