<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** storage.py cache-settings singleton, shared by all three retention policies. */
final class CacheSettings {
	public static function defaults(): array {
		return array( 'price' => 86400, 'price_retention' => 864000, 'locations' => 21600, 'quote_logs' => 604800, 'security_events' => 2592000 );
	}
	public static function get(): array {
		$values = self::defaults();
		// The source deliberately falls back to defaults when this optional read fails.
		try { $rows = ConfigurationDatabase::rows( 'SELECT data_json FROM ' . ConfigurationDatabase::table( 'cache_settings' ) . ' WHERE id = 1' ); }
		catch ( StorageError $error ) { return $values; }
		if ( ! $rows ) { return $values; }
		try { $raw = ConfigurationJson::decode( $rows[0]['data_json'] ); }
		catch ( \InvalidArgumentException $error ) { return $values; }
		if ( ! PythonValue::mapping( $raw ) ) { return $values; }
		foreach ( $values as $key => $default ) {
			$value = PythonValue::get( $raw, $key );
			if ( is_int( $value ) || is_float( $value ) ) { $values[$key] = max( 1, min( 2592000, PythonValue::integer( $value ) ) ); }
		}
		return $values;
	}
	public static function save( $raw ): array {
		$values = self::get();
		if ( ! PythonValue::mapping( $raw ) ) { throw new \InvalidArgumentException( 'مدت کش باید عدد باشد.' ); }
		foreach ( $values as $key => $default ) {
			if ( ! PythonValue::has( $raw, $key ) ) { continue; }
			try { $value = PythonValue::integer( PythonValue::get( $raw, $key ) ); }
			catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( 'مدت کش باید عدد باشد.' ); }
			if ( $value < 1 || $value > 2592000 ) { throw new \InvalidArgumentException( 'مدت کش باید بین ۱ ثانیه و ۳۰ روز باشد.' ); }
			$values[$key] = $value;
		}
		ConfigurationDatabase::upsert( 'cache_settings', array( 'id' => 1, 'data_json' => ConfigurationJson::encode( (object) $values ) ), array( 'data_json' ) );
		return $values;
	}
}
