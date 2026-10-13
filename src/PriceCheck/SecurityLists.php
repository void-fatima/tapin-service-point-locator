<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** security/allowlist.py and blocklist.py; explicit list mutations, never automatic blocking. */
final class SecurityLists {
	private SecurityStateStore $store;
	private OperationalClock $clock;
	public function __construct( SecurityStateStore $store, ?OperationalClock $clock = null ) { $this->store = $store; $this->clock = $clock ?? new OperationalClock(); }
	public function is_allowed( string $ip ): bool {
		$ip = SecurityIpResolver::normalize( $ip ); if ( '' === $ip ) { return false; }
		try { return $this->store->contains( $ip ); } catch ( \Throwable $error ) { return false; }
	}
	public function check_blocks( string $ip, string $id, string $fp ): array {
		$now = SecurityTime::milliseconds( $this->clock ); foreach ( array( 'ip' => $ip, 'client_id' => $id, 'client_fingerprint' => $fp ) as $type => $value ) { if ( '' !== $value ) { $entry = $this->store->find_active( $type, $value, $now ); if ( null !== $entry ) { return array( true, $type, $entry ); } } } return array( false, '', null );
	}
	public function block( string $type, $value, $reason, $actor, ?int $expiry = null ): BlockedClient {
		if ( ! in_array( $type, array( 'ip', 'client_id', 'client_fingerprint' ), true ) ) { throw new \InvalidArgumentException( 'unsupported block type: ' . $type ); }
		$value = V::text( V::fallback( $value, '' ) ); if ( '' === $value ) { throw new \InvalidArgumentException( 'block value must not be empty' ); }
		$now = SecurityTime::milliseconds( $this->clock ); if ( null !== $expiry && $expiry <= $now ) { throw new \InvalidArgumentException( 'expiry must be in the future' ); }
		return $this->store->add_block( new BlockedClient( array( 'type' => $type, 'value' => $value, 'reason' => V::text( V::fallback( $reason, '' ) ), 'created_by' => V::text( V::fallback( $actor, '' ) ), 'created_at_ms' => $now, 'expires_at_ms' => $expiry ) ) );
	}
	public function unblock( int $id ): bool { return $this->store->set_active( $id, false ); }
	public function reactivate( int $id ): bool { return $this->store->set_active( $id, true ); }
	public function update( int $id, $reason, ?int $expiry ): bool { return $this->store->update_block( $id, V::string( V::fallback( $reason, '' ) ), $expiry ); }
	public function delete( int $id ): bool { return $this->store->delete_block( $id ); }
	public function blocks( string $query = '', string $type = '', string $active = '', int $limit = 200 ): array { return $this->store->blocks( $query, $type, $active, SecurityTime::milliseconds( $this->clock ), $limit ); }
	public function allow( $ip, $reason = '', $actor = '' ): array {
		$cleaned = SecurityIpResolver::normalize( $ip ); if ( '' === $cleaned ) { throw new \InvalidArgumentException( 'آدرس آی‌پی معتبر نیست.' ); }
		return array( 'id' => $this->store->add_allow( $cleaned, V::text( V::fallback( $reason, '' ) ), V::text( V::fallback( $actor, '' ) ), SecurityTime::milliseconds( $this->clock ) ), 'ip' => $cleaned );
	}
	public function remove_allow( int $id ): bool { return $this->store->delete_allow( $id ); }
	public function allowlist( $query = '', int $limit = 200 ): array { return $this->store->allowlist( V::string( V::fallback( $query, '' ) ), $limit ); }
}
