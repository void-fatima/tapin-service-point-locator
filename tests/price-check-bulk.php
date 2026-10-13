<?php
/** Original bulk expectations, isolated from WordPress and all external access. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\BulkMapping;
use Tapin\ServicePointLocator\PriceCheck\BulkConfig;
use Tapin\ServicePointLocator\PriceCheck\BulkQuoteService;
use Tapin\ServicePointLocator\PriceCheck\BulkQuoteStore;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
use Tapin\ServicePointLocator\PriceCheck\ProviderCallError;
use Tapin\ServicePointLocator\PriceCheck\QuoteError;
$passed = 0;
function bulk_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function bulk_json( $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ); }
function bulk_expand( $value ) {
	if ( $value instanceof stdClass && isset( $value->{'$fixture'} ) ) { return $GLOBALS['fixtures']->inputs->{$value->{'$fixture'}}; }
	if ( $value instanceof stdClass ) { $out = new stdClass(); foreach ( (array) $value as $key => $item ) { $out->$key = bulk_expand( $item ); } return $out; }
	return is_array( $value ) ? array_map( 'bulk_expand', $value ) : $value;
}
/** State mapping key order follows async completion in Python and is not an output-order promise. */
function bulk_canonical( $value ) {
	if ( is_array( $value ) ) { return array_map( 'bulk_canonical', $value ); }
	if ( $value instanceof stdClass ) { $out = (array) $value; ksort( $out, SORT_STRING ); foreach ( $out as &$item ) { $item = bulk_canonical( $item ); } unset( $item ); return (object) $out; } return $value;
}
function bulk_copy( $value ) { return json_decode( bulk_json( $value ) ); }
final class BulkFixtureStore implements BulkQuoteStore {
	public array $providers;
	public stdClass $config;
	public array $shops = array();
	public array $cache = array();
	public array $logs = array();
	public array $sections = array();
	public int $cache_reads = 0;
	public int $cache_writes = 0;
	public int $config_reads = 0;
	public ?string $failure = null;
	public DateTimeImmutable $now;
	public function fail( string $kind ): void { if ( $kind === $this->failure ) { $this->failure = null; throw new RuntimeException( 'private fixture failure' ); } }
	public function providers(): array { $this->fail( 'providers' ); return bulk_copy( $this->providers ); }
	public function config(): stdClass { $this->fail( 'config' ); $this->config_reads++; return bulk_copy( $this->config ); }
	public function provider( string $identifier ): ?stdClass { throw new LogicException( 'Bulk must load ordered providers, not single-provider lookup.' ); }
	public function shops(): array { $this->fail( 'shops' ); return $this->shops; }
	public function shop( string $identifier ): ?array { return '01' === $identifier ? array( 'id' => $identifier, 'token' => 'inert-test-marker' ) : null; }
	public function cache_get( int $id, string $key ): ?array {
		$this->fail( 'cache_get' ); $this->cache_reads++; $identity = "{$id}:{$key}"; $entry = $this->cache[$identity] ?? null;
		if ( null !== $entry && $this->now->getTimestamp() - ( new DateTimeImmutable( $entry['created_at'] ) )->getTimestamp() > 10 ) { unset( $this->cache[$identity] ); return null; } return $entry;
	}
	public function cache_fresh( $stamp ): bool { return $this->now->getTimestamp() - ( new DateTimeImmutable( $stamp ) )->getTimestamp() < 2; }
	public function cache_save( stdClass $record ): void {
		$this->fail( 'cache_save' ); $this->cache_writes++;
		$this->cache[$record->provider_id . ':' . $record->params_hash] = array( 'created_at' => $this->now->format( 'Y-m-d\TH:i:sP' ), 'provider_name' => $record->provider_name, 'params' => bulk_copy( $record->params ), 'endpoint' => $record->endpoint, 'quote' => bulk_copy( $record->quote ) );
	}
	public function log_save( stdClass $record ): void { $this->fail( 'log_save' ); $this->logs[] = bulk_copy( $record ); }
}
final class BulkFixtureTransport implements ProviderTransport {
	public array $requests = array();
	public stdClass $replies;
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		$this->requests[] = array( 'url' => $request->url, 'body' => bulk_copy( $request->body ), 'authorization' => 'inert-test-marker' === ( $request->headers()['Authorization'] ?? null ), 'timeout' => $request->timeout );
		$name = basename( $request->url ); $reply = $this->replies->$name ?? new stdClass(); $mode = $reply->mode ?? '';
		if ( 'timeout' === $mode ) { throw new ProviderCallError( 'سرویس قیمت در زمان مقرر پاسخ نداد.', array( 'status_code' => 504, 'endpoint' => $request->url ) ); }
		if ( 'network' === $mode ) { throw new ProviderCallError( 'ارتباط با سرویس قیمت برقرار نشد.', array( 'endpoint' => $request->url ) ); }
		if ( 'unexpected' === $mode ) { throw new RuntimeException( 'private fixture failure' ); }
		return new ProviderHttpResponse( $reply->status ?? 200, $reply->raw ?? '{"total":"15"}' );
	}
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-bulk.json' ) );
bulk_check( JSON_ERROR_NONE === json_last_error() && false === $fixtures->provenance->live_access, 'Invalid bulk fixtures.' );
foreach ( $fixtures->cases as $index => $case ) {
	$case->args = bulk_expand( $case->args );
	try {
		if ( 'incoming' === $case->op ) { foreach ( array( 3, 5 ) as $position ) { if ( isset( $case->args[$position] ) ) { $case->args[$position] = array_map( static fn( $row ) => (array) $row, (array) $case->args[$position] ); } } }
		if ( 'provider_id' === $case->op ) { $value = BulkConfig::field_provider_id( ...$case->args ); }
		elseif ( 'inferred_destination' === $case->op ) { list( $cfg, $providers ) = $case->args; $value = BulkMapping::destination( $cfg, BulkMapping::fields_with_kinds( $cfg, $providers, BulkConfig::active_providers( $cfg, $providers ) ) ); }
		else { $value = call_user_func_array( array( BulkMapping::class, $case->op ), $case->args ); }
		$actual = array( 'ok' => true, 'value' => $value );
	} catch ( UnexpectedValueException $error ) { $actual = array( 'ok' => false, 'kind' => 'TypeError' ); }
	bulk_check( bulk_json( $actual ) === bulk_json( $case->expected ), "Original bulk helper mismatch {$index} ({$case->op})." );
}
// Cartesian construction follows the exact loop in _bulk_check_results, including empty-choice reset.
$combos = BulkMapping::combinations( $fixtures->config, 1 );
bulk_check( bulk_json( $combos ) === '[{"13":"a","14.141":"2"},{"13":"a","14.141":"3"},{"13":"b","14.141":"2"},{"13":"b","14.141":"3"}]', 'Combination order.' );
$empty = (object) array( 'variants' => (object) array( '1' => (object) array( '13' => array( 'a' ), '14.141' => array() ) ) );
bulk_check( bulk_json( BulkMapping::combinations( $empty, 1 ) ) === '[{}]', 'Empty choices reset combinations.' );
foreach ( $fixtures->flows as $flow ) {
	$store = new BulkFixtureStore(); $store->providers = bulk_expand( $flow->providers ); $store->config = bulk_expand( $flow->config ); $store->now = new DateTimeImmutable( '2026-01-20T12:00:00+00:00' );
	$store->shops = array( array( 'id' => '01', 'title' => 'first', 'province' => '1', 'city' => '1' ), array( 'id' => '02', 'title' => 'second', 'province' => '1', 'city' => '2' ) );
	$transport = new BulkFixtureTransport(); $datasets = new stdClass();
	$service = new BulkQuoteService( static function( $provider ) use ( $store, &$datasets ) { $store->sections[] = $provider->id; if ( 'section' === $store->failure ) { throw new RuntimeException( 'private fixture failure' ); } return $datasets->{(string) $provider->id} ?? null; }, $transport, $store );
	foreach ( $flow->actions as $index => $step ) {
		$step = bulk_expand( $step );
		$store->failure = $step->failure ?? null; $transport->replies = $step->replies ?? new stdClass(); $datasets = $step->datasets ?? new stdClass();
		if ( isset( $step->now ) ) { $store->now = new DateTimeImmutable( $step->now ); }
		$config_reads = $store->config_reads;
		try { $body = ! empty( $step->validate ) ? $service->validate( $step->body, $step->preview ?? false ) : $service->execute( $step->body, $step->preview ?? false ); $actual = array( 'status' => 200, 'body' => $body ); }
		catch ( QuoteError $error ) { $actual = array( 'status' => $error->status_code, 'body' => $error->jsonSerialize() ); }
		bulk_check( bulk_json( $actual ) === bulk_json( $step->expected ), "Original bulk result mismatch {$flow->name} / {$index}." );
		$state = array( 'cache' => (object) $store->cache, 'logs' => $store->logs, 'requests' => $transport->requests, 'sections' => $store->sections, 'cache_reads' => $store->cache_reads, 'cache_writes' => $store->cache_writes );
		$expected = clone $step->state; unset( $expected->completed );
		if ( 500 !== $step->expected->status ) { bulk_check( bulk_json( bulk_canonical( bulk_copy( $state ) ) ) === bulk_json( bulk_canonical( $expected ) ), "Original bulk state mismatch {$flow->name} / {$index}." ); }
		// Unexpected gather failures can leave other async jobs running; compare only the global result.
		if ( ! empty( $step->preview ) ) { bulk_check( $config_reads === $store->config_reads, 'Preview read live bulk configuration.' ); }
		bulk_check( false === strpos( bulk_json( array( $actual, $store->cache, $store->logs ) ), 'inert-test-marker' ), 'Credential leaked in bulk result/cache/log.' );
		bulk_check( false === strpos( bulk_json( $actual ), 'private fixture failure' ), 'Private exception leaked.' );
	}
}
bulk_check( $fixtures->flows[0]->actions[0]->state->completed[0] === 'one', 'Python mock did not exercise completion order differing from provider order.' );
echo "Price-check bulk checks passed ({$passed} assertions).\n";
