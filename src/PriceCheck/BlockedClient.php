<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** security/models.py::BlockedClient; no identity validation or blocking policy. */
final class BlockedClient {
	public int $id = 0;
	public string $type = '';
	public string $value = '';
	public string $reason = '';
	public int $created_at_ms = 0;
	public ?int $expires_at_ms = null;
	public string $created_by = '';
	public bool $is_active = true;
	public function __construct( array $values = array() ) { foreach ( $values as $key => $value ) { if ( property_exists( $this, $key ) ) { $this->$key = $value; } } }
}
