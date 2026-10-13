<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Server-only boundary for configuration and operational state used by a single quote. */
interface QuoteStore {
	public function provider( string $identifier ): ?\stdClass;
	public function shops(): array;
	public function shop( string $identifier ): ?array;
	public function cache_get( int $provider_id, string $key ): ?array;
	public function cache_fresh( $created_at ): bool;
	public function cache_save( \stdClass $record ): void;
	public function log_save( \stdClass $record ): void;
}
