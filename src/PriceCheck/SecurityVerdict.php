<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Pipeline data only. Responses/cookies are descriptors for a future authorized HTTP adapter. */
final class SecurityVerdict {
	public bool $allowed = true;
	public int $http_status = 200;
	public string $code = 'ok';
	public string $message_en = 'OK';
	public string $message_fa = 'درخواست معتبر است.';
	public int $retry_after = 0;
	public \stdClass $identity;
	public string $request_fingerprint = '';
	public string $request_hash = '';
	public bool $issue_client_id = false;
	public array $events = array();
	public function __construct( array $values = array() ) {
		$this->identity = (object) array( 'ip' => '', 'client_id' => '', 'browser_fingerprint' => '', 'client_fingerprint' => '', 'user_agent' => '' );
		foreach ( $values as $key => $value ) { if ( property_exists( $this, $key ) ) { $this->$key = $value; } }
	}
	public function deny_response(): array {
		if ( $this->allowed ) { throw new \LogicException( 'An allowed verdict has no deny response.' ); }
		return array( 'status' => $this->http_status, 'headers' => $this->retry_after ? (object) array( 'Retry-After' => (string) $this->retry_after ) : new \stdClass(), 'body' => (object) array( 'success' => false, 'code' => $this->code, 'message' => $this->message_en, 'detail' => $this->message_fa, 'retry_after' => $this->retry_after ) );
	}
	public function cookie( ?callable $random_hex = null ): ?array {
		if ( ! $this->issue_client_id ) { return null; }
		$id = null === $random_hex ? bin2hex( random_bytes( 16 ) ) : $random_hex( 16 );
		if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{32}$/D', $id ) ) { throw new \RuntimeException( 'Client ID generator returned an invalid ID.' ); }
		return array( 'name' => 'tapin_cid', 'value' => $id, 'max_age' => 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'lax', 'secure' => false );
	}
}
