<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** api.py::check_price without routing, request guard, cookies or frontend integration. */
final class SingleQuoteService {
	private QuoteStore $store;
	private ProviderTransport $transport;
	public function __construct( ?ProviderTransport $transport = null, ?QuoteStore $store = null ) { $this->transport = $transport ?? new WordPressProviderTransport(); $this->store = $store ?? new WordPressQuoteStore(); }
	public function execute( string $raw, $query_provider = null ): array {
		try {
			$body = new \stdClass();
			if ( '' !== $raw ) {
				if ( 0 === strpos( $raw, "\xef\xbb\xbf" ) ) { $raw = substr( $raw, 3 ); }
				if ( false !== strpos( $raw, "\0" ) || 1 !== preg_match( '//u', $raw ) ) { throw new \DomainException( 'Non-UTF8 request JSON needs an explicit Python/WordPress compatibility decision.' ); }
				try { $body = QuoteJson::decode( $raw ); } catch ( \InvalidArgumentException $error ) { throw new QuoteError( 400, 'بدنه درخواست JSON معتبر نیست.' ); }
				if ( ! V::mapping( $body ) ) { throw new QuoteError( 400, 'بدنه درخواست باید یک شیء JSON باشد.' ); }
			}
			$result = $this->quote( $body, $query_provider );
			// Python JSONResponse rejects non-finite public values rather than sending success.
			if ( false === json_encode( $result ) ) { throw new \UnexpectedValueException( 'Unserializable public quote.' ); }
			return $result;
		} catch ( QuoteError $error ) { throw $error; }
		catch ( \DomainException $error ) { throw $error; }
		catch ( \Throwable $error ) { throw new QuoteError( 500, 'internal error', null ); }
	}
	private function quote( \stdClass $body, $query_provider ): array {
		$identifier = V::fallback( V::get( $body, 'provider' ), $query_provider );
		if ( ! V::truth( $identifier ) ) { throw new QuoteError( 400, 'فیلد provider الزامی است.' ); }
		$provider = $this->store->provider( V::text( $identifier ) );
		if ( null === $provider ) { throw new QuoteError( 404, 'شرکت مورد نظر یافت نشد.' ); }
		if ( ! V::truth( V::get( $provider, 'active', true ) ) ) { throw new QuoteError( 404, 'این شرکت غیرفعال است.' ); }
		$incoming = V::get( $body, 'values', V::get( $body, 'payload', new \stdClass() ) );
		if ( null === $incoming ) { $incoming = new \stdClass(); }
		if ( ! V::mapping( $incoming ) ) { throw new QuoteError( 400, 'فیلد values باید یک شیء باشد.' ); }
		try { $payload = $this->normalise( $provider, $incoming ); }
		catch ( PayloadError $error ) { throw new QuoteError( 422, 'مقادیر ارسالی با تعریف فیلدها مطابقت ندارد.', $error->errors ); }
		$client = new ProviderClient( $this->transport, new ProviderRequestBuilder( array( $this->store, 'shop' ) ) );
		try { list( $quote, $cached ) = $this->with_cache( $provider, $payload, $client ); }
		catch ( ProviderCallError $error ) {
			try {
				$this->store->log_save( $client->clean( (object) array( 'provider' => $provider, 'payload' => self::log_params( $payload ), 'status' => 'error', 'error_message' => $error->getMessage(), 'response' => $error->extra['provider_response'] ?? null, 'endpoint' => V::string( V::fallback( $error->extra['endpoint'] ?? null, '' ) ) ) ) );
			} catch ( \Throwable $ignored ) {}
			if ( V::truth( $error->extra['missing_price_fields'] ?? null ) ) { throw new QuoteError( $error->status_code, 'سرویس در حال حاضر پاسخگو نیست.' ); }
			throw new QuoteError( $error->status_code, $error->getMessage(), $error->errors );
		}
		$result = ProviderResponse::result( $provider, $quote, $cached );
		return (array) $client->clean( $result );
	}
	private function normalise( $provider, $incoming ): NormalizedPayload {
		list( $values, $errors ) = PayloadNormalizer::prepare( $provider, $incoming ); $shops = array();
		$origin = array_filter( ProviderDefinition::params( $provider ), static fn( $param ) => in_array( V::get( $param, 'name' ), array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) );
		$province = PayloadNormalizer::region_code( $values[ProviderDefinition::ORIGIN_PROVINCE] ?? null ); $default = $values['shop_id'] ?? null;
		if ( $origin && ( null !== $province || ( null !== $default && '' !== $default ) ) ) {
			try { $shops = $this->store->shops(); }
			catch ( \Throwable $error ) {
				if ( null !== $province ) { $errors[] = array( 'field' => ProviderDefinition::ORIGIN_PROVINCE, 'message' => 'خواندن شعب ناموفق بود.' ); throw new PayloadError( $errors ); }
			}
		}
		return PayloadNormalizer::finish( $provider, $values, $errors, $shops );
	}
	public static function timeout( $provider ): float {
		try { $value = V::number( V::fallback( V::get( $provider, 'timeout' ), 0 ) ); } catch ( \InvalidArgumentException $error ) { $value = 0.0; }
		return $value <= 0 ? 15.0 : $value;
	}
	public static function log_params( NormalizedPayload $payload ): \stdClass {
		$values = (array) $payload->values; if ( V::truth( $payload->origin ) ) { $values['origin'] = $payload->origin; } return (object) $values;
	}
	private static function flags( $quote, $provider = null ): \stdClass {
		if ( ! V::mapping( $quote ) ) { throw new \UnexpectedValueException( 'Invalid stored quote.' ); }
		$values = (array) $quote; $prices = V::fallback( V::get( $quote, 'prices' ), array() );
		foreach ( $prices as &$row ) { if ( V::mapping( $row ) ) { $row = (array) $row; unset( $row['main'] ); } } unset( $row );
		$values['prices'] = null === $provider ? $prices : ProviderResponse::mark_main( $prices, $provider ); return (object) $values;
	}
	private function with_cache( $provider, NormalizedPayload $payload, ProviderClient $client ): array {
		$params = self::log_params( $payload ); $key = PriceCacheKey::make( $provider, $params ); $entry = null;
		try { $entry = $this->store->cache_get( $provider->id, $key ); } catch ( \Throwable $ignored ) {}
		if ( null !== $entry && $this->store->cache_fresh( $entry['created_at'] ?? null ) ) { return array( self::flags( $entry['quote'], $provider ), true ); }
		try { $quote = $client->fetch( $provider, $payload->values, self::timeout( $provider ) ); }
		catch ( ProviderCallError $error ) { if ( 504 === $error->status_code && null !== $entry ) { return array( self::flags( $entry['quote'], $provider ), true ); } throw $error; }
		try { $this->store->cache_save( $client->clean( (object) array( 'provider_id' => $provider->id, 'provider_name' => V::fallback( V::get( $provider, 'name' ), '' ), 'params_hash' => $key, 'params' => $params, 'endpoint' => V::fallback( V::get( $quote, 'endpoint' ), '' ), 'quote' => self::flags( $quote ) ) ) ); } catch ( \Throwable $ignored ) {}
		return array( $quote, false );
	}
}
