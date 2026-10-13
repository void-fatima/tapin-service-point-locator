<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** records.py bulk configuration validation. All provider records are supplied. */
final class BulkConfig {
	private static function identities( array $providers ): array {
		$ids = $names = array(); foreach ( $providers as $provider ) {
			if ( ! V::mapping( $provider ) ) { continue; }
			try { $id = V::integer( V::get( $provider, 'id' ) ); } catch ( \InvalidArgumentException $e ) { continue; }
			$ids[$id] = $provider; $name = V::text( V::get( $provider, 'name' ) ); if ( '' !== $name && ! isset( $names[$name] ) ) { $names[$name] = $provider; }
		} return array( $ids, $names );
	}
	private static function resolve( $raw, array $ids, array $names ): ?int {
		if ( null === $raw || is_bool( $raw ) ) { return null; }
		if ( is_int( $raw ) ) { return isset( $ids[$raw] ) ? $raw : null; }
		if ( is_string( $raw ) ) {
			$text = V::trim( $raw ); if ( '' === $text ) { return null; }
			if ( V::digit_string( $text ) ) { $id = V::integer( $text ); if ( isset( $ids[$id] ) ) { return $id; } }
			if ( isset( $names[$text] ) ) { try { return V::integer( V::get( $names[$text], 'id' ) ); } catch ( \InvalidArgumentException $e ) { return null; } }
		} return null;
	}
	public static function active_providers( $config, array $providers ): array {
		if ( ! V::mapping( $config ) ) { return array(); } list( $ids, $names ) = self::identities( $providers ); $out = $seen = array();
		foreach ( self::selection_items( V::fallback( V::get( $config, 'providers' ), array() ) ) as $item ) {
			$id = self::resolve( $item, $ids, $names ); if ( null === $id || in_array( $id, $seen, true ) ) { continue; } $seen[] = $id;
			if ( V::truth( V::get( $ids[$id], 'active', true ) ) ) { $out[] = $ids[$id]; }
		} return $out;
	}
	/** Python iterates strings/mapping keys here; malformed non-iterables are source errors. */
	private static function selection_items( $raw ): array {
		if ( is_string( $raw ) ) { preg_match_all( '/./us', $raw, $chars ); return $chars[0]; }
		if ( V::mapping( $raw ) ) { return array_map( 'strval', array_keys( (array) $raw ) ); }
		if ( V::listing( $raw ) ) { return $raw; }
		$type = is_bool( $raw ) ? 'bool' : ( is_int( $raw ) ? 'int' : 'float' );
		throw new \TypeError( "'{$type}' object is not iterable" );
	}
	public static function mappings_for( $config, int $id ): \stdClass {
		$map = V::get( $config, 'mappings' ); if ( ! V::mapping( $config ) || ! V::mapping( $map ) ) { return V::object(); }
		$row = V::get( $map, $id ); if ( null === $row ) { $row = V::get( $map, (string) $id, V::object() ); }
		return V::mapping( $row ) ? V::object( (array) $row ) : V::object();
	}
	/** records.py::bulk_field_provider_id; share the existing stable-ID/name resolver. */
	public static function field_provider_id( $ref, array $providers ): ?int {
		if ( ! V::mapping( $ref ) ) { return null; }
		list( $ids, $names ) = self::identities( $providers );
		return self::resolve( V::get( $ref, 'provider' ), $ids, $names );
	}
	private static function provider_ref( $raw, array $ids, array $names, string $path, array &$errors ): ?int {
		$id = self::resolve( $raw, $ids, $names ); if ( null === $id ) { $text = V::fallback( V::text( $raw ), V::string( $raw ) ); ProviderConfig::error( $errors, $path, "شرکت ناشناخته در استعلام گروهی: {$text}" ); } return $id;
	}
	private static function target_ref( $provider, $raw, string $path, array &$errors ): array {
		$text = null === $raw || is_bool( $raw ) ? '' : V::trim( V::string( $raw ) ); if ( '' === $text ) { return array( null, null, null ); }
		$canonical = ProviderDefinition::target( $provider, $text, true );
		if ( null !== $canonical ) { $parts = explode( '.', $canonical, 2 ); list( $param, $field ) = isset( $parts[1] ) ? ProviderDefinition::find_field( $provider, $parts[0], $parts[1] ) : array( ProviderDefinition::find_param( $provider, $parts[0] ), null ); }
		if ( null === $canonical || null === $param || ( isset( $parts[1] ) && null === $field ) ) { $name = V::string( V::get( $provider, 'name' ) ); ProviderConfig::error( $errors, $path, "«{$text}» در شرکت {$name} وجود ندارد." ); return array( null, null, null ); }
		return array( $canonical, $param, $field );
	}
	private static function field_reference( $raw, array $ids, array $names, string $path, array &$errors, bool $mirror ) {
		if ( null === $raw ) { return null; }
		if ( ! V::mapping( $raw ) ) { ProviderConfig::error( $errors, $path, $mirror ? 'ساختار نمایش مستقیم نامعتبر است.' : 'ساختار منبع داده نامعتبر است.' ); return null; }
		$id = self::provider_ref( V::get( $raw, 'provider' ), $ids, $names, $path, $errors ); if ( null === $id || null === V::get( $raw, 'param' ) ) { return null; }
		list( $canonical, $param, $field ) = self::target_ref( $ids[$id], V::get( $raw, 'param' ), $path, $errors );
		$message = $mirror ? 'نمایش مستقیم باید یک پارامتر ساده باشد، نه لیست یا شیء.' : 'منبع داده باید یک پارامتر ساده باشد، نه لیست یا شیء.';
		if ( null === $canonical || null === $param || null !== $field ) { if ( null !== $canonical ) { ProviderConfig::error( $errors, $path, $message ); } return null; }
		if ( in_array( V::get( $param, 'input' ), array( 'list', 'dict' ), true ) ) { ProviderConfig::error( $errors, $path, $message ); return null; }
		return V::object( array( 'provider' => $id, 'param' => V::integer( $canonical ) ) );
	}
	private static function value_mappings( $raw, array $fields, array $ids, array $names, array &$errors ): \stdClass {
		if ( null === $raw ) { return V::object(); }
		if ( ! V::mapping( $raw ) ) { ProviderConfig::error( $errors, 'value_mappings', 'ساختار نگاشت مقادیر نامعتبر است.' ); return V::object(); }
		$by_field = array(); foreach ( $fields as $field ) { $by_field[$field->name] = $field; } $out = array();
		foreach ( (array) $raw as $ref => $row ) {
			$id = self::resolve( is_int( $ref ) ? (string) $ref : $ref, $ids, $names );
			if ( null === $id ) { ProviderConfig::error( $errors, 'value_mappings', "شرکت ناشناخته در نگاشت مقادیر: {$ref}" ); continue; }
			if ( ! V::mapping( $row ) ) { ProviderConfig::error( $errors, "value_mappings.{$id}", 'ساختار نگاشت مقادیر این شرکت نامعتبر است.' ); continue; }
			foreach ( (array) $row as $name => $pairs ) {
				$path = "value_mappings.{$id}.{$name}"; $field = $by_field[$name] ?? null;
				if ( null === $field ) { ProviderConfig::error( $errors, $path, "فیلد گروهی ناشناخته: {$name}" ); continue; }
				if ( ! V::mapping( $pairs ) ) { ProviderConfig::error( $errors, $path, 'ساختار نگاشت مقادیر این فیلد نامعتبر است.' ); continue; }
				$nonempty = array_filter( (array) $pairs, static fn( $v ) => '' !== V::text( $v ) );
				if ( V::truth( $field->mirror ) && $nonempty ) { ProviderConfig::error( $errors, $path, 'فیلدی که نمایش مستقیم دارد، نگاشت مقدار نمی‌پذیرد.' ); continue; }
				$clean = array(); foreach ( (array) $pairs as $src => $dst ) { $src = V::text( (string) $src ); $dst = V::text( $dst ); if ( '' !== $src && '' !== $dst ) { $clean[$src] = $dst; } }
				if ( ! $clean ) { continue; }
				$source = $field->source; $allowed = null;
				if ( V::mapping( $source ) && isset( $ids[$source->provider] ) ) {
					$param = ProviderDefinition::find_param( $ids[$source->provider], $source->param );
					if ( null !== $param && 'select' === V::get( $param, 'input' ) ) { $allowed = array_map( static fn( $o ) => V::string( V::get( $o, 'value' ) ), V::fallback( V::get( $param, 'options' ), array() ) ); }
				}
				if ( null !== $allowed ) { $unknown = array_filter( array_keys( $clean ), static fn( $key ) => ! in_array( (string) $key, $allowed, true ) ); if ( $unknown ) { ProviderConfig::error( $errors, $path, 'این مقادیر در گزینه‌های منبع داده نیست: ' . implode( '، ', $unknown ) ); continue; } }
				$out[$id][$name] = V::object( $clean );
			}
		}
		foreach ( $out as $id => $row ) { $out[$id] = V::object( $row ); } return V::object( $out );
	}
	public static function normalise( $raw, array $providers ): \stdClass {
		if ( ! V::mapping( $raw ) ) { throw new RecordError( array( array( 'field' => 'body', 'message' => 'بدنه درخواست باید یک شیء JSON باشد.' ) ) ); }
		$errors = array(); list( $ids, $names ) = self::identities( $providers ); $selected = array();
		foreach ( self::selection_items( V::fallback( V::get( $raw, 'providers' ), array() ) ) as $item ) {
			if ( is_bool( $item ) ) { continue; } $id = self::resolve( $item, $ids, $names );
			if ( null === $id ) { $text = is_string( $item ) ? V::text( $item ) : V::string( $item ); ProviderConfig::error( $errors, 'providers', "شرکت ناشناخته در استعلام گروهی: {$text}" ); continue; }
			if ( ! in_array( $id, $selected, true ) ) { $selected[] = $id; }
		}
		$raw_fields = V::get( $raw, 'fields', array() ); if ( null === $raw_fields ) { $raw_fields = array(); }
		if ( ! V::listing( $raw_fields ) ) { ProviderConfig::error( $errors, 'fields', 'فیلدها باید یک لیست باشند.' ); $raw_fields = array(); }
		$fields = $seen = $by_field = array();
		foreach ( $raw_fields as $index => $item ) {
			$path = "fields[{$index}]";
			if ( ! V::mapping( $item ) ) { ProviderConfig::error( $errors, $path, 'ساختار فیلد نامعتبر است.' ); continue; } $name = V::text( V::get( $item, 'name' ) );
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) { ProviderConfig::error( $errors, $path, 'نام فیلد باید با حروف انگلیسی شروع شود و فقط شامل حروف، عدد و _ باشد.' ); continue; }
			if ( in_array( $name, $seen, true ) ) { ProviderConfig::error( $errors, $path, "نام فیلد تکراری: {$name}" ); continue; } $seen[] = $name;
			$input = V::fallback( V::text( V::get( $item, 'input' ) ), 'text' ); if ( ! in_array( $input, array( 'text', 'number' ), true ) ) { $input = 'text'; }
			$kind = V::text( V::get( $item, 'kind' ) ); if ( ! in_array( $kind, ProviderConfig::KINDS, true ) ) { $kind = null; }
			$source = self::field_reference( V::get( $item, 'source' ), $ids, $names, "{$path}.source", $errors, false );
			$mirror = self::field_reference( V::get( $item, 'mirror' ), $ids, $names, "{$path}.mirror", $errors, true );
			$field = V::object( array( 'name' => $name, 'label' => V::fallback( V::text( V::get( $item, 'label' ) ), $name ), 'input' => $input, 'kind' => $kind, 'source' => $source, 'mirror' => $mirror, 'required' => V::truth( V::get( $item, 'required' ) ) ) ); $fields[] = $field; $by_field[$name] = $field;
		}
		$raw_mappings = V::get( $raw, 'mappings', V::object() ); if ( null === $raw_mappings ) { $raw_mappings = V::object(); }
		$value_mappings = self::value_mappings( V::get( $raw, 'value_mappings' ), $fields, $ids, $names, $errors );
		$loc = V::get( $raw, 'location' ); $origin = V::get( $loc, 'origin' ); $dest = V::get( $loc, 'destination' );
		$province = ProviderConfig::nullable_text( V::get( $dest, 'province_field' ) ); $city = ProviderConfig::nullable_text( V::get( $dest, 'city_field' ) ); $enabled = false !== V::get( $dest, 'enabled', true );
		if ( $enabled && ( null === $province || null === $city ) ) { ProviderConfig::error( $errors, 'location.destination', 'وقتی مقصد فعال است، فیلد استان و شهر مقصد باید هر دو انتخاب شوند.' ); $province = $city = null; }
		elseif ( ( null === $province ) !== ( null === $city ) ) { ProviderConfig::error( $errors, 'location.destination', 'فیلد استان و شهر مقصد باید هر دو انتخاب شوند.' ); $province = $city = null; }
		else { foreach ( array( array( $province, 'فیلد استان مقصد' ), array( $city, 'فیلد شهر مقصد' ) ) as $pair ) { if ( null !== $pair[0] && ! isset( $by_field[$pair[0]] ) ) { ProviderConfig::error( $errors, 'location.destination', "{$pair[1]} نامعتبر است." ); } } }
		$display = static fn( $node ) => in_array( V::get( $node, 'display' ), array( 'separate', 'combined' ), true ) ? V::get( $node, 'display' ) : 'combined';
		$location = V::object( array( 'origin' => V::object( array( 'enabled' => false !== V::get( $origin, 'enabled', true ), 'display' => $display( $origin ) ) ), 'destination' => V::object( array( 'enabled' => $enabled, 'display' => $display( $dest ), 'province_field' => $province, 'city_field' => $city ) ) ) );
		$units = array(); foreach ( array( 'price' => 'مبلغ', 'weight' => 'وزن' ) as $key => $label ) {
			$unit_config = V::get( $raw, $key ); $field_name = ProviderConfig::nullable_text( V::get( $unit_config, 'field' ) );
			if ( null !== $field_name ) {
				if ( ! isset( $by_field[$field_name] ) ) { ProviderConfig::error( $errors, "{$key}.field", "فیلد گروهی «{$field_name}» وجود ندارد." ); $field_name = null; }
				elseif ( in_array( $by_field[$field_name]->kind, array( 'city', 'province' ), true ) ) { ProviderConfig::error( $errors, "{$key}.field", "فیلد {$label} گروهی نمی‌تواند فیلد شهر/استان باشد." ); $field_name = null; }
			}
			$units[$key] = V::object( array( 'field' => $field_name, 'unit' => 'price' === $key ? Units::currency( V::get( $unit_config, 'unit' ) ) : Units::weight( V::get( $unit_config, 'unit' ) ) ) );
		}
		if ( ! V::mapping( $raw_mappings ) ) { ProviderConfig::error( $errors, 'mappings', 'نگاشت فیلدها باید یک شیء باشد.' ); $raw_mappings = V::object(); }
		$mappings = array(); foreach ( $selected as $id ) {
			$provider = $ids[$id]; $row = V::get( $raw_mappings, $id ); if ( null === $row ) { $row = V::get( $raw_mappings, (string) $id, V::object() ); }
			// Preserve the source's legacy-name fallback bug: absent id rows become {} first.
			if ( null === $row ) { $row = V::get( $raw_mappings, V::get( $provider, 'name' ), V::object() ); }
			if ( ! V::mapping( $row ) ) { ProviderConfig::error( $errors, "mappings.{$id}", 'ساختار نگاشت این شرکت نامعتبر است.' ); $row = V::object(); }
			$mapping = array(); foreach ( $fields as $field ) {
				$target = V::get( $row, $field->name ); if ( null === $target || is_bool( $target ) || ( is_string( $target ) && '' === V::trim( $target ) ) ) { continue; }
				list( $canonical ) = self::target_ref( $provider, $target, "mappings.{$id}.{$field->name}", $errors ); if ( null !== $canonical ) { $mapping[$field->name] = $canonical; }
			} $mappings[$id] = $mapping;
		}
		// Source raises before variant validation if any prior section failed.
		if ( $errors ) { throw new RecordError( $errors ); }
		foreach ( $fields as $field ) {
			if ( V::truth( $field->mirror ) ) { foreach ( $mappings as &$row ) { unset( $row[$field->name] ); } unset( $row ); }
			if ( V::mapping( $field->source ) && isset( $mappings[$field->source->provider] ) ) { unset( $mappings[$field->source->provider][$field->name] ); }
		}
		$variants = array(); $raw_variants = V::get( $raw, 'variants' );
		if ( null !== $raw_variants && ! V::mapping( $raw_variants ) ) { ProviderConfig::error( $errors, 'variants', 'حالت‌های جایگزین باید یک شیء باشد.' ); $raw_variants = V::object(); }
		if ( V::mapping( $raw_variants ) ) { foreach ( $selected as $id ) {
			$provider = $ids[$id]; $row = V::get( $raw_variants, $id, V::get( $raw_variants, (string) $id, V::object() ) ); if ( null === $row ) { continue; }
			if ( ! V::mapping( $row ) ) { ProviderConfig::error( $errors, "variants.{$id}", 'ساختار حالت‌های این شرکت نامعتبر است.' ); continue; }
			$assigned = array_values( $mappings[$id] ); foreach ( $fields as $field ) { foreach ( array( $field->source, $field->mirror ) as $ref ) { if ( V::mapping( $ref ) && $ref->provider === $id && null !== $ref->param ) { $assigned[] = V::string( $ref->param ); } } }
			if ( count( (array) $row ) > 5 ) { ProviderConfig::error( $errors, "variants.{$id}", 'حداکثر ۵ فیلد جایگزین برای هر شرکت.' ); continue; }
			$clean_row = array(); foreach ( (array) $row as $target => $values ) {
				$path = "variants.{$id}.{$target}"; list( $canonical, $param, $field ) = self::target_ref( $provider, (string) $target, $path, $errors ); if ( null === $canonical || null === $param ) { continue; }
				if ( in_array( V::get( $param, 'name' ), array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) ) { ProviderConfig::error( $errors, $path, 'فیلد مبدا حالت جایگزین نمی‌پذیرد.' ); continue; }
				if ( false === strpos( $canonical, '.' ) && in_array( V::get( $param, 'input' ), array( 'list', 'dict', 'map' ), true ) ) { ProviderConfig::error( $errors, $path, 'فیلد جایگزین باید یک پارامتر ساده یا زیرفیلد باشد.' ); continue; }
				if ( in_array( $canonical, $assigned, true ) ) { ProviderConfig::error( $errors, $path, 'این فیلد به فرم گروهی نگاشت شده و حالت جایگزین نمی‌پذیرد.' ); continue; }
				$clean = array(); foreach ( V::listing( $values ) ? $values : array() as $value ) { $value = V::trim( V::string( $value ) ); if ( '' !== $value && ! in_array( $value, $clean, true ) ) { $clean[] = $value; } } if ( ! $clean ) { continue; }
				if ( count( $clean ) > 10 ) { ProviderConfig::error( $errors, $path, 'حداکثر ۱۰ حالت برای هر فیلد.' ); continue; }
				$owner = V::truth( $field ) ? $field : $param; $allowed = array(); foreach ( V::fallback( V::get( $owner, 'options' ), array() ) as $option ) { if ( V::mapping( $option ) ) { $allowed[] = V::string( V::get( $option, 'value' ) ); } }
				if ( $allowed && array_filter( $clean, static fn( $value ) => ! in_array( $value, $allowed, true ) ) ) { ProviderConfig::error( $errors, $path, 'همه حالت‌ها باید از گزینه‌های تعریف‌شده باشند.' ); continue; }
				if ( ! $allowed && 'select' === V::get( $owner, 'input' ) ) { ProviderConfig::error( $errors, $path, 'برای این فیلد گزینه‌ای تعریف نشده است.' ); continue; }
				$clean_row[$canonical] = $clean;
			}
			$total = 1; foreach ( $clean_row as $choices ) { $total *= max( 1, count( $choices ) ); }
			if ( $total > 20 ) { ProviderConfig::error( $errors, "variants.{$id}", 'ترکیب حالت‌ها بیش از ۲۰ استعلام می‌سازد؛ حالت‌ها را کمتر کنید.' ); continue; }
			if ( $clean_row ) { $variants[$id] = V::object( $clean_row ); }
		} }
		if ( $errors ) { throw new RecordError( $errors ); }
		foreach ( $mappings as $id => $row ) { $mappings[$id] = V::object( $row ); }
		return V::object( array( 'providers' => $selected, 'fields' => $fields, 'mappings' => V::object( $mappings ), 'value_mappings' => $value_mappings, 'variants' => V::object( $variants ), 'location' => $location, 'price' => $units['price'], 'weight' => $units['weight'] ) );
	}
}
