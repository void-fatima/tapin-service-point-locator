<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Private transport input. Never serialize this object into a result, cache or log. */
final class ProviderRequest {
	public string $url;
	public \stdClass $body;
	public float $timeout;
	private array $headers;
	private QuoteSecrets $secrets;
	public function __construct( string $url, \stdClass $body, array $headers, float $timeout, QuoteSecrets $secrets ) {
		$this->url = $url; $this->body = $body; $this->headers = $headers; $this->timeout = $timeout; $this->secrets = $secrets;
	}
	public function headers(): array { return $this->headers; }
	public function clean( $value ) { return $this->secrets->clean( $value ); }
}
