<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Failure-log storage only; callers decide when a failed live quote should be logged. */
final class QuoteLogStorage {
	private OperationalClock $clock;
	public function __construct( ?OperationalClock $clock = null ) { $this->clock = $clock ?? new OperationalClock(); }
	private function prune(): void {
		ConfigurationDatabase::query( 'DELETE FROM ' . ConfigurationDatabase::table( 'quote_logs' ) . ' WHERE created_at < %s', array( $this->clock->iso( CacheSettings::get()['quote_logs'] ) ) );
	}
	public function save( $record ): int {
		if ( ! PythonValue::mapping( $record ) ) { throw new \InvalidArgumentException( 'Quote log must be an object.' ); }
		$get = static fn( $key, $default = null ) => PythonValue::get( $record, $key, $default );
		if ( 'error' !== strtolower( PythonValue::text( PythonValue::fallback( $get( 'status' ), '' ) ) ) ) { return 0; }
		$provider = PythonValue::mapping( $get( 'provider' ) ) ? $get( 'provider' ) : new \stdClass();
		$payload = PythonValue::mapping( $get( 'payload' ) ) ? $get( 'payload' ) : new \stdClass();
		$provider_id = $get( 'provider_id' );
		if ( null === $provider_id || '' === $provider_id ) { $provider_id = PythonValue::get( $provider, 'id' ); }
		$data = array(
			'created_at' => $this->clock->iso(), 'provider_id' => $provider_id,
			'provider_name' => PythonValue::text( PythonValue::fallback( $get( 'provider_name' ), PythonValue::fallback( PythonValue::get( $provider, 'name' ), '' ) ) ),
			'display_name' => PythonValue::text( PythonValue::fallback( $get( 'display_name' ), PythonValue::fallback( PythonValue::get( $provider, 'display_name' ), '' ) ) ),
			'endpoint' => PythonValue::text( PythonValue::fallback( $get( 'endpoint' ), '' ) ), 'status' => 'error',
			'error_message' => PythonValue::fallback( PythonValue::text( PythonValue::fallback( $get( 'error_message' ), '' ) ), null ),
		);
		LogSafety::assert_safe( $data ); LogSafety::endpoint( $data['endpoint'] );
		LogSafety::assert_safe( $payload ); LogSafety::assert_safe( $get( 'response' ) );
		$data['payload_json'] = OperationalJson::dump( $payload );
		$data['response_json'] = null === $get( 'response' ) ? null : OperationalJson::dump( $get( 'response' ) );
		ConfigurationDatabase::insert( 'quote_logs', $data ); $id = ConfigurationDatabase::insert_id();
		$this->prune(); return $id;
	}
	public function list_rows( $limit = 100, $provider = null, $since = null, $until = null, $status = null ): array {
		$this->prune(); $where = $args = array();
		if ( null !== $provider && ! is_bool( $provider ) ) {
			if ( is_int( $provider ) || ( is_string( $provider ) && preg_match( '/^\p{Nd}+$/uD', PythonValue::trim( $provider ) ) ) ) {
				$where[] = 'provider_id = %d'; $args[] = PythonValue::integer( $provider );
			} elseif ( is_string( $provider ) && '' !== PythonValue::trim( $provider ) ) { $where[] = 'provider_name = %s'; $args[] = PythonValue::trim( $provider ); }
		}
		if ( PythonValue::truth( $status ) ) { $where[] = 'status = %s'; $args[] = strtolower( PythonValue::text( $status ) ); }
		if ( PythonValue::truth( $since ) ) { $where[] = 'created_at >= %s'; $args[] = $since; }
		if ( PythonValue::truth( $until ) ) { $where[] = 'created_at <= %s'; $args[] = $until; }
		$args[] = max( 1, PythonValue::integer( PythonValue::fallback( $limit, 100 ) ) );
		$rows = ConfigurationDatabase::rows( 'SELECT id, created_at, provider_id, provider_name, display_name, endpoint, status, error_message, payload_json, response_json FROM ' . ConfigurationDatabase::table( 'quote_logs' ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY id DESC LIMIT %d', $args );
		foreach ( $rows as &$row ) {
			$row['payload'] = PythonValue::fallback( OperationalJson::parse( $row['payload_json'] ), new \stdClass() );
			$row['response'] = OperationalJson::parse( $row['response_json'] ); unset( $row['payload_json'], $row['response_json'] );
		} unset( $row ); return $rows;
	}
	public function clear(): int { return ConfigurationDatabase::query( 'DELETE FROM ' . ConfigurationDatabase::table( 'quote_logs' ) ); }
	public function delete( $id ): bool {
		try { $id = PythonValue::integer( $id ); } catch ( \InvalidArgumentException $error ) { return false; }
		return ConfigurationDatabase::delete( 'quote_logs', array( 'id' => $id ) ) > 0;
	}
}
