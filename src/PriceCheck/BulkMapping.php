<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Pure api.py bulk helpers. Config validation and provider rules remain in Stage 3. */
final class BulkMapping {
	public static function routes( $config, array $providers, string $key ): array {
		$by_id = array(); foreach ( $providers as $provider ) { $by_id[$provider->id] = $provider; } $out = array();
		foreach ( V::get( $config, 'fields', array() ) as $field ) {
			if ( ! V::mapping( $field ) ) { continue; } $ref = V::get( $field, $key );
			$id = BulkConfig::field_provider_id( $ref, $providers );
			if ( null === $id || ! V::mapping( $ref ) || ! V::truth( V::get( $ref, 'param' ) ) ) { continue; }
			$target = ProviderDefinition::target( $by_id[$id], V::get( $ref, 'param' ) );
			if ( V::truth( $target ) ) { $out[$id][V::get( $field, 'name' )] = $target; }
		} return $out;
	}
	public static function translated( $config, int $id, string $name, $value ) {
		$maps = V::get( $config, 'value_mappings' ); $row = V::get( $maps, $id ); $pairs = V::get( $row, $name );
		if ( ! V::mapping( $maps ) || ! V::mapping( $row ) || ! V::mapping( $pairs ) ) { return $value; }
		$hit = V::get( $pairs, V::string( $value ) ); return null === $hit ? $value : $hit;
	}
	public static function incoming( $provider, $values, $config, array $mirrors, array $providers, ?array $sources = null ): \stdClass {
		$sources = $sources ?? self::routes( $config, $providers, 'source' ); $out = array(); $params = array();
		foreach ( array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ) as $name ) { if ( V::has( $values, $name ) ) { $out[$name] = V::get( $values, $name ); } }
		foreach ( ProviderDefinition::params( $provider ) as $param ) { $params[V::get( $param, 'name' )] = $param; }
		$mapping = BulkConfig::mappings_for( $config, $provider->id );
		foreach ( V::get( $config, 'fields', array() ) as $field ) {
			if ( ! V::mapping( $field ) || ! V::has( $field, 'name' ) ) { throw new \UnexpectedValueException( 'Malformed stored bulk field.' ); }
			$name = V::get( $field, 'name' ); if ( ! V::has( $values, $name ) ) { continue; } $value = V::get( $values, $name );
			$price = V::get( $config, 'price' ); $weight = V::get( $config, 'weight' );
			if ( V::mapping( $price ) && V::get( $price, 'field' ) === $name ) {
				// Python accepts Persian aliases for shared units, but only English toman here.
				$display = V::get( $provider, 'price_currency_out' ); $unit = is_string( $display ) && 'toman' === strtolower( V::trim( $display ) ) ? 'toman' : 'rial';
				$value = Units::currency_amount( $value, Units::currency( V::get( $price, 'unit' ) ), $unit );
			}
			if ( V::mapping( $weight ) && V::get( $weight, 'field' ) === $name ) { $value = Units::weight_amount( $value, Units::weight( V::get( $weight, 'unit' ) ), Units::weight( V::get( $provider, 'weight_unit_out' ) ) ); }
			$mirror = $mirrors[$provider->id][$name] ?? null;
			if ( isset( $params[$mirror] ) && ! in_array( V::get( $params[$mirror], 'input' ), array( 'list', 'dict' ), true ) ) { $out[$mirror] = $value; continue; }
			$value = self::translated( $config, $provider->id, $name, $value ); $source = $sources[$provider->id][$name] ?? null;
			if ( isset( $params[$source] ) && ! in_array( V::get( $params[$source], 'input' ), array( 'list', 'dict' ), true ) ) { $out[$source] = $value; continue; }
			$target = V::get( $mapping, $name ); $resolved = V::truth( $target ) ? ProviderDefinition::target( $provider, $target ) : null;
			if ( ! V::truth( $resolved ) ) { continue; } $parts = explode( '.', $resolved, 2 ); $base = $parts[0];
			if ( ! isset( $parts[1] ) ) { $out[$base] = $value; continue; }
			if ( isset( $params[$base] ) && 'list' === V::get( $params[$base], 'input' ) ) {
				$rows = $out[$base] ?? array( new \stdClass() );
				if ( ! V::listing( $rows ) || ! $rows || ! V::mapping( $rows[0] ) ) { $rows = array( new \stdClass() ); }
				$row = (array) $rows[0]; $row[$parts[1]] = $value; $rows[0] = (object) $row; $out[$base] = $rows;
			} else {
				$node = isset( $out[$base] ) && V::mapping( $out[$base] ) ? (array) $out[$base] : array(); $node[$parts[1]] = $value; $out[$base] = (object) $node;
			}
		} return (object) $out;
	}
	public static function combinations( $config, int $id ): array {
		$variants = V::get( V::get( $config, 'variants' ), $id ); $combos = array( new \stdClass() );
		if ( ! V::mapping( $variants ) ) { return $combos; }
		foreach ( (array) $variants as $target => $choices ) {
			$expanded = array(); foreach ( $combos as $prior ) { foreach ( V::listing( $choices ) ? $choices : array() as $choice ) { $row = (array) $prior; $row[(string) $target] = $choice; $expanded[] = (object) $row; } }
			$combos = $expanded ?: array( new \stdClass() );
		} return $combos;
	}
	/** Python dict(value): mapping or a sequence of pairs, never a loose PHP cast. */
	private static function dictionary( $value ): array {
		if ( ! V::truth( $value ) ) { return array(); } if ( V::mapping( $value ) ) { return (array) $value; }
		if ( V::listing( $value ) ) {
			$out = array(); foreach ( $value as $pair ) { if ( ! V::listing( $pair ) || 2 !== count( $pair ) || ! is_scalar( $pair[0] ) ) { throw new \UnexpectedValueException( 'Invalid Python dict input.' ); } $out[V::string( $pair[0] )] = $pair[1]; } return $out;
		} throw new \UnexpectedValueException( 'Invalid Python dict input.' );
	}
	public static function variant_payload( $provider, $incoming, $variant ): \stdClass {
		$out = (array) $incoming;
		foreach ( (array) $variant as $target => $value ) {
			$resolved = ProviderDefinition::target( $provider, (string) $target ); if ( ! V::truth( $resolved ) ) { continue; } $parts = explode( '.', $resolved, 2 ); $base = $parts[0];
			if ( ! isset( $parts[1] ) ) { $out[$base] = $value; }
			elseif ( isset( $out[$base] ) && V::listing( $out[$base] ) ) { $rows = $out[$base] ?: array( new \stdClass() ); $row = self::dictionary( $rows[0] ); $row[$parts[1]] = $value; $out[$base] = array( (object) $row ); }
			else { $node = self::dictionary( $out[$base] ?? null ); $node[$parts[1]] = $value; $out[$base] = (object) $node; }
		} return (object) $out;
	}
	private static function options( $param ): array {
		$out = array(); foreach ( V::fallback( V::get( $param, 'options' ), array() ) as $option ) { $out[] = array( 'value' => V::string( V::get( $option, 'value' ) ), 'label' => V::string( V::get( $option, 'label' ) ) ); } return $out;
	}
	public static function variant_detail( $provider, $variant ): array {
		$out = array(); foreach ( (array) $variant as $target => $value ) {
			$target = (string) $target; $resolved = ProviderDefinition::target( $provider, $target ); $label = $target; $value_label = V::string( $value );
			if ( V::truth( $resolved ) ) {
				$parts = explode( '.', $resolved, 2 ); $param = ProviderDefinition::find_param( $provider, $parts[0] ); $label = V::string( V::fallback( V::get( $param, 'label' ), $parts[0] ) );
				if ( isset( $parts[1] ) ) {
					list( $_, $field ) = ProviderDefinition::find_field( $provider, $parts[0], $parts[1] );
					$label .= ' — ' . V::string( V::fallback( V::get( $field, 'label' ), $parts[1] ) ); $options = V::fallback( V::get( $field, 'options' ), array() );
				} else { $options = self::options( $param ); }
				foreach ( $options as $option ) { if ( V::mapping( $option ) && V::string( V::get( $option, 'value' ) ) === V::string( $value ) ) { $value_label = V::string( V::fallback( V::get( $option, 'label' ), $value ) ); break; } }
			}
			$out[] = array( 'target' => $target, 'label' => $label, 'value' => V::string( $value ), 'value_label' => $value_label );
		} return $out;
	}
	public static function missing_required( $config, $values ): array {
		$out = array(); foreach ( V::get( $config, 'fields', array() ) as $field ) {
			if ( ! V::mapping( $field ) || ! V::truth( V::get( $field, 'required' ) ) ) { continue; } $name = V::string( V::fallback( V::get( $field, 'name' ), '' ) );
			if ( '' === $name || ! self::blank( V::get( $values, $name ) ) ) { continue; } $label = V::string( V::fallback( V::get( $field, 'label' ), $name ) );
			$out[] = array( 'field' => $name, 'message' => "«{$label}» اجباری است؛ لطفاً تکمیل کنید." );
		} return $out;
	}
	private static function blank( $value ): bool { return null === $value || ( is_string( $value ) && '' === V::trim( $value ) ); }
	public static function shared_field( $provider, string $target, $config, ?array $providers = null ): string {
		if ( false === strpos( $target, '.' ) ) { foreach ( V::get( $config, 'fields', array() ) as $field ) {
			foreach ( array( 'mirror', 'source' ) as $key ) {
				$ref = V::get( $field, $key ); if ( ! V::mapping( $ref ) || ProviderDefinition::target( $provider, V::get( $ref, 'param' ) ) !== $target ) { continue; }
				$matches = 'mirror' === $key ? in_array( V::string( V::fallback( V::get( $ref, 'provider' ), '' ) ), array( V::string( $provider->id ), V::string( V::fallback( V::get( $provider, 'name' ), '' ) ) ), true ) : ( null !== $providers && BulkConfig::field_provider_id( $ref, $providers ) === $provider->id );
				if ( $matches ) { return V::string( V::fallback( V::get( $field, 'name' ), '' ) ); }
			}
		} }
		foreach ( (array) BulkConfig::mappings_for( $config, $provider->id ) as $shared => $mapped ) { if ( ProviderDefinition::target( $provider, $mapped ) === $target ) { return (string) $shared; } } return '';
	}
	/** Only kind inference is consumed by execution; this does not expose a frontend schema. */
	public static function fields_with_kinds( $config, array $providers, array $active ): array {
		$by_id = array(); foreach ( $providers as $provider ) { $by_id[$provider->id] = $provider; } $fields = array();
		foreach ( V::get( $config, 'fields', array() ) as $field ) {
			if ( ! V::mapping( $field ) ) { throw new \UnexpectedValueException( 'Malformed stored bulk field.' ); }
			$row = (array) $field; $kind = V::get( $field, 'kind' ); $candidates = array();
			foreach ( array( 'mirror', 'source' ) as $key ) {
				$ref = V::get( $field, $key ); $id = BulkConfig::field_provider_id( $ref, $providers );
				if ( null === $id || ( 'mirror' === $key && ! V::truth( V::get( $ref, 'param' ) ) ) ) { continue; }
				$target = ProviderDefinition::target( $by_id[$id], V::get( $ref, 'param' ) );
				foreach ( ProviderDefinition::params( $by_id[$id] ) as $param ) { if ( V::get( $param, 'name' ) === $target ) { $candidates[] = $param; break; } }
				if ( $candidates ) { break; }
			}
			if ( ! $candidates ) { foreach ( $active as $provider ) {
				$target = V::get( BulkConfig::mappings_for( $config, $provider->id ), V::get( $field, 'name' ) );
				$resolved = V::truth( $target ) ? ProviderDefinition::target( $provider, $target ) : null;
				if ( V::truth( $resolved ) ) { $candidates[] = ProviderDefinition::find_param( $provider, explode( '.', $resolved, 2 )[0] ); }
			} }
			foreach ( $candidates as $param ) { if ( ! V::truth( $kind ) && in_array( V::get( $param, 'kind' ), array( 'province', 'city' ), true ) ) { $kind = V::get( $param, 'kind' ); } }
			$row['kind'] = $kind; $fields[] = (object) $row;
		} return $fields;
	}
	public static function destination( $config, array $fields ): array {
		$dest = V::get( V::get( $config, 'location' ), 'destination' ); $enabled = false !== V::get( $dest, 'enabled', true );
		$display = V::get( $dest, 'display' ); if ( ! in_array( $display, array( 'separate', 'combined' ), true ) ) { $display = 'combined'; }
		$province = V::fallback( V::get( $dest, 'province_field' ), null ); $city = V::fallback( V::get( $dest, 'city_field' ), null ); $names = array(); $auto_province = $auto_city = null;
		foreach ( $fields as $field ) { $name = V::get( $field, 'name' ); if ( V::truth( $name ) ) { $names[] = $name; } if ( null === $auto_province && 'province' === V::get( $field, 'kind' ) ) { $auto_province = $name; } if ( null === $auto_city && 'city' === V::get( $field, 'kind' ) ) { $auto_city = $name; } }
		if ( $enabled && ( ! V::truth( $province ) || ! V::truth( $city ) || $province === $city || ! in_array( $province, $names, true ) || ! in_array( $city, $names, true ) ) && V::truth( $auto_province ) && V::truth( $auto_city ) && $auto_province !== $auto_city ) { $province = $auto_province; $city = $auto_city; }
		return array( $enabled, $display, $province, $city );
	}
	public static function destination_served( $section, $province, $city ): bool {
		if ( ! V::mapping( $section ) ) { return true; } $provinces = $groups = array();
		foreach ( (array) V::fallback( V::get( $section, 'bindings' ), new \stdClass() ) as $name => $binding ) {
			if ( ! V::mapping( $binding ) || in_array( $name, array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) ) { continue; }
			if ( 'province' === V::get( $binding, 'kind' ) ) { foreach ( V::fallback( V::get( $binding, 'items' ), array() ) as $item ) { $value = V::get( $item, 'value' ); if ( V::mapping( $item ) && null !== $value && '' !== $value ) { $provinces[] = V::string( $value ); } } }
			elseif ( 'city' === V::get( $binding, 'kind' ) ) { foreach ( (array) V::fallback( V::get( $binding, 'items_by_parent' ), new \stdClass() ) as $parent => $items ) { if ( ! V::listing( $items ) ) { continue; } foreach ( $items as $item ) { $value = V::get( $item, 'value' ); if ( V::mapping( $item ) && null !== $value && '' !== $value ) { $groups[(string) $parent][] = V::string( $value ); } } } }
		}
		if ( ! $provinces && ! $groups ) { return true; } $province_blank = self::blank( $province ); $city_blank = self::blank( $city );
		if ( $province_blank && $city_blank ) { return true; }
		if ( ! $province_blank && ! in_array( V::trim( V::string( $province ) ), $provinces, true ) ) { return false; }
		if ( ! $city_blank ) { $code = V::trim( V::string( $city ) ); $parent = V::trim( V::string( $province ) ); if ( ! $province_blank && isset( $groups[$parent] ) ) { return in_array( $code, $groups[$parent], true ); } foreach ( $groups as $group ) { if ( in_array( $code, $group, true ) ) { return true; } } return false; }
		return true;
	}
}
