<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** api.py::check_price without routing, request guard, cookies or frontend integration. */
final class SingleQuoteService {
	private QuoteStore $store;
	private ProviderTransport $transport;
	public function __construct( ?ProviderTransport $transport = null, ?QuoteStore $store = null ) { $this->transport = $transport ?? new WordPressProviderTransport(); $this->store = $store ?? new WordPressQuoteStore(); }
	public function execute( string $raw, $query_provider = null ): array {
		try {
			$result = $this->quote( QuoteExecution::body( $raw ), $query_provider );
			// Python JSONResponse rejects non-finite public values rather than sending success.
			if ( false === json_encode( $result ) ) { throw new \UnexpectedValueException( 'Unserializable public quote.' ); }
			return $result;
		} catch ( QuoteError $error ) { throw $error; }
		catch ( \DomainException $error ) { throw $error; }
		catch ( \Throwable $error ) { throw new QuoteError( 500, 'internal error', null ); }
	}
	private function quote( \stdClass $body, $query_provider ): array {
		$identifier = V::fallback( V::get( $body, 'provider' ), $query_provider );
		if ( ! V::truth( $identifier ) ) { throw new QuoteError( 400, 'فیلد provider الزامی است.' ); }
		$provider = $this->store->provider( V::text( $identifier ) );
		if ( null === $provider ) { throw new QuoteError( 404, 'شرکت مورد نظر یافت نشد.' ); }
		if ( ! V::truth( V::get( $provider, 'active', true ) ) ) { throw new QuoteError( 404, 'این شرکت غیرفعال است.' ); }
		$incoming = V::get( $body, 'values', V::get( $body, 'payload', new \stdClass() ) );
		if ( null === $incoming ) { $incoming = new \stdClass(); }
		if ( ! V::mapping( $incoming ) ) { throw new QuoteError( 400, 'فیلد values باید یک شیء باشد.' ); }
		$execution = new QuoteExecution( $this->store, $this->transport );
		try { $payload = $execution->normalise( $provider, $incoming ); }
		catch ( PayloadError $error ) { throw new QuoteError( 422, 'مقادیر ارسالی با تعریف فیلدها مطابقت ندارد.', $error->errors ); }
		try { list( $quote, $cached ) = $execution->fetch( $provider, $payload ); }
		catch ( ProviderCallError $error ) {
			$execution->log_failure( $provider, $payload, $error );
			if ( V::truth( $error->extra['missing_price_fields'] ?? null ) ) { throw new QuoteError( $error->status_code, 'سرویس در حال حاضر پاسخگو نیست.' ); }
			throw new QuoteError( $error->status_code, $error->getMessage(), $error->errors );
		}
		return $execution->result( $provider, $quote, $cached );
	}
	public static function timeout( $provider ): float { return QuoteExecution::timeout( $provider ); }
	public static function log_params( NormalizedPayload $payload ): \stdClass { return QuoteExecution::log_params( $payload ); }
}
