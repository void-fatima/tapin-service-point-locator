<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Explicit urllib-compatible request mechanics over the WordPress HTTP API. */
final class WordPressProviderTransport implements ProviderTransport {
	const MAX_BYTES = 2000000;
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		$url = $request->url; $method = 'POST'; $body = ConfigurationJson::encode( $request->body );
		$headers = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'tapin-check-price-web/1.0', 'Accept-Encoding' => 'identity' ) + $request->headers(); $visited = array();
		while ( true ) {
			$response = wp_remote_request( $url, array( 'method' => $method, 'body' => $body, 'headers' => $headers, 'user-agent' => 'tapin-check-price-web/1.0', 'timeout' => $request->timeout, 'redirection' => 0, 'httpversion' => '1.1', 'blocking' => true, 'sslverify' => true, 'compress' => false, 'decompress' => false, 'reject_unsafe_urls' => false, 'limit_response_size' => self::MAX_BYTES + 1, 'cookies' => array() ) );
			if ( is_wp_error( $response ) ) {
				// WP does not provide a portable timeout subtype; never echo its error message.
				$timeout = 'http_request_timeout' === $response->get_error_code() || preg_match( '/(?:cURL error 28\b|\btimed?\s+out\b)/i', $response->get_error_message() );
				throw new ProviderCallError( $timeout ? 'سرویس قیمت در زمان مقرر پاسخ نداد.' : 'ارتباط با سرویس قیمت برقرار نشد.', array( 'status_code' => $timeout ? 504 : 502, 'endpoint' => $request->url ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response ); $raw = substr( wp_remote_retrieve_body( $response ), 0, self::MAX_BYTES + 1 );
			$response_headers = wp_remote_retrieve_headers( $response ); $has_location = isset( $response_headers['location'] ) || isset( $response_headers['uri'] );
			$location = wp_remote_retrieve_header( $response, isset( $response_headers['location'] ) ? 'location' : 'uri' );
			if ( is_array( $location ) ) { $location = $location[0] ?? ''; }
			$redirect = in_array( $status, array( 301, 302, 303 ), true ) || ( 'GET' === $method && in_array( $status, array( 307, 308 ), true ) );
			if ( $redirect && $has_location ) {
				$next = ProviderRedirect::resolve( (string) $location, $url );
				// WordPress supports HTTP(S) only. Return FTP/other redirects as HTTP error bodies.
				if ( PythonValue::http_url( $next ) && ( $visited[$next] ?? 0 ) < 4 && count( $visited ) < 10 ) {
					$visited[$next] = ( $visited[$next] ?? 0 ) + 1;
					if ( self::origin( $url ) !== self::origin( $next ) ) { unset( $headers['Authorization'] ); }
					unset( $headers['Content-Type'], $headers['Content-Length'] ); $url = $next; $method = 'GET'; $body = null; continue;
				}
			}
			// Python HTTPError returns early and bypasses the normal response-size failure.
			if ( $status >= 200 && $status < 300 && strlen( $raw ) > self::MAX_BYTES ) { throw new ProviderCallError( 'پاسخ سرویس قیمت بیش از حد بزرگ است.', array( 'endpoint' => $request->url, 'http_status' => $status ) ); }
			return new ProviderHttpResponse( $status, $raw );
		}
	}
	private static function origin( string $url ): array {
		$parts = parse_url( $url );
		return array( strtolower( $parts['scheme'] ?? '' ), strtolower( $parts['host'] ?? '' ), $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ?? '' ) ? 443 : 80 ) );
	}
}
