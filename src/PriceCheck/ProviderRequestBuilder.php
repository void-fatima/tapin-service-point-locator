<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source passthrough adapters and conditional server-only shop authorization. */
final class ProviderRequestBuilder {
	private $shops;
	public function __construct( ?callable $shops = null ) { $this->shops = $shops ?? array( ShopStorage::class, 'get_private' ); }
	public static function endpoint( $raw ): string {
		$endpoint = null === $raw ? '' : V::text( $raw );
		if ( '' === $endpoint ) { throw new ProviderCallError( 'نشانی سرویس قیمت این شرکت تنظیم نشده است.', array( 'field' => 'check_price_url' ) ); }
		if ( ! V::http_url( $endpoint ) ) { throw new ProviderCallError( 'نشانی سرویس قیمت باید یک URL کامل http یا https باشد.', array( 'field' => 'check_price_url', 'endpoint' => $endpoint ) ); }
		return $endpoint;
	}
	public static function shop_id( $value ): string {
		if ( null === $value || is_bool( $value ) ) { throw new ProviderCallError( 'شناسه شعبه برای استعلام زنده قیمت الزامی است. آن را در تنظیمات شرکت (شناسه شعبه پیش‌فرض) تنظیم کنید.', array( 'status_code' => 422, 'field' => 'shop_id' ) ); }
		if ( is_int( $value ) ) { return V::string( $value ); }
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || floor( $value ) !== $value ) { throw new ProviderCallError( 'شناسه شعبه معتبر نیست.', array( 'status_code' => 422, 'field' => 'shop_id' ) ); }
			return V::string( V::bounded_integer( $value ) );
		}
		$text = V::text( $value );
		if ( '' === $text ) { throw new ProviderCallError( 'شناسه شعبه برای استعلام زنده قیمت الزامی است.', array( 'status_code' => 422, 'field' => 'shop_id' ) ); }
		return $text;
	}
	public function build( $provider, \stdClass $payload, float $timeout = 15.0 ): ProviderRequest {
		$url = self::endpoint( V::get( $provider, 'check_price_url' ) );
		$body = ConfigurationJson::decode( ConfigurationJson::encode( $payload ) ); $headers = array(); $secrets = new QuoteSecrets();
		$name = V::text( V::fallback( V::get( $provider, 'name' ), '' ) );
		$origin = array_filter( ProviderDefinition::params( $provider ), static fn( $param ) => in_array( V::get( $param, 'name' ), array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) );
		if ( V::has( $payload, 'shop_id' ) && ( in_array( $name, array( 'post', 'irpost', 'tipax' ), true ) || $origin ) ) {
			$key = self::shop_id( $payload->shop_id ); $shop = ( $this->shops )( $key );
			if ( null === $shop ) { throw new ProviderCallError( "شعبه {$key} در مدیریت شعب ثبت نشده است.", array( 'status_code' => 422, 'field' => 'shop_id' ) ); }
			$raw = V::get( $shop, 'token' ); $token = null === $raw || is_bool( $raw ) ? '' : V::text( $raw );
			if ( '' === $token ) { throw new ProviderCallError( "توکن شعبه {$key} تنظیم نشده است.", array( 'status_code' => 422, 'field' => 'shop_id' ) ); }
			$headers['Authorization'] = $token; $secrets->remember( $token );
		}
		return new ProviderRequest( $url, $body, $headers, $timeout, $secrets );
	}
}
