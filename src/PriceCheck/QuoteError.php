<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Source public ApiError envelope, separate from server-only carrier diagnostics. */
final class QuoteError extends \RuntimeException implements \JsonSerializable {
	public int $status_code;
	public string $detail;
	public array $errors;
	private bool $include_errors;
	public function __construct( int $status_code, string $detail, ?array $errors = array() ) {
		parent::__construct( $detail ); $this->status_code = $status_code; $this->detail = $detail; $this->errors = $errors ?? array(); $this->include_errors = null !== $errors;
	}
	public function jsonSerialize(): array { return $this->include_errors ? array( 'detail' => $this->detail, 'errors' => $this->errors ) : array( 'detail' => $this->detail ); }
}
