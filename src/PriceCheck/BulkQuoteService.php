<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Server-only bulk execution/preflight. No authorization, routes or live location fetches. */
final class BulkQuoteService {
	private BulkQuoteStore $store;
	private ProviderTransport $transport;
	/** Nullable location snapshots; caller maps source LocationError to null, other failures propagate. */
	private $section;
	public function __construct( callable $section, ?ProviderTransport $transport = null, ?BulkQuoteStore $store = null ) { $this->section = $section; $this->transport = $transport ?? new WordPressProviderTransport(); $this->store = $store ?? new WordPressBulkQuoteStore(); }
	/** Stored requests accept values only; preview additionally accepts payload and normalizes a draft. */
	public function execute( string $raw, bool $preview = false ): array { return $this->request( $raw, $preview, false ); }
	public function validate( string $raw, bool $preview = false ): array { return $this->request( $raw, $preview, true ); }
	private function request( string $raw, bool $preview, bool $validate ): array {
		try {
			$body = QuoteExecution::body( $raw );
			if ( $preview ) {
				try { $config = BulkConfig::normalise( V::get( $body, 'config' ), $this->store->providers() ); }
				catch ( RecordError $error ) { throw new QuoteError( 422, 'پیش‌نویس گروهی معتبر نیست.', $error->errors ); }
			}
			$values = V::get( $body, 'values', $preview ? V::get( $body, 'payload', new \stdClass() ) : null );
			if ( null === $values ) { $values = new \stdClass(); }
			if ( ! V::mapping( $values ) ) { throw new QuoteError( 400, 'فیلد values باید یک شیء باشد.' ); }
			if ( ! $preview ) { $config = $this->store->config(); }
			// Preview checks shared required inputs before the engine's active-provider check.
			if ( $preview && ! $validate ) { self::required( $config, $values ); }
			$providers = $this->store->providers(); $active = BulkConfig::active_providers( $config, $providers );
			if ( ! $active ) { throw new QuoteError( 422, 'استعلام گروهی هنوز تنظیم نشده است.' ); }
			$mirrors = BulkMapping::routes( $config, $providers, 'mirror' ); $sources = BulkMapping::routes( $config, $providers, 'source' );
			$result = $validate ? $this->preflight( $config, $values, $providers, $active, $mirrors, $sources ) : array( 'results' => $this->check( $config, $values, $providers, $active, $mirrors, $sources, $preview ) );
			if ( false === json_encode( $result ) ) { throw new \UnexpectedValueException( 'Unserializable public bulk result.' ); } return $result;
		} catch ( QuoteError $error ) { throw $error; }
		catch ( \DomainException $error ) { throw $error; }
		catch ( \Throwable $error ) { throw new QuoteError( 500, 'internal error', null ); }
	}
	private static function required( $config, $values ): void { $errors = BulkMapping::missing_required( $config, $values ); if ( $errors ) { throw new QuoteError( 422, 'فیلدهای اجباری تکمیل نشده است.', $errors ); } }
	private function check( $config, $values, array $providers, array $active, array $mirrors, array $sources, bool $preview ): array {
		$fields = BulkMapping::fields_with_kinds( $config, $providers, $active ); self::required( $config, $values );
		list( $enabled, $_, $province, $city ) = BulkMapping::destination( $config, $fields ); $sections = array();
		foreach ( $active as $provider ) { $sections[] = call_user_func( $this->section, $provider ); }
		$jobs = array();
		foreach ( $active as $index => $provider ) {
			$incoming = BulkMapping::incoming( $provider, $values, $config, $mirrors, $providers, $sources );
			$unserved = $enabled && V::truth( $province ) && V::truth( $city ) && ! BulkMapping::destination_served( $sections[$index], V::get( $values, $province ), V::get( $values, $city ) );
			foreach ( BulkMapping::combinations( $config, $provider->id ) as $variant ) {
				$execution = new QuoteExecution( $this->store, $this->transport ); $payload = null; $error = null;
				if ( $unserved ) { $error = array( 'detail' => 'این شرکت به مقصد انتخاب‌شده سرویس نمی‌دهد.', 'unserved' => true ); }
				else {
					try { $payload = $execution->normalise( $provider, V::truth( $variant ) ? BulkMapping::variant_payload( $provider, $incoming, $variant ) : $incoming ); }
					catch ( PayloadError $failure ) { $error = array( 'detail' => 'مقادیر ارسالی معتبر نیست.', 'errors' => $failure->errors ); }
				}
				$jobs[] = array( $provider, $payload, $error, $variant, $execution );
			}
		}
		foreach ( $jobs as &$job ) { $job[] = ! $preview && null !== $job[1] ? $job[4]->plan( $job[0], $job[1] ) : null; } unset( $job );
		// Portable PHP 7.4/WP HTTP is synchronous: see the documented timing/side-effect limitation.
		$results = array(); foreach ( $jobs as $job ) { $results[] = $this->run( ...array_merge( $job, array( $preview ) ) ); } return $results;
	}
	private function run( $provider, ?NormalizedPayload $payload, ?array $error, \stdClass $variant, QuoteExecution $execution, ?array $plan, bool $preview ): array {
		$ref = array( 'id' => $provider->id, 'name' => $provider->name, 'display_name' => $provider->display_name ); $detail = BulkMapping::variant_detail( $provider, $variant );
		$failure = array( 'provider' => $ref, 'variant' => $variant, 'variant_detail' => $detail, 'ok' => false );
		if ( null !== $error || null === $payload ) { return $failure + array( 'error' => $error ?: array( 'detail' => 'خطای نامشخص.' ) ); }
		try { list( $quote, $cached ) = $execution->fetch( $provider, $payload, $preview, $plan ); }
		catch ( ProviderCallError $error ) {
			if ( ! $preview ) { $execution->log_failure( $provider, $payload, $error ); }
			$public = V::truth( $error->extra['missing_price_fields'] ?? null ) ? array( 'detail' => 'سرویس در حال حاضر پاسخگو نیست.' ) : array( 'detail' => $error->getMessage(), 'errors' => $error->errors );
			return (array) $execution->clean( $failure + array( 'error' => $public ) );
		}
		return (array) $execution->clean( array( 'ok' => true, 'variant' => $variant, 'variant_detail' => $detail ) + $execution->result( $provider, $quote, $cached ) );
	}
	private function preflight( $config, $values, array $providers, array $active, array $mirrors, array $sources ): array {
		$problems = $shared_missing = array();
		foreach ( BulkMapping::missing_required( $config, $values ) as $item ) { $shared_missing[] = $item['field']; $problems[] = array( 'provider' => '', 'display_name' => '', 'field' => $item['field'], 'target' => '', 'message' => $item['message'] ); }
		foreach ( $active as $provider ) {
			$incoming = BulkMapping::incoming( $provider, $values, $config, $mirrors, $providers, $sources );
			try { ( new QuoteExecution( $this->store, $this->transport ) )->normalise( $provider, $incoming ); }
			catch ( PayloadError $error ) { foreach ( $error->errors as $item ) {
				$shared = BulkMapping::shared_field( $provider, $item['field'] ?? '', $config, $providers ); if ( '' !== $shared && in_array( $shared, $shared_missing, true ) ) { continue; }
				$problems[] = array( 'provider' => $provider->name, 'display_name' => V::fallback( V::get( $provider, 'display_name' ), $provider->name ), 'field' => $shared, 'target' => $item['field'] ?? '', 'message' => $item['message'] ?? '' );
			} }
		} return array( 'valid' => ! $problems, 'errors' => $problems );
	}
}
