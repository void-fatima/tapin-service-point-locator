<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Shared Stage 6 execution policy. One instance/client per job keeps credentials local. */
final class QuoteExecution {
	private QuoteStore $store;
	private ProviderClient $client;
	public function __construct( QuoteStore $store, ProviderTransport $transport ) { $this->store = $store; $this->client = new ProviderClient( $transport, new ProviderRequestBuilder( array( $store, 'shop' ) ) ); }
	public static function body( string $raw ): \stdClass {
		if ( '' === $raw ) { return new \stdClass(); }
		if ( 0 === strpos( $raw, "\xef\xbb\xbf" ) ) { $raw = substr( $raw, 3 ); }
		if ( false !== strpos( $raw, "\0" ) || 1 !== preg_match( '//u', $raw ) ) { throw new \DomainException( 'Non-UTF8 request JSON needs an explicit Python/WordPress compatibility decision.' ); }
		try { $body = QuoteJson::decode( $raw ); } catch ( \InvalidArgumentException $error ) { throw new QuoteError( 400, 'بدنه درخواست JSON معتبر نیست.' ); }
		if ( ! V::mapping( $body ) ) { throw new QuoteError( 400, 'بدنه درخواست باید یک شیء JSON باشد.' ); } return $body;
	}
	public function normalise( $provider, $incoming ): NormalizedPayload {
		list( $values, $errors ) = PayloadNormalizer::prepare( $provider, $incoming ); $shops = array();
		$origin = array_filter( ProviderDefinition::params( $provider ), static fn( $param ) => in_array( V::get( $param, 'name' ), array( ProviderDefinition::ORIGIN_PROVINCE, ProviderDefinition::ORIGIN_CITY ), true ) );
		$province = PayloadNormalizer::region_code( $values[ProviderDefinition::ORIGIN_PROVINCE] ?? null ); $default = $values['shop_id'] ?? null;
		if ( $origin && ( null !== $province || ( null !== $default && '' !== $default ) ) ) {
			try { $shops = $this->store->shops(); }
			catch ( \Throwable $error ) { if ( null !== $province ) { $errors[] = array( 'field' => ProviderDefinition::ORIGIN_PROVINCE, 'message' => 'خواندن شعب ناموفق بود.' ); throw new PayloadError( $errors ); } }
		}
		return PayloadNormalizer::finish( $provider, $values, $errors, $shops );
	}
	public static function timeout( $provider ): float {
		try { $value = V::number( V::fallback( V::get( $provider, 'timeout' ), 0 ) ); } catch ( \InvalidArgumentException $error ) { $value = 0.0; }
		return $value <= 0 ? 15.0 : $value;
	}
	public static function log_params( NormalizedPayload $payload ): \stdClass { $values = (array) $payload->values; if ( V::truth( $payload->origin ) ) { $values['origin'] = $payload->origin; } return (object) $values; }
	private static function flags( $quote, $provider = null ): \stdClass {
		if ( ! V::mapping( $quote ) ) { throw new \UnexpectedValueException( 'Invalid stored quote.' ); }
		$values = (array) $quote; $prices = V::fallback( V::get( $quote, 'prices' ), array() );
		foreach ( $prices as &$row ) { if ( V::mapping( $row ) ) { $row = (array) $row; unset( $row['main'] ); } } unset( $row );
		$values['prices'] = null === $provider ? $prices : ProviderResponse::mark_main( $prices, $provider ); return (object) $values;
	}
	/** Snapshot lookups before blocking bulk calls, matching gather's cache-before-await decisions. */
	public function plan( $provider, NormalizedPayload $payload ): array {
		$key = PriceCacheKey::make( $provider, self::log_params( $payload ) ); $entry = null;
		try { $entry = $this->store->cache_get( $provider->id, $key ); } catch ( \Throwable $ignored ) {}
		return array( 'key' => $key, 'entry' => $entry, 'fresh' => null !== $entry && $this->store->cache_fresh( $entry['created_at'] ?? null ) );
	}
	public function fetch( $provider, NormalizedPayload $payload, bool $preview = false, ?array $plan = null ): array {
		if ( $preview ) { return array( $this->client->fetch( $provider, $payload->values, self::timeout( $provider ) ), false ); }
		$plan = $plan ?? $this->plan( $provider, $payload ); $key = $plan['key']; $entry = $plan['entry']; $params = self::log_params( $payload );
		if ( $plan['fresh'] ) { return array( self::flags( $entry['quote'], $provider ), true ); }
		try { $quote = $this->client->fetch( $provider, $payload->values, self::timeout( $provider ) ); }
		catch ( ProviderCallError $error ) { if ( 504 === $error->status_code && null !== $entry ) { return array( self::flags( $entry['quote'], $provider ), true ); } throw $error; }
		try { $this->store->cache_save( $this->client->clean( (object) array( 'provider_id' => $provider->id, 'provider_name' => V::fallback( V::get( $provider, 'name' ), '' ), 'params_hash' => $key, 'params' => $params, 'endpoint' => V::fallback( V::get( $quote, 'endpoint' ), '' ), 'quote' => self::flags( $quote ) ) ) ); } catch ( \Throwable $ignored ) {}
		return array( $quote, false );
	}
	public function log_failure( $provider, NormalizedPayload $payload, ProviderCallError $error ): void {
		try { $this->store->log_save( $this->client->clean( (object) array( 'provider' => $provider, 'payload' => self::log_params( $payload ), 'status' => 'error', 'error_message' => $error->getMessage(), 'response' => $error->extra['provider_response'] ?? null, 'endpoint' => V::string( V::fallback( $error->extra['endpoint'] ?? null, '' ) ) ) ) ); } catch ( \Throwable $ignored ) {}
	}
	public function clean( $value ) { return $this->client->clean( $value ); }
	public function result( $provider, $quote, bool $cached ): array { return (array) $this->clean( ProviderResponse::result( $provider, $quote, $cached ) ); }
}
