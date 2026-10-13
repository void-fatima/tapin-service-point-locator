<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source quote/preview API adaptation; calculations/cache/log policy stay in existing services. */
final class QuoteApiService {
	private $single;
	private $bulk;
	private $validate;
	private PublicApiService $schemas;
	private $providers;
	private QuoteStore $store;
	private ProviderTransport $transport;
	public function __construct( ?callable $single = null, ?callable $bulk = null, ?callable $validate = null, ?PublicApiService $schemas = null, ?callable $providers = null, ?QuoteStore $store = null, ?ProviderTransport $transport = null ) {
		$this->transport = $transport ?? new WordPressProviderTransport(); $this->store = $store ?? new WordPressQuoteStore(); $this->schemas = $schemas ?? new PublicApiService(); $this->providers = $providers ?? array( ProviderStorage::class, 'all' );
		$this->single = $single ?? array( new SingleQuoteService( $this->transport, $this->store ), 'execute' ); $service = new BulkQuoteService( null, $this->transport ); $this->bulk = $bulk ?? array( $service, 'execute' ); $this->validate = $validate ?? array( $service, 'validate' );
	}
	private function draft( \stdClass $body, bool $bulk = false ): \stdClass {
		try { return $bulk ? BulkConfig::normalise( V::get( $body, 'config' ), ( $this->providers )() ) : ProviderConfig::normalise( V::get( $body, 'record' ) ); }
		catch ( RecordError $error ) { throw new QuoteError( 422, $bulk ? 'پیش‌نویس گروهی معتبر نیست.' : 'پیش‌نویس شرکت معتبر نیست.', $error->errors ); }
	}
	public function call( string $operation, RestInput $input ): RestResult {
		if ( 'check_price' === $operation ) { return new RestResult( ( $this->single )( $input->raw, $input->query['provider'] ?? null ) ); }
		if ( 'bulk_check' === $operation || 'bulk_validate' === $operation ) { $callback = 'bulk_check' === $operation ? $this->bulk : $this->validate; return new RestResult( $callback( $input->raw, false ) ); }
		$body = $input->body();
		if ( 'preview_provider_schema' === $operation ) { return new RestResult( $this->schemas->schema( $this->draft( $body ) ) ); }
		if ( 'preview_bulk_schema' === $operation ) { return new RestResult( $this->schemas->bulk( $this->draft( $body, true ) ) ); }
		if ( 'preview_bulk_check' === $operation || 'preview_bulk_validate' === $operation ) { $callback = 'preview_bulk_check' === $operation ? $this->bulk : $this->validate; return new RestResult( $callback( $input->raw, true ) ); }
		if ( 'preview_price_check' === $operation ) {
			$provider = $this->draft( $body ); $values = V::get( $body, 'values', V::get( $body, 'payload', new \stdClass() ) ) ?? new \stdClass(); if ( ! V::mapping( $values ) ) { throw new QuoteError( 400, 'فیلد values باید یک شیء باشد.' ); }
			$execution = new QuoteExecution( $this->store, $this->transport ); try { $payload = $execution->normalise( $provider, $values ); } catch ( PayloadError $error ) { throw new QuoteError( 422, 'مقادیر ارسالی با تعریف فیلدها مطابقت ندارد.', $error->errors ); }
			try { [$quote] = $execution->fetch( $provider, $payload, true ); }
			catch ( ProviderCallError $error ) { throw new QuoteError( $error->status_code, V::truth( $error->extra['missing_price_fields'] ?? null ) ? 'سرویس در حال حاضر پاسخگو نیست.' : $error->getMessage(), V::truth( $error->extra['missing_price_fields'] ?? null ) ? array() : $error->errors ); }
			return new RestResult( $execution->result( $provider, $quote, false ) );
		} throw new \LogicException( 'Unknown quote operation.' );
	}
}
