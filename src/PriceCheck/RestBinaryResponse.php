<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Private binary response; default JSON serialization cannot expose its bytes. */
final class RestBinaryResponse extends \WP_REST_Response {
	private string $bytes;
	private bool $admin;
	public function __construct( string $bytes, int $status, array $headers, bool $admin = true ) { parent::__construct( null, $status, $headers ); $this->bytes = $bytes; $this->admin = $admin; }
	public static function serve( $served, $response, $request, $server ): bool { if ( ! $response instanceof self || 0 !== strpos( $request->get_route(), '/' . RestApi::NAMESPACE . '/' ) ) { return (bool) $served; } if ( ! $served && ( ! $response->admin || true === RestApi::permission( $request ) ) ) { echo $response->bytes; } return true; }
}
