<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Pure providers.py payload/rule port; origin resolution requires explicit shop data. */
final class PayloadNormalizer {
	public static function coerce( $value, $type ) {
		if ( null === $value || ! is_string( $value ) ) { return $value; }
		if ( '' === V::trim( $value ) ) { return null; }
		if ( in_array( $type, array( 'int', 'integer' ), true ) ) { return V::integer( $value ); }
		if ( in_array( $type, array( 'float', 'double', 'decimal' ), true ) ) { return V::number( $value ); }
		return $value;
	}
	private static function blank( $value ): bool { return null === $value || ( is_string( $value ) && '' === V::trim( $value ) ); }
	private static function required( $field, $value, string $path, array &$errors ): void {
		if ( V::truth( V::get( $field, 'required' ) ) && self::blank( $value ) ) {
			$label = V::string( V::fallback( V::get( $field, 'label' ), V::get( $field, 'name' ) ) );
			ProviderConfig::error( $errors, $path, "«{$label}» اجباری است؛ لطفاً تکمیل کنید." );
		}
	}
	private static function strict( $value, $type, string $path, array &$errors ) {
		if ( null === $value || '' === $value ) { return null; }
		if ( V::mapping( $value ) || is_array( $value ) ) { ProviderConfig::error( $errors, $path, "{$path} باید یک مقدار ساده باشد." ); return null; }
		if ( is_bool( $value ) ) { ProviderConfig::error( $errors, $path, "{$path} باید عدد یا متن باشد." ); return null; }
		if ( in_array( $type, array( 'int', 'integer' ), true ) ) {
			if ( is_int( $value ) ) { return $value; }
			try { return V::integer( V::trim( V::string( $value ) ) ); }
			catch ( \InvalidArgumentException $e ) { ProviderConfig::error( $errors, $path, "{$path} باید عدد صحیح باشد." ); return null; }
		}
		if ( in_array( $type, array( 'float', 'double', 'decimal' ), true ) ) {
			try { return V::number( is_int( $value ) || is_float( $value ) ? $value : V::trim( V::string( $value ) ) ); }
			catch ( \InvalidArgumentException $e ) { ProviderConfig::error( $errors, $path, "{$path} باید عدد باشد." ); return null; }
		}
		return V::string( $value );
	}
	private static function convert( $provider, $value, string $target, $kind ) {
		list( $service, $display ) = Units::currency_pair( $provider );
		$money = V::trim( V::string( V::fallback( V::get( $provider, 'price_input_param' ), '' ) ) );
		if ( $service !== $display && ( 'money' === $kind || ( '' !== $money && $money === $target ) ) ) { $value = Units::currency_amount( $value, $display, $service ); }
		list( $service, $display ) = Units::weight_pair( $provider );
		$weight = V::trim( V::string( V::fallback( V::get( $provider, 'weight_input_param' ), '' ) ) );
		if ( $service !== $display && ( 'weight' === $kind || ( '' !== $weight && $weight === $target ) ) ) { $value = Units::weight_amount( $value, $display, $service ); }
		return $value;
	}
	public static function normalise( $provider, $incoming, array $shops ): NormalizedPayload {
		list( $payload, $errors ) = self::prepare( $provider, $incoming );
		return self::finish( $provider, $payload, $errors, $shops );
	}
	/** Pure first phase, allowing orchestration to load shops after field/rule validation. */
	public static function prepare( $provider, $incoming ): array {
		if ( ! V::mapping( $provider ) || ! V::mapping( $incoming ) ) { throw new \InvalidArgumentException( 'Provider and incoming values must be mappings.' ); }
		$errors = array(); $payload = (array) ProviderDefinition::defaults( $provider );
		foreach ( ProviderDefinition::params( $provider ) as $param ) {
			$name = V::get( $param, 'name' ); $input = V::get( $param, 'input' );
			if ( 'list' === $input ) { $payload[$name] = self::rows( $provider, $param, V::get( $incoming, $name ), $errors ); }
			elseif ( 'dict' === $input ) { $payload[$name] = self::row( $provider, $param, V::get( $incoming, $name ), $name, $errors ); }
			elseif ( 'map' === $input ) {
				foreach ( (array) self::map( $param, V::get( $incoming, $name ), $errors ) as $key => $value ) { $payload[$key] = $value; }
				unset( $payload[$name] );
			} elseif ( ! V::truth( V::get( $param, 'visible' ) ) ) { $payload[$name] = self::convert( $provider, $payload[$name], $name, V::get( $param, 'kind' ) ); }
			elseif ( V::has( $incoming, $name ) ) {
				$count = count( $errors ); $value = self::strict( V::get( $incoming, $name ), V::get( $param, 'type' ), $name, $errors );
				if ( in_array( $input, array( 'text', 'number', 'select' ), true ) && count( $errors ) === $count ) { self::required( $param, $value, $name, $errors ); }
				$payload[$name] = self::convert( $provider, $value, $name, V::get( $param, 'kind' ) );
			}
		}
		self::check_rules( $provider, V::object( $payload ), $errors );
		return array( $payload, $errors );
	}
	/** Pure origin phase; the existing normalise API retains exactly the same rules. */
	public static function finish( $provider, array $payload, array $errors, array $shops ): NormalizedPayload {
		$origin = self::origin( $provider, $payload, $shops, $errors );
		if ( $errors ) { throw new PayloadError( $errors ); }
		unset( $payload[ProviderDefinition::ORIGIN_PROVINCE], $payload[ProviderDefinition::ORIGIN_CITY] );
		return new NormalizedPayload( V::object( $payload ), $origin );
	}
	private static function row( $provider, $param, $raw, string $path, array &$errors ): \stdClass {
		$name = V::get( $param, 'name' ); $out = array();
		if ( null !== $raw && ! V::mapping( $raw ) ) { ProviderConfig::error( $errors, $name, "{$name} باید یک شیء باشد." ); return V::object(); }
		foreach ( ProviderDefinition::fields( $param ) as $field ) {
			$sub = V::get( $field, 'name' );
			if ( null !== $raw && V::truth( V::get( $field, 'visible' ) ) && V::has( $raw, $sub ) ) {
				$count = count( $errors ); $value = self::strict( V::get( $raw, $sub ), V::get( $field, 'type' ), "{$path}.{$sub}", $errors );
				if ( count( $errors ) === $count ) { self::required( $field, $value, "{$name}.{$sub}", $errors ); }
			} else { $value = V::get( $field, 'default' ); }
			$out[$sub] = self::convert( $provider, $value, "{$name}.{$sub}", V::get( $field, 'kind' ) );
		} return V::object( $out );
	}
	private static function rows( $provider, $param, $raw, array &$errors ): array {
		$name = V::get( $param, 'name' ); if ( null === $raw ) { $raw = array(); }
		if ( ! V::listing( $raw ) ) { ProviderConfig::error( $errors, $name, "{$name} باید یک لیست باشد." ); return array(); }
		$out = array(); foreach ( $raw as $index => $item ) {
			if ( ! V::mapping( $item ) ) { ProviderConfig::error( $errors, "{$name}[{$index}]", 'ساختار ردیف نامعتبر است.' ); continue; }
			$present = false; foreach ( (array) $item as $value ) { if ( '' !== V::trim( V::string( $value ) ) ) { $present = true; break; } }
			if ( ! $present ) { continue; }
			$out[] = self::row( $provider, $param, $item, "{$name}[{$index}]", $errors );
		}
		$min = V::fallback( V::get( $param, 'min_items', 1 ), 1 );
		if ( count( $out ) < $min ) { $label = V::get( $param, 'label' ); $text = V::string( $min ); ProviderConfig::error( $errors, $name, "{$label} حداقل به {$text} ردیف نیاز دارد." ); }
		return $out;
	}
	private static function map( $param, $raw, array &$errors ): \stdClass {
		$name = V::get( $param, 'name' ); if ( null === $raw ) { $raw = V::object(); }
		if ( ! V::mapping( $raw ) ) { ProviderConfig::error( $errors, $name, "{$name} باید مختصات باشد." ); return V::object(); }
		$lat = self::strict( V::get( $raw, 'lat' ), 'float', "{$name}.lat", $errors ); $long = self::strict( V::get( $raw, 'long' ), 'float', "{$name}.long", $errors ); $config = V::get( $param, 'map' );
		if ( 'combined' === V::get( $config, 'mode' ) && V::truth( V::get( $config, 'param' ) ) ) { return V::object( array( V::string( V::get( $config, 'param' ) ) => null === $lat || null === $long ? null : V::string( $lat ) . ',' . V::string( $long ) ) ); }
		return V::object( array( V::string( V::fallback( V::get( $config, 'lat_param' ), 'lat' ) ) => $lat, V::string( V::fallback( V::get( $config, 'long_param' ), 'long' ) ) => $long ) );
	}
	private static function rule_values( $payload, string $target ): array {
		$parts = explode( '.', $target, 2 ); if ( '' === $parts[0] || ! V::mapping( $payload ) ) { return array(); } $node = V::get( $payload, $parts[0] );
		if ( ! isset( $parts[1] ) ) { return array( $node ); }
		if ( V::mapping( $node ) ) { return array( V::get( $node, $parts[1] ) ); }
		if ( V::listing( $node ) ) { return array_map( static fn( $row ) => V::mapping( $row ) ? V::get( $row, $parts[1] ) : null, $node ); }
		return array( null );
	}
	public static function rule_clause( string $op, $actual, $expected, $expected2 ): ?bool {
		if ( self::blank( $actual ) ) { return null; } $text = V::trim( V::string( $actual ) ); $want_text = V::trim( V::string( V::fallback( $expected, '' ) ) );
		if ( 'eq' === $op ) { return $text === $want_text; } if ( 'neq' === $op ) { return $text !== $want_text; }
		if ( 'in' === $op ) { return in_array( $text, array_map( array( V::class, 'trim' ), explode( ',', $want_text ) ), true ); }
		try { $number = V::number( V::trim( V::string( $actual ) ) ); $want = V::number( V::trim( V::string( $expected ) ) ); }
		catch ( \InvalidArgumentException $e ) { return false; }
		if ( 'between' === $op ) { try { $other = V::number( V::trim( V::string( $expected2 ) ) ); } catch ( \InvalidArgumentException $e ) { return false; } list( $low, $high ) = $want <= $other ? array( $want, $other ) : array( $other, $want ); return $low <= $number && $number <= $high; }
		switch ( $op ) { case 'gt': return $number > $want; case 'gte': return $number >= $want; case 'lt': return $number < $want; case 'lte': return $number <= $want; default: return false; }
	}
	private static function label( $provider, string $target ): string {
		$parts = explode( '.', $target, 2 );
		foreach ( ProviderDefinition::params( $provider ) as $param ) {
			if ( V::get( $param, 'name' ) !== $parts[0] ) { continue; }
			if ( isset( $parts[1] ) ) { foreach ( ProviderDefinition::fields( $param ) as $field ) { if ( V::get( $field, 'name' ) === $parts[1] ) { return V::string( V::fallback( V::get( $field, 'label' ), $parts[1] ) ); } } return '' === $parts[1] ? $target : $parts[1]; }
			return V::string( V::fallback( V::get( $param, 'label' ), $parts[0] ) );
		} return $target;
	}
	public static function check_rules( $provider, $payload, array &$errors ): void {
		$rules = V::fallback( V::get( $provider, 'rules' ), array() ); if ( ! V::listing( $rules ) ) { return; }
		foreach ( $rules as $rule ) {
			if ( ! V::mapping( $rule ) ) { continue; } $if = V::string( V::fallback( V::get( $rule, 'if_field' ), '' ) ); $op = V::string( V::fallback( V::get( $rule, 'if_op' ), '' ) );
			if ( '' === $if || '' === $op ) { continue; }
			$values = array_values( array_filter( self::rule_values( $payload, $if ), static fn( $value ) => ! self::blank( $value ) ) ); if ( ! $values ) { continue; }
			$then = V::string( V::fallback( V::get( $rule, 'then_field' ), '' ) ); $message = V::trim( V::string( V::fallback( V::get( $rule, 'message' ), '' ) ) );
			$verdicts = array_map( static fn( $value ) => self::rule_clause( $op, $value, V::get( $rule, 'if_value' ), V::get( $rule, 'if_value2' ) ), $values );
			if ( '' !== $then ) {
				if ( ! in_array( true, $verdicts, true ) ) { continue; }
				$values = array_values( array_filter( self::rule_values( $payload, $then ), static fn( $value ) => ! self::blank( $value ) ) );
				$verdicts = array_map( static fn( $value ) => self::rule_clause( V::string( V::fallback( V::get( $rule, 'then_op' ), '' ) ), $value, V::get( $rule, 'then_value' ), V::get( $rule, 'then_value2' ) ), $values );
			}
			if ( ! $values || in_array( false, $verdicts, true ) || in_array( null, $verdicts, true ) ) { $target = '' === $then ? $if : $then; $label = self::label( $provider, $target ); ProviderConfig::error( $errors, $target, '' === $message ? "مقدار «{$label}» معتبر نیست." : $message ); }
		}
	}
	public static function region_code( $value ): ?string {
		if ( null === $value || is_bool( $value ) ) { return null; } if ( is_int( $value ) ) { return (string) $value; }
		if ( is_float( $value ) ) { return is_finite( $value ) && floor( $value ) === $value ? (string) V::integer( $value ) : null; }
		$text = V::trim( V::string( $value ) ); if ( '' === $text ) { return null; }
		if ( V::digit_string( ltrim( $text, '+-' ) ) ) { try { return (string) V::integer( $text ); } catch ( \InvalidArgumentException $e ) { return $text; } }
		return $text;
	}
	private static function origin( $provider, array &$payload, array $shops, array &$errors ): ?\stdClass {
		$origin_params = array_filter( ProviderDefinition::params( $provider ), static fn( $param ) => in_array( V::get( $param, 'name' ), array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) );
		if ( ! $origin_params ) { return null; }
		$province = self::region_code( V::get( $payload, ProviderDefinition::ORIGIN_PROVINCE ) ); $city = self::region_code( V::get( $payload, ProviderDefinition::ORIGIN_CITY ) );
		if ( null === $province ) {
			$configured = V::get( $payload, 'shop_id' );
			if ( null === $configured || '' === $configured ) { ProviderConfig::error( $errors, ProviderDefinition::ORIGIN_PROVINCE, 'استان مبدا را انتخاب کنید.' ); return null; }
			$title = null; foreach ( $shops as $shop ) { if ( V::mapping( $shop ) && V::trim( V::string( V::fallback( V::get( $shop, 'id' ), '' ) ) ) === V::trim( V::string( $configured ) ) ) { $title = null === V::get( $shop, 'title' ) ? null : V::string( V::get( $shop, 'title' ) ); break; } }
			return V::object( array( 'province_param' => ProviderDefinition::ORIGIN_PROVINCE, 'city_param' => ProviderDefinition::ORIGIN_CITY, 'province_code' => null, 'city_code' => $city, 'shop_id' => $configured, 'shop_title' => $title, 'match' => 'provider_default' ) );
		}
		$candidates = array_values( array_filter( $shops, static fn( $shop ) => V::mapping( $shop ) && self::region_code( V::get( $shop, 'province' ) ) === $province ) );
		if ( ! $candidates ) { ProviderConfig::error( $errors, ProviderDefinition::ORIGIN_PROVINCE, "برای استان مبدا {$province} هیچ شعبه‌ای ثبت نشده است." ); return null; }
		$selected = $candidates[0]; $match = 'province_fallback';
		if ( null !== $city ) { foreach ( $candidates as $candidate ) { if ( self::region_code( V::get( $candidate, 'city' ) ) === $city ) { $selected = $candidate; $match = 'exact'; break; } } }
		$payload['shop_id'] = V::get( $selected, 'id' );
		return V::object( array( 'province_param' => ProviderDefinition::ORIGIN_PROVINCE, 'city_param' => ProviderDefinition::ORIGIN_CITY, 'province_code' => V::digit_string( $province ) ? V::integer( $province ) : $province, 'city_code' => null !== $city && V::digit_string( $city ) ? V::integer( $city ) : $city, 'shop_id' => V::get( $selected, 'id' ), 'shop_title' => V::get( $selected, 'title' ), 'match' => $match ) );
	}
}
