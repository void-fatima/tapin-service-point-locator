<?php
/** Small REST boundary double; core dispatch, cookie authentication and HTTP serving need WP integration. */
class WP_REST_Response {
	private $data; private int $status; private array $headers;
	public function __construct( $data = null, int $status = 200, array $headers = array() ) { $this->data = $data; $this->status = $status; $this->headers = $headers; }
	public function get_data() { return $this->data; } public function set_data( $data ): void { $this->data = $data; }
	public function get_status(): int { return $this->status; } public function set_status( int $status ): void { $this->status = $status; }
	public function header( $key, $value ): void { $this->headers[$key] = $value; } public function get_headers(): array { return $this->headers; }
}
class WP_Error { public string $code; public string $message; public array $data; public function __construct( $code, $message, $data ) { $this->code=$code; $this->message=$message; $this->data=$data; } public function get_error_codes():array{return array($this->code);} }
final class PriceRestRequest {
	public string $method='GET'; public string $route=''; public string $body=''; public array $path=array(); public array $query=array(); public array $headers=array(); public array $files=array();
	public function get_method() { return $this->method; } public function get_route() { return $this->route; } public function get_body() { return $this->body; }
	public function get_url_params() { return $this->path; } public function get_query_params() { return $this->query; } public function get_file_params() { return $this->files; } public function get_headers() { return $this->headers; }
	public function get_header( $name ) { $key=str_replace('-', '_', strtolower($name)); $value=$this->headers[$key]??''; return is_array($value)?implode(',',$value):$value; }
	public function get_param( $key ) { return $this->query[$key]??null; }
}
$rest_routes=array(); $rest_filters=array(); $rest_admin=false; $rest_logged=false;
function register_rest_route( $namespace, $path, $definition ): void { $GLOBALS['rest_routes'][]=array($namespace,$path,$definition); }
function add_filter( $hook, $callback, $priority=10, $count=1 ): void { $GLOBALS['rest_filters'][$hook][]=$callback; }
function add_action( $hook, $callback, $priority=10 ): void { $GLOBALS['rest_filters'][$hook][]=$callback; }
function is_user_logged_in(): bool { return $GLOBALS['rest_logged']; }
function current_user_can( $cap ): bool { if ('manage_options'!==$cap) { throw new LogicException('Unexpected capability.'); } return $GLOBALS['rest_admin']; }
function wp_verify_nonce( $nonce, $action ): bool { return 'wp_rest'===$action && 'valid-rest-nonce'===$nonce; }
function __return_true(): bool { return true; }
function wp_unslash( $value ) { return $value; }
