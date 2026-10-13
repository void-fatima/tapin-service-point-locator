<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;
final class ProviderHttpResponse {
	public int $status;
	public string $body;
	public function __construct( int $status, string $body ) { $this->status = $status; $this->body = $body; }
}
