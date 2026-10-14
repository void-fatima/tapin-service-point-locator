<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Price-check HTTP boundary only. Services are injectable; locator routes are untouched. */
final class RestApi {
	const NAMESPACE = 'tapin-price-check/v1';
	private $services;
	private $guard;
	private $context;
	private $random;
	private array $callbacks = array();
	public function __construct( ?callable $services = null, ?callable $guard = null, ?callable $context = null, ?callable $random = null ) {
		$this->services = $services ?? static fn( $operation, $input ) => ( new ApiServices() )->call( $operation, $input );
		$this->guard = $guard ?? static fn( $input ) => ( new SecurityGuard() )->check( $input );
		$this->context = $context ?? static fn( $request ) => array( 'direct_ip' => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 'cookies' => wp_unslash( $_COOKIE ) );
		$this->random = $random;
	}
	public static function routes(): array { return array(
		array( '/health', 'GET', 'health', false ), array( '/providers', 'GET', 'list_providers', true ),
		array( '/providers/(?P<provider_id>[^/]+)', 'GET', 'provider_schema', true ), array( '/locations', 'GET', 'location_lookup', true ),
		array( '/bulk', 'GET', 'bulk_schema', true ),
		array( '/price-check', 'POST', 'check_price', true ), array( '/bulk-check', 'POST', 'bulk_check', true ), array( '/bulk-validate', 'POST', 'bulk_validate', true ),
		array( '/admin/preview/provider-schema', 'POST', 'preview_provider_schema', false ), array( '/admin/preview/price-check', 'POST', 'preview_price_check', false ),
		array( '/admin/preview/bulk-schema', 'POST', 'preview_bulk_schema', false ), array( '/admin/preview/bulk-check', 'POST', 'preview_bulk_check', false ), array( '/admin/preview/bulk-validate', 'POST', 'preview_bulk_validate', false ),
		array( '/admin/providers', 'GET', 'admin_list_providers', false ), array( '/admin/providers', 'POST', 'admin_create_provider', false ),
		array( '/admin/providers/locations/sample', 'GET', 'admin_locations_sample', false ), array( '/admin/providers/locations/import', 'POST', 'admin_locations_import', false ),
		array( '/admin/providers/(?P<provider_id>[^/]+)/unserved-locations', 'GET', 'admin_unserved_locations', false ),
		array( '/admin/providers/(?P<provider_id>[^/]+)', 'GET', 'admin_get_provider', false ), array( '/admin/providers/(?P<provider_id>[^/]+)', 'PUT', 'admin_update_provider', false ), array( '/admin/providers/(?P<provider_id>[^/]+)', 'DELETE', 'admin_delete_provider', false ),
		array( '/admin/shops', 'GET', 'admin_list_shops', false ), array( '/admin/shops', 'POST', 'admin_create_shop', false ),
		array( '/admin/shops/sample', 'GET', 'admin_shops_sample', false ), array( '/admin/shops/import', 'POST', 'admin_import_shops', false ),
		array( '/admin/shops/(?P<shop_id>[^/]+)', 'GET', 'admin_get_shop', false ), array( '/admin/shops/(?P<shop_id>[^/]+)', 'PUT', 'admin_update_shop', false ), array( '/admin/shops/(?P<shop_id>[^/]+)', 'DELETE', 'admin_delete_shop', false ),
		array( '/admin/bulk', 'GET', 'admin_get_bulk', false ), array( '/admin/bulk', 'PUT', 'admin_save_bulk', false ),
		array( '/admin/logs', 'GET', 'admin_list_logs', false ),
		array( '/admin/logs', 'DELETE', 'admin_clear_logs', false ),
		array( '/admin/logs/(?P<log_id>[0-9]+)', 'DELETE', 'admin_delete_log', false ),
		array( '/admin/security/blocks', 'GET', 'admin_list_blocks', false ),
		array( '/admin/security/blocks', 'POST', 'admin_create_block', false ),
		array( '/admin/security/blocks/(?P<block_id>[0-9]+)', 'PUT', 'admin_update_block', false ),
		array( '/admin/security/blocks/(?P<block_id>[0-9]+)', 'DELETE', 'admin_delete_block', false ),
		array( '/admin/security/events', 'GET', 'admin_list_security_events', false ),
		array( '/admin/security/rate-limits', 'DELETE', 'admin_reset_rate_limits', false ),
		array( '/admin/security/rate-limits', 'GET', 'admin_list_rate_limits', false ),
		array( '/admin/security/route-limits', 'GET', 'admin_list_route_limits', false ),
		array( '/admin/security/route-limits', 'PUT', 'admin_save_route_limit', false ),
		array( '/admin/security/route-limits', 'DELETE', 'admin_delete_route_limit', false ),
		array( '/admin/security/repeat-offenders', 'GET', 'admin_list_repeat_offenders', false ),
		array( '/admin/cache', 'GET', 'admin_get_cache_settings', false ),
		array( '/admin/cache', 'PUT', 'admin_save_cache_settings', false ),
		array( '/admin/cache/clear', 'DELETE', 'admin_clear_cache', false ),
		array( '/admin/security/allowlist', 'GET', 'admin_list_allowlist', false ),
		array( '/admin/security/allowlist', 'POST', 'admin_create_allowlist_entry', false ),
		array( '/admin/security/allowlist/(?P<entry_id>[0-9]+)', 'DELETE', 'admin_delete_allowlist_entry', false ),
	); }
	public static function permission( $request ) {
		$nonce = Access::can_manage() ? ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) ) : null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) { return new \WP_Error( 'tapin_price_forbidden', 'دسترسی به مدیریت استعلام قیمت مجاز نیست.', array( 'status' => 403 ) ); } return true;
	}
	public function register(): void {
		foreach ( self::routes() as [$path, $method, $operation, $guarded] ) {
			$admin = 0 === strpos( $path, '/admin/' );
			$callback = fn( $request ) => $this->handle( $request, $operation, $guarded, $admin ); $this->callbacks[] = $callback;
			register_rest_route( self::NAMESPACE, $path, array( 'methods' => $method, 'permission_callback' => $admin ? array( self::class, 'permission' ) : '__return_true', 'callback' => $callback ) );
		}
		add_filter( 'rest_request_before_callbacks', array( $this, 'body_validation' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'errors' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( RestBinaryResponse::class, 'serve' ), 10, 4 );
	}
	/** Let only our callbacks parse raw JSON; clearing validation still runs core permissions. */
	public function body_validation( $response, $handler, $request ) {
		if ( $response instanceof \WP_Error && array( 'rest_invalid_json' ) === $response->get_error_codes() && in_array( $handler['callback'] ?? null, $this->callbacks, true ) ) { return null; } return $response;
	}
	public static function owns( $request ): bool { $prefix = '/' . self::NAMESPACE . '/'; return 0 === strncasecmp( $request->get_route(), $prefix, strlen( $prefix ) ); }
	private static function source_route( string $operation, RestInput $input ): string {
		foreach ( self::routes() as [$path, $method, $name] ) { if ( $name === $operation ) { return '/api' . preg_replace_callback( '/\(\?P<(\w+)>[^)]+\)/', static fn( $match ) => (string) ( $input->path[$match[1]] ?? '' ), $path ); } } throw new \LogicException( 'Unknown guarded operation.' );
	}
	private function cookie( \WP_REST_Response $response, SecurityVerdict $verdict ): void {
		$cookie = $verdict->cookie( $this->random ); if ( null !== $cookie ) { $response->header( 'Set-Cookie', $cookie['name'] . '=' . $cookie['value'] . '; Max-Age=' . $cookie['max_age'] . '; Path=/; HttpOnly; SameSite=lax' ); }
	}
	public function handle( $request, string $operation, bool $guarded, bool $admin ): \WP_REST_Response {
		$admin = $admin || 0 === strpos( $operation, 'admin_' ) || 0 === strpos( $operation, 'preview_' );
		if ( $admin && true !== self::permission( $request ) ) { return new \WP_REST_Response( array( 'detail' => 'دسترسی به مدیریت استعلام قیمت مجاز نیست.', 'errors' => array() ), 403 ); }
		$verdict = null; $context = null;
		try {
			$input = new RestInput( $request->get_body(), $request->get_url_params(), $request->get_query_params(), $request->get_file_params() );
			if ( $guarded ) {
				$body = 'POST' === $request->get_method() ? $input->body() : null;
				$context = ( $this->context )( $request ); $headers = array(); foreach ( $request->get_headers() as $key => $value ) { $headers[str_replace( '_', '-', strtolower( $key ) )] = is_array( $value ) ? ( $value[0] ?? '' ) : $value; }
				$context['headers'] = $headers; $context['route'] = self::source_route( $operation, $input ); $context['method'] = $request->get_method();
				$verdict = ( $this->guard )( SecurityRequest::from_context( $context, $body, null === $body ? 0 : strlen( $input->raw ) ) );
				if ( ! $verdict->allowed ) { $deny = $verdict->deny_response(); $response = new \WP_REST_Response( $deny['body'], $deny['status'], (array) $deny['headers'] ); $this->cookie( $response, $verdict ); return $response; }
			}
			$result = ( $this->services )( $operation, $input );
			$headers = $result->headers; if ( $admin ) { $headers['Cache-Control'] = 'private, no-store, max-age=0'; }
			$response = null !== $result->binary ? new RestBinaryResponse( $result->binary, $result->status, $headers ) : new \WP_REST_Response( $result->body, $result->status, $headers );
			if ( null !== $verdict ) { $this->cookie( $response, $verdict ); } return $response;
		} catch ( QuoteError $error ) {
			$body = $error->jsonSerialize(); try { LogSafety::assert_safe( $body ); } catch ( \Throwable $unsafe ) { $body = array( 'detail' => 'internal error', 'errors' => array() ); }
			$response = new \WP_REST_Response( $body, $error->status_code );
			if ( ! $admin ) { $context = $context ?? ( $this->context )( $request ); if ( ! PythonValue::truth( $context['cookies']['tapin_cid'] ?? null ) ) { $this->cookie( $response, new SecurityVerdict( array( 'issue_client_id' => true ) ) ); } } return $response;
		} catch ( \Throwable $error ) { return new \WP_REST_Response( array( 'detail' => 'internal error' ), 500 ); }
	}
	/** Adapt core-generated errors only inside this namespace; no global response changes. */
	public function errors( $response, $server, $request ) {
		if ( ! self::owns( $request ) || ! $response instanceof \WP_REST_Response ) { return $response; }
		$admin_prefix = '/' . self::NAMESPACE . '/admin/'; if ( 0 === strncasecmp( $request->get_route(), $admin_prefix, strlen( $admin_prefix ) ) ) { $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); if ( true !== self::permission( $request ) ) { $response->set_status( 403 ); $response->set_data( array( 'detail' => 'دسترسی به مدیریت استعلام قیمت مجاز نیست.', 'errors' => array() ) ); return $response; } }
		$data = $response->get_data(); if ( ! is_array( $data ) || ! isset( $data['code'], $data['message'] ) || ! array_key_exists( 'data', $data ) ) { return $response; }
		if ( 'rest_no_route' === $data['code'] ) { foreach ( self::routes() as [$path, $method] ) { if ( preg_match( '~^/' . self::NAMESPACE . $path . '$~iD', $request->get_route() ) ) { $response->set_status( 405 ); return new RestBinaryResponse( 'Method Not Allowed', 405, array( 'Content-Type' => 'text/plain; charset=utf-8' ), false ); } } $response->set_data( array( 'detail' => 'not found' ) ); return $response; }
		$detail = 'rest_invalid_json' === $data['code'] ? 'بدنه درخواست JSON معتبر نیست.' : ( 403 === $response->get_status() || 401 === $response->get_status() ? 'دسترسی به مدیریت استعلام قیمت مجاز نیست.' : 'درخواست معتبر نیست.' );
		$response->set_data( array( 'detail' => $detail, 'errors' => array() ) ); return $response;
	}
}
