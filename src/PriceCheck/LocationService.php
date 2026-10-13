<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** locations.py snapshot/credentials policy over injectable HTTP, cache, clock and checked stores. */
final class LocationService {
	const URL = 'https://api.tapin.ir/api/v4/location/public/all/city/filter/';
	const TTL = 21600;
	private ProviderTransport $transport;
	private LocationCache $cache;
	private OperationalClock $clock;
	private QuoteStore $store;
	private $providers;
	private $ttl;
	public function __construct( ?ProviderTransport $transport = null, ?LocationCache $cache = null, ?OperationalClock $clock = null, ?QuoteStore $store = null, ?callable $providers = null, ?callable $ttl = null ) {
		$this->clock = $clock ?? new OperationalClock(); $this->transport = $transport ?? new WordPressLocationTransport(); $this->cache = $cache ?? new WordPressLocationCache( $this->clock ); $this->store = $store ?? new WordPressQuoteStore();
		$this->providers = $providers ?? array( ProviderStorage::class, 'list_rows' ); $this->ttl = $ttl ?? static fn() => CacheSettings::get()['locations'];
	}
	public function credentials( $provider ): LocationCredentials {
		$id = V::get( $provider, 'shop_id' );
		if ( is_bool( $id ) || ( is_float( $id ) && ( ! is_finite( $id ) || floor( $id ) !== $id ) ) ) { throw new LocationError( 'برای دریافت لیست شهر و استان، شناسه شعبه معتبر نیست.' ); }
		$id = is_int( $id ) || is_float( $id ) ? V::string( V::integer( $id ) ) : V::text( V::fallback( $id, '' ) );
		if ( '' !== $id ) {
			try { $own = $this->store->shop( $id ); } catch ( \Throwable $error ) { throw new LocationError( 'خواندن شعبه این شرکت برای لیست شهر و استان ناموفق بود.' ); }
			$token = self::token( V::get( $own, 'token' ) ); if ( '' !== $token ) { return new LocationCredentials( $id, $token ); }
		}
		try { $shops = $this->store->shops(); } catch ( \Throwable $error ) { throw new LocationError( 'خواندن شعب برای لیست شهر و استان ناموفق بود.' ); }
		foreach ( $shops as $shop ) {
			if ( ! V::mapping( $shop ) || ! V::truth( V::get( $shop, 'id' ) ) || ! V::truth( V::get( $shop, 'has_token' ) ) ) { continue; }
			$shop_id = V::get( $shop, 'id' ); try { $candidate = $this->store->shop( V::string( $shop_id ) ); } catch ( \Throwable $ignored ) { continue; }
			$token = self::token( V::get( $candidate, 'token' ) ); if ( '' !== $token ) { return new LocationCredentials( V::trim( V::string( $shop_id ) ), $token ); }
		}
		if ( '' !== $id ) { throw new LocationError( "شعبه {$id} توکن معتبری ندارد و شعبه دیگری هم برای لیست شهر و استان پیدا نشد." ); }
		throw new LocationError( 'برای دریافت لیست شهر و استان، شناسه شعبه این شرکت یا حداقل یک شعبه دارای توکن لازم است.' );
	}
	private static function token( $value ): string { return null === $value || is_bool( $value ) ? '' : V::trim( V::string( $value ) ); }
	public function ttl( $value = null ): int {
		if ( null === $value ) { try { $value = call_user_func( $this->ttl ); } catch ( \Throwable $ignored ) { $value = self::TTL; } }
		try { return max( 0, V::integer( $value ) ); } catch ( \InvalidArgumentException $error ) { return self::TTL; }
	}
	private static function project( array $aliases, $targets, array $entry, int $ttl, string $status, string $message ): \stdClass {
		$payload = $entry['payload']; list( $data, $missing ) = LocationData::snapshot_data( $aliases, $targets, V::get( $payload, 'cities', array() ) ); $at = $entry['fetched_at'];
		return (object) array( 'data' => $data, 'missing' => $missing, 'status' => ! $missing || V::truth( $data ) ? $status : 'unavailable', 'message' => $message, 'fetched_at' => $at, 'expires_at' => null === $at ? null : $at + $ttl, 'stale_expires_at' => null, 'provider_status' => V::get( $payload, 'provider_status' ), 'provider_message' => V::string( V::fallback( V::get( $payload, 'provider_message' ), '' ) ) );
	}
	private static function usable( ?array $entry, float $now ): bool { return null !== $entry && V::truth( V::get( $entry['payload'], 'cities' ) ) && null !== $entry['fetched_at'] && $entry['fetched_at'] <= $now; }
	public function snapshot( array $names, ?LocationCredentials $credentials = null, bool $refresh = false, ?float $now = null, $ttl = null, ?array $records = null ): \stdClass {
		$aliases = LocationData::aliases( $names );
		if ( null === $records ) { try { $records = call_user_func( $this->providers ); } catch ( \Throwable $ignored ) { $records = array(); } }
		$targets = LocationData::targets( $aliases, $records ); $now = $now ?? (float) $this->clock->now()->format( 'U.u' ); $ttl = $this->ttl( $ttl );
		$owner = $this->cache->acquire();
		try {
			$entry = $this->cache->read();
			if ( ! $refresh && self::usable( $entry, $now ) && $now <= $entry['fetched_at'] + $ttl ) { return self::project( $aliases, $targets, $entry, $ttl, 'fresh', '' ); }
			if ( null === $owner ) { if ( self::usable( $entry, $now ) ) { return self::project( $aliases, $targets, $entry, $ttl, 'stale', 'به‌روزرسانی لیست شهر و استان در حال انجام است؛ آخرین داده معتبر نمایش داده شد.' ); } throw new LocationError( 'به‌روزرسانی لیست شهر و استان در حال انجام است.' ); }
			if ( null === $credentials ) { if ( self::usable( $entry, $now ) ) { return self::project( $aliases, $targets, $entry, $ttl, 'stale', 'اعتبار لیست شهر و استان برای به‌روزرسانی در دسترس نیست؛ آخرین داده معتبر نمایش داده شد.' ); } throw new LocationError( 'برای دریافت لیست شهر و استان، شناسه شعبه و توکن لازم است.' ); }
			$secrets = new QuoteSecrets(); $secrets->remember( $credentials->token() ); $request = new ProviderRequest( self::URL, (object) array( 'shop_id' => $credentials->shop_id ), array( 'Authorization' => $credentials->token() ), 15.0, $secrets );
			try { $reply = $this->transport->send( $request ); list( $cities, $provider_status, $provider_message ) = LocationData::decode( $reply->status, $reply->body, self::URL ); }
			catch ( LocationError $error ) { $message = $request->clean( $error->getMessage() ); if ( self::usable( $entry, $now ) ) { return self::project( $aliases, $targets, $entry, $ttl, 'stale', 'به‌روزرسانی لیست شهر و استان ناموفق بود؛ آخرین داده معتبر نمایش داده شد. خطا: ' . $message ); } throw new LocationError( $message ); }
			$payload = $request->clean_response( (object) array( 'cities' => $cities, 'provider_status' => $provider_status, 'provider_message' => $provider_message, 'source' => self::URL ) ); $fresh = array( 'fetched_at' => $now, 'payload' => $payload );
			$this->cache->save( $fresh );
			$snapshot = self::project( $aliases, $targets, $fresh, $ttl, 'fresh', '' ); if ( $snapshot->missing ) { $snapshot->message = 'برای ' . implode( ', ', $snapshot->missing ) . ' داده شهر و استان پیدا نشد.'; } return $snapshot;
		} finally { if ( null !== $owner ) { $this->cache->release( $owner ); } }
	}
	public function cache_info(): ?array { return $this->cache->read(); }
	public function clear(): void { $this->cache->clear(); }
}
