<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Keep URL/query parameters separate from JSON, preserving Python path and query precedence. */
final class RestInput {
	public string $raw;
	public array $path;
	public array $query;
	public array $files;
	public function __construct( string $raw = '', array $path = array(), array $query = array(), array $files = array() ) { $this->raw = $raw; $this->path = $path; $this->query = $query; $this->files = $files; }
	public function body(): \stdClass { return QuoteExecution::body( $this->raw ); }
	public function text( string $key, string $default = '' ): string {
		$value = $this->query[$key] ?? $default;
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) { throw new QuoteError( 400, 'پارامتر درخواست معتبر نیست.' ); }
		return V::trim( (string) $value );
	}
	public function number( string $key, int $default ): int { try { return V::integer( $this->query[$key] ?? (string) $default ); } catch ( \Throwable $error ) { return $default; } }
}
