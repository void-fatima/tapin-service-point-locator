<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** records.py public/admin presentation; validation stays in existing domain services. */
final class ProviderSchema {
	public static function fold( string $text ): string { static $map = null; if ( null === $map ) { $map = json_decode( file_get_contents( __DIR__ . '/unicode-casefold.json' ), true, 512, JSON_THROW_ON_ERROR ); } return strtr( $text, $map ); }
	public static function rules( $provider ): array { $out = array(); foreach ( V::fallback( V::get( $provider, 'rules' ), array() ) as $rule ) { if ( V::mapping( $rule ) ) { $row = (array) $rule; unset( $row['name'] ); $out[] = (object) $row; } } return $out; }
	public static function options( $param ): array { $out = array(); foreach ( V::fallback( V::get( $param, 'options' ), array() ) as $option ) { if ( ! V::mapping( $option ) ) { throw new \UnexpectedValueException( 'Malformed stored option.' ); } { $out[] = (object) array( 'value' => V::string( V::get( $option, 'value' ) ), 'label' => V::string( V::get( $option, 'label' ) ) ); } } return $out; }
	public static function public_schema( $provider ): \stdClass {
		$params = array(); foreach ( ProviderDefinition::params( $provider ) as $param ) {
			$row = array(); foreach ( array( 'id', 'name', 'label', 'input', 'type', 'visible', 'kind' ) as $key ) { $row[$key] = V::get( $param, $key ); }
			$row += array( 'description' => V::fallback( V::get( $param, 'description' ), '' ), 'required' => V::truth( V::get( $param, 'required' ) ), 'default' => V::get( $param, 'default' ) );
			if ( 'select' === $row['input'] ) { $row['options'] = self::options( $param ); }
			if ( 'map' === $row['input'] ) { $row['map'] = V::fallback( V::get( $param, 'map' ), (object) array( 'mode' => 'split', 'lat_param' => 'lat', 'long_param' => 'long' ) ); }
			if ( 'list' === $row['input'] ) { $row['min_items'] = V::get( $param, 'min_items', 1 ); }
			if ( in_array( $row['input'], array( 'list', 'dict' ), true ) ) { $row['fields'] = array(); foreach ( ProviderDefinition::fields( $param ) as $field ) { $sub = array(); foreach ( array( 'id', 'name', 'label', 'input', 'type', 'visible', 'kind' ) as $key ) { $sub[$key] = V::get( $field, $key ); } $sub += array( 'required' => V::truth( V::get( $field, 'required' ) ), 'default' => V::get( $field, 'default' ), 'options' => V::fallback( V::get( $field, 'options' ), array() ) ); $row['fields'][] = (object) $sub; } }
			$params[] = (object) $row;
		}
		$out = array(); foreach ( array( 'id', 'name', 'display_name', 'logo' ) as $key ) { $out[$key] = V::get( $provider, $key ); }
		$currency = V::get( $provider, 'price_currency_out' ); if ( ! in_array( $currency, array( 'rial', 'toman' ), true ) ) { $currency = 'rial'; } $weight = V::get( $provider, 'weight_unit_out' ); if ( ! in_array( $weight, array( 'gram', 'kilogram' ), true ) ) { $weight = 'gram'; }
		return (object) ( $out + array( 'params' => $params, 'rules' => self::rules( $provider ), 'location_config' => V::fallback( V::get( $provider, 'location_config' ), ProviderDefinition::location_defaults() ), 'currency' => $currency, 'currency_label' => 'toman' === $currency ? 'تومان' : 'ریال', 'price_input_param' => V::fallback( V::text( V::fallback( V::get( $provider, 'price_input_param' ), '' ) ), null ), 'weight_unit' => $weight, 'weight_unit_label' => 'kilogram' === $weight ? 'کیلوگرم' : 'گرم', 'weight_input_param' => V::fallback( V::text( V::fallback( V::get( $provider, 'weight_input_param' ), '' ) ), null ), 'defaults' => ProviderDefinition::defaults( $provider ) ) );
	}
	public static function admin_schema( $provider ): \stdClass {
		$base = self::public_schema( $provider ); $out = clone $base; foreach ( array( 'check_price_url', 'shop_id', 'timeout', 'main_price_key' ) as $key ) { $out->$key = V::get( $provider, $key ); } $out->active = V::get( $provider, 'active', true ); $out->price_fields = V::fallback( V::get( $provider, 'price_fields' ), array() );
		foreach ( array( 'price_currency_in' => 'rial', 'price_currency_out' => 'rial', 'weight_unit_in' => 'gram', 'weight_unit_out' => 'gram' ) as $key => $default ) { $out->$key = V::fallback( V::get( $provider, $key ), $default ); }
		$ref = V::get( $provider, 'referral' ); $out->referral = (object) array( 'enabled' => V::truth( V::get( $ref, 'enabled' ) ), 'title' => V::string( V::fallback( V::get( $ref, 'title' ), '' ) ), 'url' => V::string( V::fallback( V::get( $ref, 'url' ), '' ) ), 'description' => V::string( V::fallback( V::get( $ref, 'description' ), '' ) ) ); $out->rules = array_values( array_filter( V::fallback( V::get( $provider, 'rules' ), array() ), array( V::class, 'mapping' ) ) ); $ordered = (array) $base; foreach ( array( 'check_price_url', 'active', 'shop_id', 'price_fields', 'price_currency_in', 'price_currency_out', 'weight_unit_in', 'weight_unit_out', 'timeout', 'main_price_key', 'referral' ) as $key ) { $ordered[$key] = $out->$key; } $ordered['rules'] = $out->rules; return (object) $ordered;
	}
}
