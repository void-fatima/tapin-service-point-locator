<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Guard reads/events and the source's explicit list operations. No authentication or counters. */
interface SecurityStateStore {
	public function contains( string $ip ): bool;
	public function find_active( string $type, string $value, int $now_ms ): ?BlockedClient;
	public function record( SecurityEvent $event ): void;
	public function add_block( BlockedClient $entry ): BlockedClient;
	public function set_active( int $id, bool $active ): bool;
	public function update_block( int $id, string $reason, ?int $expiry ): bool;
	public function delete_block( int $id ): bool;
	public function blocks( string $query, string $type, string $active, int $now_ms, int $limit ): array;
	public function add_allow( string $ip, string $reason, string $actor, int $now_ms ): int;
	public function delete_allow( int $id ): bool;
	public function allowlist( string $query, int $limit ): array;
}
