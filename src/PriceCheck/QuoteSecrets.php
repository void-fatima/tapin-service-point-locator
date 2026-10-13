<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** A request-local scrubber; never emits credentials or performs credential persistence. */
final class QuoteSecrets {
	private array $values = array();
	public function remember( string $value ): void { if ( '' !== $value ) { $this->values[] = $value; } }
	public function clean( $value, bool $response_keys = false ) {
		if ( PythonValue::mapping( $value ) || is_array( $value ) ) {
			$out = array();
			foreach ( (array) $value as $key => $item ) {
				if ( in_array( strtolower( (string) $key ), array( 'token', 'authorization', 'proxy-authorization' ), true ) ) { continue; }
				$out[$response_keys && is_string( $key ) ? $this->clean( $key ) : $key] = $this->clean( $item, $response_keys );
			}
			return PythonValue::mapping( $value ) ? (object) $out : array_values( $out );
		}
		if ( is_string( $value ) ) {
			foreach ( $this->values as $secret ) { $value = str_replace( $secret, '[redacted]', $value ); }
		}
		return $value;
	}
}
