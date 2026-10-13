<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** One provider call: request construction, injectable transport, extraction and conversion. */
final class ProviderClient {
	private ProviderTransport $transport;
	private ProviderRequestBuilder $builder;
	private ?ProviderRequest $request = null;
	public function __construct( ?ProviderTransport $transport = null, ?ProviderRequestBuilder $builder = null ) { $this->transport = $transport ?? new WordPressProviderTransport(); $this->builder = $builder ?? new ProviderRequestBuilder(); }
	public function clean( $value ) { return null === $this->request ? ( new QuoteSecrets() )->clean( $value ) : $this->request->clean( $value ); }
	public function fetch( $provider, \stdClass $payload, float $timeout = 15.0 ): \stdClass {
		$this->request = null; $this->request = $this->builder->build( $provider, $payload, $timeout );
		try {
			$response = $this->transport->send( $this->request );
			list( $body, $status, $message ) = ProviderResponse::parse( $response->status, $response->body, $this->request->url );
			list( $prices, $missing ) = ProviderResponse::evaluate( $body, PythonValue::fallback( PythonValue::get( $provider, 'price_fields' ), array() ), array( 'endpoint' => $this->request->url, 'http_status' => $response->status, 'provider_status' => $status, 'provider_message' => $message ) );
			list( $service, $display ) = Units::currency_pair( $provider );
			if ( $service !== $display ) { foreach ( $prices as &$row ) { $row['value'] = Units::currency_amount( $row['value'], $service, $display ); } unset( $row ); }
			return $this->clean( (object) array( 'endpoint' => $this->request->url, 'provider_status' => $status, 'provider_message' => $message, 'provider_response' => $this->request->clean_response( $body ), 'prices' => ProviderResponse::mark_main( $prices, $provider ), 'missing_price_fields' => $missing, 'price_candidates' => array() ) );
		} catch ( ProviderCallError $error ) {
			$context = (array) $this->clean( get_object_vars( $error ) ); $context['extra'] = (array) $context['extra'];
			$context['provider_response'] = $this->request->clean_response( $error->provider_response );
			if ( array_key_exists( 'provider_response', $context['extra'] ) ) { $context['extra']['provider_response'] = $this->request->clean_response( $error->extra['provider_response'] ); }
			throw new ProviderCallError( $this->clean( $error->getMessage() ), $context );
		}
	}
}
