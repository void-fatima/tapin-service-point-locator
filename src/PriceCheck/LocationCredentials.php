<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Private request-local credentials, never part of a location snapshot or persisted cache. */
final class LocationCredentials {
	public string $shop_id;
	private string $token;
	public function __construct( string $shop_id, string $token ) { $this->shop_id = $shop_id; $this->token = $token; }
	public function token(): string { return $this->token; }
	public function __debugInfo(): array { return array( 'shop_id' => $this->shop_id ); }
}
