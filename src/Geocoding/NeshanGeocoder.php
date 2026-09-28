<?php
namespace Tapin\ServicePointLocator\Geocoding;

defined( 'ABSPATH' ) || exit;

/** https://platform.neshan.org/docs/api/search-category/geocoding/ */
final class NeshanGeocoder implements GeocoderInterface {
	private string $key;
	private bool $plus;

	public function __construct( string $key, bool $plus = false ) {
		$this->key = trim( $key );
		$this->plus = $plus;
	}
	public function name(): string { return $this->plus ? 'neshan-v1-plus' : 'neshan-v1'; }
	public function configured(): bool { return '' !== $this->key; }

	public function geocode( array $query ) {
		if ( ! $this->configured() ) { return self::error( 'not_configured' ); }
		$url = 'https://api.neshan.org/geocoding/v1' . ( $this->plus ? '/plus' : '' );
		$response = wp_remote_get( $url . '?json=' . rawurlencode( wp_json_encode( $query ) ), array(
			'headers' => array( 'Api-Key' => $this->key, 'Accept' => 'application/json' ),
			'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 65536,
		) );
		// Never persist raw HTTP messages, request headers, or provider payloads.
		if ( is_wp_error( $response ) ) { return self::error( 'transport_error', true ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$retryable = in_array( $code, array( 408, 425, 429, 482 ), true ) || $code >= 500;
			$header = wp_remote_retrieve_header( $response, 'retry-after' );
			$delay = is_numeric( $header ) ? (int) $header : max( 0, (int) strtotime( (string) $header ) - time() );
			return self::error( in_array( $code, array( 429, 482 ), true ) ? 'rate_limited' : 'http_' . $code, $retryable, $delay );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['items'] ) || ! is_array( $body['items'] ) || count( $body['items'] ) > 5 ) { return self::error( 'invalid_response' ); }
		$items = array();
		foreach ( $body['items'] as $item ) {
			if ( ! is_array( $item ) || ! is_array( $item['location'] ?? null ) ) { return self::error( 'invalid_response' ); }
			foreach ( array( 'province', 'city', 'unMatchedTerm' ) as $field ) {
				if ( isset( $item[$field] ) && ! is_string( $item[$field] ) ) { return self::error( 'invalid_response' ); }
			}
			$items[] = array(
				'latitude' => $item['location']['latitude'] ?? null,
				'longitude' => $item['location']['longitude'] ?? null,
				'province' => $item['province'] ?? '', 'city' => $item['city'] ?? '',
				// Missing quality information is not evidence of an exact match.
				'quality' => isset( $item['unMatchedTerm'] ) && '' === trim( $item['unMatchedTerm'] ) ? 'matched' : 'partial',
			);
		}
		return $items;
	}

	private static function error( string $code, bool $retryable = false, int $delay = 0 ): \WP_Error {
		return new \WP_Error( $code, 'Location provider could not resolve this address.', array( 'retryable' => $retryable, 'retry_after' => max( 0, $delay ) ) );
	}
}
