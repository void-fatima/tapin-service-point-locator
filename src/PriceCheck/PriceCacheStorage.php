<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Durable source MySQL cache: stale retrieval and freshness are separate operations. */
final class PriceCacheStorage {
	private OperationalClock $clock;
	public function __construct( ?OperationalClock $clock = null ) { $this->clock = $clock ?? new OperationalClock(); }
	private function prune(): void {
		ConfigurationDatabase::query( 'DELETE FROM ' . ConfigurationDatabase::table( 'price_cache' ) . ' WHERE created_at < %s', array( $this->clock->iso( CacheSettings::get()['price_retention'] ) ) );
	}
	public function get( $provider_id, string $params_hash ): ?array {
		$this->prune();
		$rows = ConfigurationDatabase::rows( 'SELECT created_at, provider_name, params_json, endpoint, quote_json FROM ' . ConfigurationDatabase::table( 'price_cache' ) . ' WHERE provider_id = %d AND params_hash = %s', array( PythonValue::integer( $provider_id ), $params_hash ) );
		if ( ! $rows ) { return null; } $row = $rows[0];
		$row['params'] = PythonValue::fallback( OperationalJson::parse( $row['params_json'] ), new \stdClass() );
		$row['quote'] = PythonValue::fallback( OperationalJson::parse( $row['quote_json'] ), new \stdClass() );
		unset( $row['params_json'], $row['quote_json'] ); return $row;
	}
	public function save( $record ): void {
		if ( ! PythonValue::mapping( $record ) ) { throw new \InvalidArgumentException( 'Cache entry must be an object.' ); }
		$get = static fn( $key ) => PythonValue::get( $record, $key );
		$data = array( 'created_at' => $this->clock->iso(), 'provider_id' => $get( 'provider_id' ), 'provider_name' => PythonValue::text( PythonValue::fallback( $get( 'provider_name' ), '' ) ), 'params_hash' => PythonValue::text( PythonValue::fallback( $get( 'params_hash' ), '' ) ), 'params_json' => OperationalJson::dump( PythonValue::fallback( $get( 'params' ), new \stdClass() ) ), 'endpoint' => PythonValue::text( PythonValue::fallback( $get( 'endpoint' ), '' ) ), 'quote_json' => OperationalJson::dump( PythonValue::fallback( $get( 'quote' ), new \stdClass() ) ) );
		ConfigurationDatabase::upsert( 'price_cache', $data, array( 'created_at', 'provider_name', 'params_json', 'endpoint', 'quote_json' ) ); $this->prune();
	}
	public function is_fresh( $created_at ): bool {
		if ( ! is_string( $created_at ) || '' === $created_at ) { return false; }
		// Source-generated extended ISO forms, naive UTC and explicit offsets; no strtotime free text.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d{1,6})?(?:Z|[+-]\d{2}:\d{2}(?::\d{2})?)?)?$/D', $created_at ) ) {
			if ( preg_match( '/^\d{4}(?:-?\d{2}|-?W\d{2})/', $created_at ) ) { throw new \DomainException( 'This ISO timestamp form needs a Python/PHP compatibility decision.' ); }
			return false;
		}
		try { $moment = new \DateTimeImmutable( str_replace( ',', '.', $created_at ), new \DateTimeZone( 'UTC' ) ); }
		catch ( \Exception $error ) { return false; }
		$errors = \DateTimeImmutable::getLastErrors();
		if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) { return false; }
		$age = (float) $this->clock->now()->format( 'U.u' ) - (float) $moment->format( 'U.u' );
		return $age < CacheSettings::get()['price'];
	}
	public function clear(): int { return ConfigurationDatabase::query( 'DELETE FROM ' . ConfigurationDatabase::table( 'price_cache' ) ); }
}
