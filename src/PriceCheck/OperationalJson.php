<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Source log/cache JSON contract, including its lossy 20,000-codepoint truncation. */
final class OperationalJson {
	public static function dump( $value ): string {
		$text = ConfigurationJson::encode( $value );
		return PythonValue::length( $text ) > 20000 ? PythonValue::slice( $text, 20000 ) . '…[truncated]' : $text;
	}
	public static function parse( $text ) {
		if ( ! is_string( $text ) || '' === $text ) { return null; }
		try { return ConfigurationJson::decode( $text ); }
		catch ( \InvalidArgumentException $error ) { return $text; }
	}
}
