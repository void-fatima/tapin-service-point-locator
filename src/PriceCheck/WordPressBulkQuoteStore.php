<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Compose checked Stage 4/5 stores; no new SQL or schema. */
final class WordPressBulkQuoteStore implements BulkQuoteStore {
	private WordPressQuoteStore $quotes;
	public function __construct( ?OperationalClock $clock = null ) { $this->quotes = new WordPressQuoteStore( $clock ); }
	public function providers(): array { return ProviderStorage::all(); }
	public function config(): \stdClass { return BulkStorage::get(); }
	public function provider( string $identifier ): ?\stdClass { return $this->quotes->provider( $identifier ); }
	public function shops(): array { return $this->quotes->shops(); }
	public function shop( string $identifier ): ?array { return $this->quotes->shop( $identifier ); }
	public function cache_get( int $provider_id, string $key ): ?array { return $this->quotes->cache_get( $provider_id, $key ); }
	public function cache_fresh( $created_at ): bool { return $this->quotes->cache_fresh( $created_at ); }
	public function cache_save( \stdClass $record ): void { $this->quotes->cache_save( $record ); }
	public function log_save( \stdClass $record ): void { $this->quotes->log_save( $record ); }
}
