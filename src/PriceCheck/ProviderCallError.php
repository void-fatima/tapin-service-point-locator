<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** provider_calls.ProviderCallError; technical context stays inside the server. */
final class ProviderCallError extends \RuntimeException {
	public int $status_code;
	public ?string $field;
	public ?string $endpoint;
	public ?int $http_status;
	public $provider_status;
	public ?string $provider_message;
	public $provider_response;
	public array $errors;
	public array $extra;
	public function __construct( string $message, array $context = array() ) {
		parent::__construct( $message );
		$this->status_code = $context['status_code'] ?? 502; $this->field = $context['field'] ?? null;
		$this->endpoint = $context['endpoint'] ?? null; $this->http_status = $context['http_status'] ?? null;
		$this->provider_status = $context['provider_status'] ?? null; $this->provider_message = $context['provider_message'] ?? null;
		$this->provider_response = $context['provider_response'] ?? null; $this->extra = $context['extra'] ?? array();
		$this->errors = PythonValue::fallback( $context['errors'] ?? array(), null !== $this->field ? array( array( 'field' => $this->field, 'message' => $message ) ) : array() );
	}
}
