<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Pure provider_calls.py response/price rules; HTTP status never invents or suppresses a price. */
final class ProviderResponse {
	const KNOWN = array( 'send_price' => 'هزینه ارسال', 'just_send_price' => 'هزینه ارسال بدون احتساب پرداخت در محل', 'tax' => 'مالیات', 'total' => 'جمع کل' );
	public static function number( $value ) {
		if ( null === $value || is_bool( $value ) ) { return null; }
		if ( is_int( $value ) || is_float( $value ) ) { return $value; }
		$text = V::text( $value ); if ( '' === $text ) { return null; }
		try { return V::integer( $text ); } catch ( \InvalidArgumentException $error ) {
			try { return V::number( $text ); } catch ( \InvalidArgumentException $error ) { return null; }
		}
	}
	private static function provider_status( $value ) {
		if ( is_bool( $value ) ) { return null; }
		if ( is_int( $value ) ) { return $value; }
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) { return V::bounded_integer( $value ); }
		$text = null === $value ? '' : V::text( $value ); if ( '' === $text ) { return null; }
		try { return V::integer( $text ); } catch ( \InvalidArgumentException $error ) { return $text; }
	}
	public static function lookup( $body, string $path ) {
		$current = $body;
		foreach ( explode( '.', $path ) as $part ) {
			if ( V::mapping( $current ) ) { $current = V::get( $current, $part ); }
			elseif ( V::listing( $current ) ) {
				try { $index = V::integer( $part ); } catch ( \InvalidArgumentException $error ) { return null; }
				if ( $index < 0 ) { $index += count( $current ); } $current = $current[$index] ?? null;
			} else { return null; }
		}
		return $current;
	}
	public static function missing( $body, array $fields ): array {
		$out = array(); foreach ( $fields as $field ) {
			$key = V::text( V::fallback( V::get( $field, 'key' ), '' ) );
			if ( '' !== $key && null === self::number( self::lookup( $body, $key ) ) ) { $out[] = $key; }
		} return $out;
	}
	public static function candidates( $body, int $limit = 40 ): array {
		$out = array(); $walk = static function( $node, string $path ) use ( &$walk, &$out, $limit ): void {
			if ( count( $out ) >= $limit ) { return; }
			if ( V::mapping( $node ) || is_array( $node ) ) {
				foreach ( (array) $node as $key => $value ) { $next = V::mapping( $node ) ? ( '' === $path ? (string) $key : $path . '.' . $key ) : $path . '.' . $key; $walk( $value, $next ); if ( count( $out ) >= $limit ) { break; } }
				return;
			}
			$amount = self::number( $node ); if ( null !== $amount && '' !== $path ) { $parts = explode( '.', $path ); $out[] = array( 'key' => $path, 'label' => end( $parts ), 'value' => $amount ); }
		}; $walk( $body, '' ); return $out;
	}
	public static function extract( $body, array $fields = array() ): array {
		$prices = array();
		if ( $fields ) {
			foreach ( $fields as $field ) {
				$key = V::text( V::fallback( V::get( $field, 'key' ), '' ) ); if ( '' === $key ) { continue; }
				$amount = self::number( self::lookup( $body, $key ) ); if ( null !== $amount ) { $prices[] = array( 'label' => V::string( V::fallback( V::get( $field, 'label' ), $key ) ), 'value' => $amount ); }
			} return $prices;
		}
		if ( V::mapping( $body ) ) {
			foreach ( self::KNOWN as $key => $label ) { foreach ( array( $key, 'entries.' . $key ) as $path ) {
				$amount = self::number( self::lookup( $body, $path ) ); if ( null !== $amount ) { $prices[] = array( 'label' => $label, 'value' => $amount ); break; }
			} } return $prices;
		}
		if ( V::listing( $body ) ) { foreach ( $body as $item ) {
			if ( ! V::mapping( $item ) ) { continue; }
			$amount = self::number( V::get( $item, 'value', V::get( $item, 'price', V::get( $item, 'amount', V::get( $item, 'total' ) ) ) ) );
			if ( null === $amount ) { continue; }
			$label = V::get( $item, 'label', V::get( $item, 'title', V::get( $item, 'name', 'مبلغ' ) ) ); $prices[] = array( 'label' => null === $label ? '' : V::string( $label ), 'value' => $amount );
		} } return $prices;
	}
	public static function parse( int $status, string $raw, string $endpoint ): array {
		$text = QuoteJson::utf8( $raw ); $context = array( 'endpoint' => $endpoint, 'http_status' => $status );
		if ( '' === V::trim( $text ) ) { throw new ProviderCallError( 'سرویس قیمت پاسخ خالی برگرداند.', $context ); }
		try { $body = QuoteJson::decode( $text ); }
		catch ( \InvalidArgumentException $error ) { throw new ProviderCallError( 'پاسخ سرویس قیمت JSON معتبر نیست.', $context + array( 'provider_response' => V::slice( $text, 2000 ) ) ); }
		$provider_status = null; $message = ''; $returns = V::get( $body, 'returns' );
		if ( V::mapping( $body ) && V::mapping( $returns ) ) { $provider_status = self::provider_status( V::get( $returns, 'status' ) ); $value = V::get( $returns, 'message' ); $message = null === $value ? '' : V::string( $value ); }
		if ( null === $body ) { throw new ProviderCallError( 'سرویس قیمت داده‌ای برنگرداند.', $context + array( 'provider_status' => $provider_status ) ); }
		return array( $body, $provider_status, $message );
	}
	public static function evaluate( $body, array $fields = array(), array $context = array() ): array {
		$prices = self::extract( $body, $fields ); if ( $prices ) { return array( $prices, self::missing( $body, $fields ) ); }
		$configured = array(); foreach ( $fields as $field ) { $key = V::text( V::fallback( V::get( $field, 'key' ), '' ) ); if ( '' !== $key ) { $configured[] = $key; } }
		$message = $configured ? 'پاسخ سرویس قیمت هیچ‌کدام از فیلدهای تنظیم‌شده را ندارد.' : 'برای این شرکت هیچ فیلد قیمتی تنظیم نشده است.';
		if ( $configured ) { $message .= ' فیلدهای تنظیم‌شده: ' . implode( '، ', $configured ) . '.'; }
		$context['provider_message'] = V::fallback( $context['provider_message'] ?? null, null ); $context['provider_response'] = $body;
		$context['errors'] = array_map( static fn( $key ) => array( 'field' => $key, 'message' => 'در پاسخ سرویس پیدا نشد.' ), $configured );
		$context['extra'] = array( 'provider_response' => $body, 'missing_price_fields' => $configured, 'price_candidates' => self::candidates( $body ), 'provider_message' => $context['provider_message'], 'provider_status' => $context['provider_status'] ?? null, 'endpoint' => $context['endpoint'] ?? null );
		throw new ProviderCallError( $message, $context );
	}
	public static function mark_main( array $prices, $provider ): array {
		$main_key = V::text( V::fallback( V::get( $provider, 'main_price_key' ), '' ) ); $label = null;
		foreach ( V::fallback( V::get( $provider, 'price_fields' ), array() ) as $field ) { if ( V::mapping( $field ) && V::text( V::fallback( V::get( $field, 'key' ), '' ) ) === $main_key ) { $label = V::string( V::fallback( V::get( $field, 'label' ), V::get( $field, 'key' ) ) ); break; } }
		foreach ( $prices as $index => &$row ) {
			if ( ! V::mapping( $row ) ) { throw new \UnexpectedValueException( 'Invalid stored price row.' ); }
			$row = (array) $row; $row['main'] = ( null !== $label && V::get( $row, 'label' ) === $label ) || ( null === $label && 0 === $index );
		} unset( $row ); return $prices;
	}
	public static function referral( $provider ): ?array {
		$referral = V::get( $provider, 'referral' ); if ( ! V::mapping( $referral ) || ! V::truth( V::get( $referral, 'enabled' ) ) ) { return null; }
		$url = V::text( V::fallback( V::get( $referral, 'url' ), '' ) ); if ( ! V::http_url( $url ) ) { return null; }
		$title = V::text( V::fallback( V::get( $referral, 'title' ), '' ) ); if ( '' === $title ) { return null; }
		return array( 'title' => $title, 'url' => $url, 'description' => V::text( V::fallback( V::get( $referral, 'description' ), '' ) ) );
	}
	public static function result( $provider, $quote, bool $cached = false ): array {
		list( , $display ) = Units::currency_pair( $provider );
		return array( 'provider' => array( 'id' => V::get( $provider, 'id' ), 'name' => V::get( $provider, 'name' ), 'display_name' => V::get( $provider, 'display_name' ), 'logo' => V::fallback( V::get( $provider, 'logo' ), '' ) ), 'prices' => V::get( $quote, 'prices' ), 'currency' => $display, 'currency_label' => 'toman' === $display ? 'تومان' : 'ریال', 'referral' => self::referral( $provider ), 'cached' => $cached );
	}
}
