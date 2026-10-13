<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** records.py::normalise and its validation helpers, without persistence. */
final class ProviderConfig {
	const INPUTS = array( 'text', 'number', 'select', 'list', 'dict', 'map' );
	const KINDS = array( 'city', 'province', 'money', 'weight' );
	const OPS = array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in' );
	public static function error( array &$errors, string $field, string $message ): void { $errors[] = array( 'field' => $field, 'message' => $message ); }
	private static function one_of( $value, array $allowed, string $default ): string { $text = V::text( $value ); return in_array( $text, $allowed, true ) ? $text : $default; }
	private static function row_id( $value ) {
		if ( null === $value || is_bool( $value ) || ( is_float( $value ) && ( ! is_finite( $value ) || floor( $value ) !== $value ) ) ) { return null; }
		try { return V::integer( $value ); } catch ( \InvalidArgumentException $e ) { return null; }
	}
	private static function default_value( $value ) {
		return null === $value || is_int( $value ) || is_float( $value ) || is_bool( $value ) ? $value : V::trim( V::string( $value ) );
	}
	public static function normalise( $raw ): \stdClass {
		if ( ! V::mapping( $raw ) ) { throw new RecordError( array( array( 'field' => 'body', 'message' => 'بدنه درخواست باید یک شیء JSON باشد.' ) ) ); }
		$errors = array(); $name = V::text( V::get( $raw, 'name' ) );
		if ( '' === $name ) { self::error( $errors, 'name', 'نام فنی شرکت الزامی است.' ); }
		$params = self::dynamic_fields( V::get( $raw, 'params' ), 'params', $errors );
		$location = self::location( V::get( $raw, 'location_config' ), $params, $errors );
		$rest = array_filter( $params, static fn( $p ) => ! in_array( $p->name, array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) );
		if ( $rest ) { $params = ProviderDefinition::ensure_origin_params( $params, $location ); }
		if ( ! $params ) { self::error( $errors, 'params', 'حداقل یک پارامتر باید تعریف شود.' ); }
		$endpoint = V::text( V::get( $raw, 'check_price_url' ) );
		if ( '' !== $endpoint && ! V::http_url( $endpoint ) ) { self::error( $errors, 'check_price_url', 'نشانی سرویس قیمت باید یک URL کامل http یا https باشد.' ); }
		$prices = self::prices( V::get( $raw, 'price_fields' ), $errors );
		$money_target = self::unit_target( V::get( $raw, 'price_input_param' ), $params, $errors, false );
		$weight_target = self::unit_target( V::get( $raw, 'weight_input_param' ), $params, $errors, true );
		$logo = V::text( V::get( $raw, 'logo' ) );
		if ( V::length( $logo ) > 1500000 ) { self::error( $errors, 'logo', 'حجم لوگو بیش از حد بزرگ است؛ فایل کوچک‌تری انتخاب کنید.' ); $logo = ''; }
		elseif ( '' !== $logo && ! ( 0 === strpos( $logo, 'data:image/' ) && false !== strpos( $logo, ',' ) ) && ! V::http_url( $logo ) ) { self::error( $errors, 'logo', 'لوگو باید یک فایل تصویری یا نشانی http(s) باشد.' ); }
		$timeout = self::timeout( V::get( $raw, 'timeout' ), $errors );
		$main = V::text( V::get( $raw, 'main_price_key' ) );
		if ( '' !== $main && ! in_array( $main, array_map( static fn( $p ) => $p->key, $prices ), true ) ) { self::error( $errors, 'main_price_key', 'قیمت اصلی باید یکی از فیلدهای قیمت تنظیم‌شده باشد.' ); }
		$referral = self::referral( V::get( $raw, 'referral' ), $errors );
		$rules = self::rules( V::get( $raw, 'rules' ), $params, $errors );
		$seen = array();
		foreach ( $params as $index => $param ) {
			if ( in_array( $param->name, $seen, true ) ) { self::error( $errors, "params[{$index}].name", "پارامتر تکراری: {$param->name}" ); }
			$seen[] = $param->name;
		}
		foreach ( $params as $index => $param ) {
			if ( 'map' !== $param->input || ! V::mapping( V::get( $param, 'map' ) ) ) { continue; }
			$taken = array();
			foreach ( $params as $other ) {
				if ( $other === $param ) { continue; }
				$taken[] = $other->name;
				if ( 'map' === $other->input && V::mapping( V::get( $other, 'map' ) ) ) { $taken = array_merge( $taken, self::map_keys( $other->map ) ); }
			}
			foreach ( self::map_keys( $param->map ) as $key ) { if ( in_array( $key, $taken, true ) ) { self::error( $errors, "params[{$index}].map", "نام خروجی نقشه با پارامتر دیگری تداخل دارد: {$key}" ); } }
		}
		if ( $errors ) { throw new RecordError( $errors ); }
		$id = V::get( $raw, 'id' );
		return V::object( array(
			'id' => is_int( $id ) || is_bool( $id ) ? $id : null, 'name' => $name, 'display_name' => V::fallback( V::text( V::get( $raw, 'display_name' ) ), $name ),
			'active' => false !== V::get( $raw, 'active' ), 'logo' => $logo, 'check_price_url' => $endpoint,
			'shop_id' => self::nullable_text( V::get( $raw, 'shop_id' ) ), 'price_fields' => $prices,
			'price_currency_in' => Units::currency( V::get( $raw, 'price_currency_in' ) ), 'price_currency_out' => Units::currency( V::get( $raw, 'price_currency_out' ) ), 'price_input_param' => $money_target,
			'weight_unit_in' => Units::weight( V::get( $raw, 'weight_unit_in' ) ), 'weight_unit_out' => Units::weight( V::get( $raw, 'weight_unit_out' ) ), 'weight_input_param' => $weight_target,
			'timeout' => $timeout, 'main_price_key' => '' === $main ? null : $main, 'referral' => $referral, 'rules' => $rules, 'location_config' => $location, 'params' => $params,
		) );
	}
	public static function nullable_text( $value ): ?string { $text = V::text( $value ); return '' === $text ? null : $text; }
	private static function timeout( $value, array &$errors ) {
		if ( null === $value || ( is_string( $value ) && '' === V::trim( $value ) ) ) { return null; }
		try { if ( is_bool( $value ) ) { throw new \InvalidArgumentException(); } $seconds = V::number( $value ); }
		catch ( \InvalidArgumentException $e ) { self::error( $errors, 'timeout', 'مهلت پاسخ‌گویی باید عدد باشد.' ); return null; }
		if ( ! ( 1 <= $seconds && $seconds <= 300 ) ) { self::error( $errors, 'timeout', 'مهلت پاسخ‌گویی باید بین 1 تا 300 ثانیه باشد.' ); return null; }
		return $seconds;
	}
	private static function prices( $raw, array &$errors ): array {
		if ( null === $raw ) { return array(); }
		if ( ! V::listing( $raw ) ) { self::error( $errors, 'price_fields', 'فیلدهای قیمت باید یک لیست باشند.' ); return array(); }
		$out = array(); foreach ( $raw as $index => $item ) {
			if ( ! V::mapping( $item ) ) { self::error( $errors, "price_fields[{$index}]", 'ساختار فیلد قیمت نامعتبر است.' ); continue; }
			$key = V::text( V::get( $item, 'key' ) ); if ( '' === $key ) { continue; }
			$out[] = V::object( array( 'key' => $key, 'label' => V::fallback( V::text( V::get( $item, 'label' ) ), $key ) ) );
		} return $out;
	}
	private static function options( $raw, string $path, array &$errors ): array {
		if ( null === $raw ) { return array(); }
		if ( ! V::listing( $raw ) ) { self::error( $errors, $path, 'گزینه‌ها باید یک لیست باشند.' ); return array(); }
		$out = array(); $seen = array(); foreach ( $raw as $index => $item ) {
			if ( is_string( $item ) ) { $value = $label = V::trim( $item ); }
			elseif ( V::mapping( $item ) ) { $value = V::text( V::get( $item, 'value' ) ); $label = V::fallback( V::text( V::get( $item, 'label' ) ), $value ); }
			else { self::error( $errors, "{$path}[{$index}]", 'ساختار گزینه نامعتبر است.' ); continue; }
			if ( '' === $value ) { continue; }
			if ( in_array( $value, $seen, true ) ) { self::error( $errors, "{$path}[{$index}]", "مقدار تکراری در گزینه‌ها: {$value}" ); continue; }
			$seen[] = $value; $out[] = V::object( array( 'value' => $value, 'label' => V::fallback( $label, $value ) ) );
		} return $out;
	}
	private static function dynamic_fields( $raw, string $path, array &$errors, bool $nested = false ): array {
		if ( null === $raw ) { return array(); }
		if ( ! V::listing( $raw ) ) { self::error( $errors, $path, $nested ? 'ستون‌های لیست باید یک لیست باشند.' : 'پارامترها باید یک لیست باشند.' ); return array(); }
		$out = array(); foreach ( $raw as $index => $item ) {
			$at = "{$path}[{$index}]";
			if ( ! V::mapping( $item ) ) { self::error( $errors, $at, $nested ? 'ساختار ستون نامعتبر است.' : 'ساختار پارامتر نامعتبر است.' ); continue; }
			$name = V::text( V::get( $item, 'name' ) ); if ( '' === $name ) { continue; }
			$input = self::one_of( V::get( $item, 'input' ), $nested ? array( 'text', 'number', 'select' ) : self::INPUTS, 'text' );
			$kind = V::text( V::get( $item, 'kind' ) ); if ( ! in_array( $kind, self::KINDS, true ) ) { $kind = ''; }
			if ( in_array( $kind, array( 'city', 'province' ), true ) && 'select' !== $input && ( $nested || ! in_array( $name, array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) ) ) { self::error( $errors, "{$at}.kind", $nested ? 'زیرفیلد شهر/استان باید ورودی لیست انتخابی داشته باشد.' : 'پارامتر شهر/استان باید ورودی لیست انتخابی داشته باشد.' ); }
			if ( in_array( $kind, array( 'money', 'weight' ), true ) && ! in_array( $input, array( 'text', 'number' ), true ) ) {
				$message = $nested ? ( 'money' === $kind ? 'زیرفیلد مبلغ باید ورودی متنی یا عددی داشته باشد.' : 'زیرفیلد وزن باید ورودی متنی یا عددی داشته باشد.' ) : ( 'money' === $kind ? 'فیلد مبلغ باید ورودی متنی یا عددی داشته باشد.' : 'فیلد وزن باید ورودی متنی یا عددی داشته باشد.' );
				self::error( $errors, "{$at}.kind", $message ); $kind = '';
			}
			if ( '' !== $kind && 'map' === $input ) { self::error( $errors, "{$at}.kind", 'ورودی نقشه نمی‌تواند داده مرجع داشته باشد.' ); }
			$field = array( 'id' => self::row_id( V::get( $item, 'id' ) ), 'name' => $name, 'label' => V::fallback( V::text( V::get( $item, 'label' ) ), $name ), 'input' => $input, 'type' => self::one_of( V::get( $item, 'type' ), array( 'str', 'int', 'float' ), 'str' ), 'visible' => V::truth( V::get( $item, 'visible' ) ) );
			if ( $nested ) { $field['default'] = self::default_value( V::get( $item, 'default' ) ); $field['kind'] = '' === $kind ? null : $kind; }
			else { $field['kind'] = '' === $kind ? null : $kind; $field['description'] = V::text( V::get( $item, 'description' ) ); $field['default'] = in_array( $input, array( 'list', 'dict' ), true ) ? null : self::default_value( V::get( $item, 'default' ) ); }
			$field['required'] = ( $nested || in_array( $input, array( 'text', 'number', 'select' ), true ) ) && V::truth( V::get( $item, 'required' ) );
			if ( ! $nested && V::length( $field['description'] ) > 500 ) { self::error( $errors, "{$at}.description", 'توضیح پارامتر نباید بیشتر از ۵۰۰ نویسه باشد.' ); }
			if ( 'select' === $input ) {
				$field['options'] = self::options( V::get( $item, 'options' ), "{$at}.options", $errors );
				if ( ! $nested ) { self::select_default( $field, "{$at}.default", $errors ); }
			}
			if ( 'list' === $input ) { $min = V::get( $item, 'min_items' ); $field['min_items'] = ( is_int( $min ) || is_bool( $min ) ) && $min > 0 ? $min : 1; }
			if ( in_array( $input, array( 'list', 'dict' ), true ) ) {
				$field['fields'] = self::dynamic_fields( V::get( $item, 'fields' ), "{$at}.fields", $errors, true );
				foreach ( $field['fields'] as $sub_index => $sub ) { if ( 'select' === $sub->input ) { $row = (array) $sub; self::select_default( $row, "{$at}.fields[{$sub_index}].default", $errors ); $sub->default = $row['default']; } }
			}
			if ( 'map' === $input ) { $field['map'] = self::map_config( V::get( $item, 'map' ), "{$at}.map", $errors ); }
			$out[] = V::object( $field );
		} return $out;
	}
	private static function select_default( array &$field, string $path, array &$errors ): void {
		$text = V::text( $field['default'] );
		if ( '' !== $text && ! in_array( $text, array_map( static fn( $option ) => $option->value, $field['options'] ), true ) ) { self::error( $errors, $path, 'مقدار پیش‌فرض باید یکی از گزینه‌های تعریف‌شده باشد.' ); }
		$field['default'] = '' === $text ? null : $text;
	}
	private static function map_config( $raw, string $path, array &$errors ): \stdClass {
		$default = V::object( array( 'mode' => 'split', 'lat_param' => 'lat', 'long_param' => 'long' ) );
		if ( null === $raw ) { return $default; }
		if ( ! V::mapping( $raw ) ) { self::error( $errors, $path, 'ساختار تنظیمات نقشه نامعتبر است.' ); return $default; }
		$mode = V::fallback( V::text( V::get( $raw, 'mode' ) ), 'split' );
		if ( ! in_array( $mode, array( 'split', 'combined' ), true ) ) { self::error( $errors, "{$path}.mode", 'حالت نقشه باید split یا combined باشد.' ); $mode = 'split'; }
		if ( 'split' === $mode ) {
			$lat = V::text( V::get( $raw, 'lat_param' ) ); $long = V::text( V::get( $raw, 'long_param' ) );
			if ( '' === $lat || '' === $long ) { self::error( $errors, $path, 'در حالت جداگانه، نام پارامتر lat و long الزامی است.' ); }
			return V::object( array( 'mode' => $mode, 'lat_param' => $lat, 'long_param' => $long ) );
		}
		$param = V::text( V::get( $raw, 'param' ) ); if ( '' === $param ) { self::error( $errors, $path, 'در حالت ترکیبی، نام پارامتر الزامی است.' ); }
		return V::object( array( 'mode' => $mode, 'param' => $param ) );
	}
	private static function map_keys( $config ): array {
		$keys = 'combined' === V::get( $config, 'mode' ) ? array( V::get( $config, 'param' ) ) : array( V::get( $config, 'lat_param' ), V::get( $config, 'long_param' ) );
		return array_values( array_filter( $keys, array( V::class, 'truth' ) ) );
	}
	private static function unit_target( $raw, array $params, array &$errors, bool $weight ): ?string {
		$target = V::text( $raw ); if ( '' === $target ) { return null; }
		$key = $weight ? 'weight_input_param' : 'price_input_param'; $label = $weight ? 'وزن' : 'مبلغ';
		$pieces = explode( '.', $target, 2 ); $base = $pieces[0]; $param = null;
		foreach ( $params as $p ) { if ( $p->name === $base ) { $param = $p; } }
		if ( null === $param ) { self::error( $errors, $key, "پارامتر «{$target}» در این شرکت وجود ندارد." ); return null; }
		if ( in_array( $base, array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) ) { self::error( $errors, $key, "پارامتر {$label} ورودی نمی‌تواند از فیلدهای مبدا باشد." ); return null; }
		if ( ! isset( $pieces[1] ) ) {
			if ( ! in_array( $param->input, array( 'text', 'number' ), true ) ) { self::error( $errors, $key, "پارامتر «{$base}» ورودی متنی یا عددی ندارد." ); return null; }
			return $base;
		}
		if ( ! in_array( $param->input, array( 'list', 'dict' ), true ) ) { self::error( $errors, $key, "«{$base}» لیست یا شیء نیست؛ زیرفیلد ندارد." ); return null; }
		$field = null; foreach ( ProviderDefinition::fields( $param ) as $f ) { if ( $f->name === $pieces[1] ) { $field = $f; break; } }
		if ( null === $field ) { self::error( $errors, $key, "زیرفیلد «{$target}» در این شرکت وجود ندارد." ); return null; }
		if ( ! in_array( $field->input, array( 'text', 'number' ), true ) ) { self::error( $errors, $key, "زیرفیلد «{$target}» ورودی متنی یا عددی ندارد." ); return null; }
		return $base . '.' . $pieces[1];
	}
	private static function referral( $raw, array &$errors ): \stdClass {
		$out = array( 'enabled' => false, 'title' => '', 'url' => '', 'description' => '' );
		if ( null !== $raw && ! V::mapping( $raw ) ) { self::error( $errors, 'referral', 'ساختار لینک ارجاع نامعتبر است.' ); }
		elseif ( V::mapping( $raw ) ) {
			$flag = V::get( $raw, 'enabled' );
			$out['enabled'] = true === $flag || ( ( is_int( $flag ) || is_float( $flag ) ) && 1 == $flag ) || ( is_string( $flag ) && in_array( strtolower( V::trim( $flag ) ), array( '1', 'true', 'on', 'yes' ), true ) );
			foreach ( array( 'title', 'url', 'description' ) as $key ) { $out[$key] = V::text( V::get( $raw, $key ) ); }
		}
		foreach ( array( 'title' => array( 120, 'عنوان دکمه' ), 'url' => array( 500, 'لینک ارجاع' ), 'description' => array( 500, 'توضیحات' ) ) as $key => $bound ) { if ( V::length( $out[$key] ) > $bound[0] ) { self::error( $errors, 'referral.' . $key, "{$bound[1]} حداکثر {$bound[0]} نویسه باشد." ); } }
		if ( $out['enabled'] ) {
			if ( '' === $out['title'] ) { self::error( $errors, 'referral.title', 'عنوان دکمه لینک ارجاع الزامی است.' ); }
			if ( '' === $out['url'] ) { self::error( $errors, 'referral.url', 'لینک ارجاع الزامی است.' ); }
			elseif ( ! V::http_url( $out['url'] ) ) { self::error( $errors, 'referral.url', 'لینک ارجاع باید یک URL کامل http یا https باشد.' ); }
		} return V::object( $out );
	}
	private static function rule_target( $raw, array $info, string $path, array &$errors ): string {
		$text = V::text( $raw ); if ( '' === $text ) { return ''; } $pieces = explode( '.', $text, 2 );
		if ( ! isset( $info[$pieces[0]] ) ) { self::error( $errors, $path, "فیلد «{$text}» در این شرکت وجود ندارد." ); return ''; }
		if ( isset( $pieces[1] ) && ! in_array( $pieces[1], $info[$pieces[0]], true ) ) { self::error( $errors, $path, "زیرفیلد «{$text}» در این شرکت وجود ندارد." ); return ''; }
		return $text;
	}
	private static function rule_clause( $op, $first, $second, string $path, array &$errors ): array {
		if ( ! in_array( $op, self::OPS, true ) ) { self::error( $errors, $path, "عملگر نامعتبر است: {$op}" ); return array( '', '', '' ); }
		$first = V::text( $first ); $second = V::text( $second );
		if ( in_array( $op, array( 'gt', 'gte', 'lt', 'lte', 'between' ), true ) ) {
			try { V::number( $first ); } catch ( \InvalidArgumentException $e ) { self::error( $errors, $path, 'مقدار شرط عددی باید عدد باشد.' ); }
			if ( 'between' === $op ) { try { V::number( $second ); } catch ( \InvalidArgumentException $e ) { self::error( $errors, $path, 'مقدار دوم بازه باید عدد باشد.' ); } }
		}
		if ( 'in' === $op && '' === $first ) { self::error( $errors, $path, 'لیست مقادیر مجاز خالی است.' ); }
		return array( $op, $first, $second );
	}
	private static function rules( $raw, array $params, array &$errors ): array {
		if ( null === $raw ) { return array(); }
		if ( ! V::listing( $raw ) ) { self::error( $errors, 'rules', 'شرط‌ها باید یک لیست باشند.' ); return array(); }
		$info = array(); foreach ( $params as $param ) {
			if ( in_array( $param->name, array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) || 'map' === $param->input ) { continue; }
			$info[$param->name] = array_map( static fn( $f ) => $f->name, ProviderDefinition::fields( $param ) );
		}
		if ( count( $raw ) > 50 ) { self::error( $errors, 'rules', 'حداکثر 50 شرط برای هر شرکت.' ); $raw = array_slice( $raw, 0, 50 ); }
		$out = array(); foreach ( $raw as $index => $item ) {
			$path = "rules[{$index}]";
			if ( ! V::mapping( $item ) ) { self::error( $errors, $path, 'ساختار شرط نامعتبر است.' ); continue; }
			$if_field = self::rule_target( V::get( $item, 'if_field' ), $info, "{$path}.if_field", $errors );
			list( $if_op, $if_value, $if_value2 ) = self::rule_clause( V::text( V::get( $item, 'if_op' ) ), V::get( $item, 'if_value' ), V::get( $item, 'if_value2' ), $path, $errors );
			$then_field = $then_op = $then_value = $then_value2 = '';
			if ( '' !== V::text( V::get( $item, 'then_field' ) ) ) {
				$then_field = self::rule_target( V::get( $item, 'then_field' ), $info, "{$path}.then_field", $errors );
				list( $then_op, $then_value, $then_value2 ) = self::rule_clause( V::text( V::get( $item, 'then_op' ) ), V::get( $item, 'then_value' ), V::get( $item, 'then_value2' ), $path, $errors );
			}
			$message = V::text( V::get( $item, 'message' ) ); $name = V::text( V::get( $item, 'name' ) );
			if ( V::length( $message ) > 500 ) { self::error( $errors, "{$path}.message", 'متن خطا حداکثر 500 نویسه باشد.' ); $message = V::slice( $message, 500 ); }
			if ( V::length( $name ) > 120 ) { self::error( $errors, "{$path}.name", 'نام شرط حداکثر 120 نویسه باشد.' ); $name = V::slice( $name, 120 ); }
			if ( '' === $if_field || '' === $if_op ) { continue; }
			$out[] = V::object( array( 'name' => $name, 'if_field' => $if_field, 'if_op' => $if_op, 'if_value' => $if_value, 'if_value2' => $if_value2, 'then_field' => $then_field, 'then_op' => $then_op, 'then_value' => $then_value, 'then_value2' => $then_value2, 'message' => $message ) );
		} return $out;
	}
	private static function custom_locations( $raw, array &$errors, string $prefix ): \stdClass {
		$out = array( 'provinces' => array(), 'cities' => array() );
		if ( null === $raw ) { return V::object( $out ); }
		if ( ! V::mapping( $raw ) ) { self::error( $errors, $prefix, 'ساختار لیست دستی شهر و استان نامعتبر است.' ); return V::object( $out ); }
		foreach ( array( 'provinces' => array( 500, 'استان', 'استان‌های' ), 'cities' => array( 20000, 'شهر', 'شهرهای' ) ) as $key => $bound ) {
			$rows = V::get( $raw, $key, array() ); if ( null === $rows ) { $rows = array(); }
			if ( ! V::listing( $rows ) ) { self::error( $errors, "{$prefix}.{$key}", "لیست {$bound[2]} دستی باید یک لیست باشد." ); $rows = array(); }
			if ( count( $rows ) > $bound[0] ) { self::error( $errors, "{$prefix}.{$key}", "حداکثر {$bound[0]} {$bound[1]} دستی برای هر شرکت." ); $rows = array_slice( $rows, 0, $bound[0] ); }
			$seen = array(); foreach ( $rows as $index => $item ) {
				$path = "{$prefix}.{$key}[{$index}]"; $label = $bound[1];
				if ( ! V::mapping( $item ) ) { self::error( $errors, $path, "ساختار {$label} نامعتبر است." ); continue; }
				$code = V::text( V::get( $item, 'code' ) ); $title = V::text( V::get( $item, 'title' ) ); $province = V::text( V::get( $item, 'province_code' ) );
				if ( '' === $code ) { self::error( $errors, $path, "کد {$label} الزامی است." ); continue; }
				if ( V::length( $code ) > 30 ) { self::error( $errors, $path, "کد {$label} حداکثر 30 نویسه باشد." ); continue; }
				if ( in_array( $code, $seen, true ) ) { self::error( $errors, $path, "کد {$label} تکراری: {$code}" ); continue; }
				if ( 'cities' === $key && '' === $province ) { self::error( $errors, $path, 'کد استان شهر الزامی است.' ); continue; }
				if ( V::length( $title ) > 120 ) { self::error( $errors, $path, "عنوان {$label} حداکثر 120 نویسه باشد." ); $title = V::slice( $title, 120 ); }
				$seen[] = $code; $row = array( 'code' => $code, 'title' => V::fallback( $title, $code ) ); if ( 'cities' === $key ) { $row['province_code'] = $province; } $out[$key][] = V::object( $row );
			}
		} return V::object( $out );
	}
	private static function location( $raw, array $params, array &$errors ): \stdClass {
		$out = ProviderDefinition::location_defaults(); if ( null === $raw ) { return $out; }
		if ( ! V::mapping( $raw ) ) { self::error( $errors, 'location_config', 'ساختار تنظیمات شهر و استان نامعتبر است.' ); return $out; }
		$by_name = array(); foreach ( $params as $p ) { $by_name[$p->name] = $p; }
		$origin = V::get( $raw, 'origin' ); $dest = V::get( $raw, 'destination' );
		$out->origin = V::object( array( 'enabled' => false !== V::get( $origin, 'enabled', true ), 'display' => self::one_of( V::get( $origin, 'display' ), array( 'separate', 'combined' ), 'separate' ), 'source' => self::one_of( V::get( $origin, 'source' ), array( 'service', 'custom' ), 'service' ), 'custom_mode' => self::one_of( V::get( $origin, 'custom_mode' ), array( 'only', 'merge' ), 'merge' ) ) );
		$enabled = true === V::get( $dest, 'enabled', false ); $display = self::one_of( V::get( $dest, 'display' ), array( 'separate', 'combined' ), 'separate' );
		$province = self::nullable_text( V::get( $dest, 'province_param' ) ); $city = self::nullable_text( V::get( $dest, 'city_param' ) );
		if ( $enabled ) {
			foreach ( array( 'province_param' => array( $province, 'فیلد استان مقصد' ), 'city_param' => array( $city, 'فیلد شهر مقصد' ) ) as $key => $pair ) {
				if ( null === $pair[0] || ! isset( $by_name[$pair[0]] ) ) { self::error( $errors, "location_config.destination.{$key}", "{$pair[1]} نامعتبر است." ); }
				elseif ( in_array( $pair[0], array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) ) { self::error( $errors, "location_config.destination.{$key}", "{$pair[1]} نمی‌تواند از فیلدهای مبدا باشد." ); }
			}
			if ( null !== $province && null !== $city && $province === $city && isset( $by_name[$province] ) ) { self::error( $errors, 'location_config.destination.city_param', 'فیلد استان و شهر مقصد باید دو فیلد جدا باشند.' ); }
			if ( 'combined' === $display && isset( $by_name[$province], $by_name[$city] ) && ( 'select' !== $by_name[$province]->input || 'province' !== $by_name[$province]->kind || 'select' !== $by_name[$city]->input || 'city' !== $by_name[$city]->kind ) ) { self::error( $errors, 'location_config.destination.display', 'برای نمایش ترکیبی، فیلد استان باید داده استان و فیلد شهر باید داده شهر باشد (ورودی لیست انتخابی).' ); }
			foreach ( array( 'province_param' => array( $province, 'فیلد استان مقصد' ), 'city_param' => array( $city, 'فیلد شهر مقصد' ) ) as $key => $pair ) { if ( null !== $pair[0] && isset( $by_name[$pair[0]] ) && ! $by_name[$pair[0]]->visible ) { self::error( $errors, "location_config.destination.{$key}", "{$pair[1]} باید نمایشی باشد." ); } }
		}
		$out->destination = V::object( array( 'enabled' => $enabled, 'display' => $display, 'province_param' => $province, 'city_param' => $city, 'source' => self::one_of( V::get( $dest, 'source' ), array( 'service', 'custom' ), 'service' ), 'custom_mode' => self::one_of( V::get( $dest, 'custom_mode' ), array( 'only', 'merge' ), 'merge' ) ) );
		$out->custom_locations = self::custom_locations( V::get( $raw, 'custom_locations' ), $errors, 'location_config.custom_locations' );
		$out->blocked_locations = self::custom_locations( V::get( $raw, 'blocked_locations' ), $errors, 'location_config.blocked_locations' );
		return $out;
	}
}
