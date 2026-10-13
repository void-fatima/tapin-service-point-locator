<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** One global last-valid snapshot; no stale expiry, and a refresh lease separate from the data. */
interface LocationCache {
	public function read(): ?array;
	public function save( array $entry ): void;
	public function clear(): void;
	public function acquire(): ?string;
	public function release( string $owner ): void;
}
