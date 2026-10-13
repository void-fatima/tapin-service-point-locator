<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** One increment and its window start must be atomic; missing deletes are no-ops. */
interface RateLimitStore {
	public function increment( string $key, int $window_seconds, int $now_ms ): array;
	public function read( string $key, int $window_seconds, int $now_ms ): array;
	public function delete( string $key ): void;
	public function clear(): void;
}
