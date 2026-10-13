<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** security/models.py::SecurityEvent; storage accepts already-sanitized diagnostics. */
final class SecurityEvent {
	public int $id = 0;
	public int $created_at_ms = 0;
	public string $kind = '';
	public string $client_fingerprint = '';
	public string $client_id = '';
	public string $ip = '';
	public string $route = '';
	public ?string $detail = '';
	public function __construct( array $values = array() ) { foreach ( $values as $key => $value ) { if ( property_exists( $this, $key ) ) { $this->$key = $value; } } }
}
