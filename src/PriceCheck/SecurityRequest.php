<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Already-extracted HTTP context. No globals, WP request reads or route/handler attachment. */
final class SecurityRequest {
	public string $method = 'GET';
	public string $route = '';
	public string $direct_ip = '';
	public array $headers = array();
	public array $cookies = array();
	public $client_body = null;
	public $business = null;
	public int $body_size = 0;
	public int $now_ms = 0;
	public function __construct( array $values = array() ) { foreach ( $values as $key => $value ) { if ( property_exists( $this, $key ) ) { $this->$key = $value; } } }
	/** infra_starlette.py::extract_input semantics, over an injected context and parsed JSON. */
	public static function from_context( array $context, $body = null, int $raw_size = 0 ): self {
		$headers = array(); foreach ( $context['headers'] ?? array() as $name => $value ) { $headers[strtolower( $name )] = $value; }
		$client = V::mapping( $body ) ? V::get( $body, 'client' ) : null;
		return new self( array( 'method' => $context['method'] ?? 'GET', 'route' => $context['route'] ?? '', 'direct_ip' => $context['direct_ip'] ?? '', 'headers' => $headers, 'cookies' => $context['cookies'] ?? array(), 'client_body' => null === $client ? new \stdClass() : $client, 'business' => V::mapping( $body ) ? V::get( $body, 'values', $body ) : null, 'body_size' => $raw_size, 'now_ms' => $context['now_ms'] ?? 0 ) );
	}
}
