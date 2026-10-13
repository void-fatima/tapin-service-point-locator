<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** api.py::_cache_key for finite JSON values; no quote execution or provider access. */
final class PriceCacheKey {
	private static function sorted( $value ) {
		if ( PythonValue::mapping( $value ) ) {
			$fields = (array) $value; ksort( $fields, SORT_STRING );
			foreach ( $fields as &$item ) { $item = self::sorted( $item ); } unset( $item ); return (object) $fields;
		}
		return is_array( $value ) ? array_map( array( self::class, 'sorted' ), $value ) : $value;
	}
	public static function make( $provider, $params ): string {
		if ( ! PythonValue::mapping( $provider ) || ! PythonValue::mapping( $params ) ) { throw new \InvalidArgumentException( 'Cache key inputs must be objects.' ); }
		$get = static fn( $key, $default ) => PythonValue::fallback( PythonValue::get( $provider, $key ), $default );
		$material = (object) array( 'params' => (object) (array) $params, 'price_fields' => $get( 'price_fields', array() ), 'currency' => array( $get( 'price_currency_in', 'rial' ), $get( 'price_currency_out', 'rial' ) ), 'weight' => array( $get( 'weight_unit_in', 'gram' ), $get( 'weight_unit_out', 'gram' ) ) );
		return hash( 'sha256', ConfigurationJson::encode( self::sorted( $material ) ) );
	}
}
