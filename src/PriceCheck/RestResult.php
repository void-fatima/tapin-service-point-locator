<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Framework-neutral API result; never exposes a temporary file or internal exception. */
final class RestResult {
	public $body;
	public int $status;
	public array $headers;
	public ?string $binary;
	public function __construct( $body, int $status = 200, array $headers = array(), ?string $binary = null ) { $this->body = $body; $this->status = $status; $this->headers = $headers; $this->binary = $binary; }
}
