<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Compose the existing stores; no new tables, SQL, state policy or secret editing API. */
final class WordPressQuoteStore implements QuoteStore {
	private PriceCacheStorage $cache;
	private QuoteLogStorage $logs;
	public function __construct( ?OperationalClock $clock = null ) { $this->cache = new PriceCacheStorage( $clock ); $this->logs = new QuoteLogStorage( $clock ); }
	public function provider( string $identifier ): ?\stdClass { return ProviderStorage::load( $identifier ); }
	public function shops(): array { return ShopStorage::list_rows(); }
	public function shop( string $identifier ): ?array { return ShopStorage::get_private( $identifier ); }
	public function cache_get( int $provider_id, string $key ): ?array { return $this->cache->get( $provider_id, $key ); }
	public function cache_fresh( $created_at ): bool { return $this->cache->is_fresh( $created_at ); }
	public function cache_save( \stdClass $record ): void { $this->cache->save( $record ); }
	public function log_save( \stdClass $record ): void { $this->logs->save( $record ); }
}
