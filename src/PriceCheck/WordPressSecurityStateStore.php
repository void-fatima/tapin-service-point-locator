<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Compose Stage 5 checked stores. The optional Python client registry is not consulted. */
final class WordPressSecurityStateStore implements SecurityStateStore {
	public function contains( string $ip ): bool { return AllowlistStorage::contains( $ip ); }
	public function find_active( string $type, string $value, int $now_ms ): ?BlockedClient { return BlockedClientStorage::find_active( $type, $value, $now_ms ); }
	public function record( SecurityEvent $event ): void { SecurityEventStorage::record( $event ); }
	public function add_block( BlockedClient $entry ): BlockedClient { return BlockedClientStorage::add( $entry ); }
	public function set_active( int $id, bool $active ): bool { return BlockedClientStorage::set_active( $id, $active ); }
	public function update_block( int $id, string $reason, ?int $expiry ): bool { return BlockedClientStorage::update( $id, $reason, $expiry ); }
	public function delete_block( int $id ): bool { return BlockedClientStorage::delete( $id ); }
	public function blocks( string $query, string $type, string $active, int $now_ms, int $limit ): array { return BlockedClientStorage::list_rows( $query, $type, $active, $now_ms, $limit ); }
	public function add_allow( string $ip, string $reason, string $actor, int $now_ms ): int { return AllowlistStorage::add( $ip, $reason, $actor, $now_ms ); }
	public function delete_allow( int $id ): bool { return AllowlistStorage::delete( $id ); }
	public function allowlist( string $query, int $limit ): array { return AllowlistStorage::list_rows( $query, $limit ); }
}
