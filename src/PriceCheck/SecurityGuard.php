<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\SecurityFingerprint as F;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source pipeline, deliberately unregistered. Does not authorize WordPress administration. */
final class SecurityGuard {
	private SecurityStateStore $store;
	private SecurityLists $lists;
	private SecurityRateLimiter $limiter;
	private SecurityIpResolver $ips;
	private OperationalClock $clock;
	public function __construct( ?SecurityStateStore $store = null, ?SecurityRateLimiter $limiter = null, ?SecurityIpResolver $ips = null, ?OperationalClock $clock = null ) {
		$this->clock = $clock ?? new OperationalClock(); $this->store = $store ?? new WordPressSecurityStateStore(); $this->lists = new SecurityLists( $this->store, $this->clock ); $this->ips = $ips ?? new SecurityIpResolver();
		if ( null === $limiter ) { $routes = new SecurityRouteLimits(); [$max, $window] = $routes->effective(); $limiter = new SecurityRateLimiter( new WordPressRateLimitStore(), $this->clock, $max, $window, array( $routes, 'resolve' ), array( SecurityRouteLimits::class, 'normalize' ) ); } $this->limiter = $limiter;
	}
	private function emit( string $kind, string $fp, string $ip, string $route, string $detail, string $id = '' ): string {
		try {
			// Diagnostics never copy a body, browser value, cookie, header or storage exception.
			LogSafety::assert_safe( array( 'detail' => $detail ) ); LogSafety::endpoint( $route );
			$this->store->record( new SecurityEvent( array( 'created_at_ms' => SecurityTime::milliseconds( $this->clock ), 'kind' => $kind, 'client_fingerprint' => $fp, 'client_id' => $id, 'ip' => $ip, 'route' => $route, 'detail' => $detail ) ) );
		} catch ( \Throwable $error ) { /* Source events are best-effort, including failed cleanup after insert. */ } return $kind;
	}
	public function check( SecurityRequest $data ): SecurityVerdict {
		$route = $data->route; $events = array();
		try { $ip = $this->ips->resolve( $data->direct_ip, $data->headers ); } catch ( \Throwable $error ) { $ip = ''; }
		$agent = $data->headers['user-agent'] ?? ''; if ( is_array( $agent ) ) { $agent = $agent[0] ?? ''; } $agent = V::string( V::fallback( $agent, '' ) );
		$allowlisted = '' !== $ip && $this->lists->is_allowed( $ip );
		$base = new SecurityVerdict(); $base->identity->ip = $ip; $base->identity->user_agent = $agent;
		if ( $data->body_size > 262144 ) {
			$base->allowed = false; $base->http_status = 413; $base->code = 'payload_too_large'; $base->message_en = 'Payload too large'; $base->message_fa = 'حجم درخواست بیش از حد مجاز است.';
			$base->events[] = $this->emit( 'payload_too_large', '', $ip, $route, (string) $data->body_size ); return $base;
		}
		$raw = $data->cookies['tapin_cid'] ?? ''; $id = F::client_id( $raw ); $forged = F::forged( $raw ); $base->identity->client_id = $id; $base->issue_client_id = '' === $id;
		if ( $allowlisted ) { return $base; }
		[$browser, , $malformed, $present] = F::browser( $data->client_body );
		if ( $malformed ) { $events[] = $this->emit( 'invalid_client_signal', '', $ip, $route, 'browser signals are not an object' ); }
		$fp = F::client( $id, $browser ); if ( $forged ) { $events[] = $this->emit( 'suspicious_pattern', $fp, $ip, $route, 'forged client_id cookie', $id ); }
		$base->identity->browser_fingerprint = $browser; $base->identity->client_fingerprint = $fp;
		try { [$request_hash] = F::request( $data->business ); }
		catch ( \Throwable $error ) { $request_hash = ''; $events[] = $this->emit( 'malformed_request', $fp, $ip, $route, 'unhashable payload', $id ); }
		$base->request_hash = $request_hash; $base->request_fingerprint = F::context( $fp, $ip, $agent, $route, $request_hash );
		try { [$blocked, $dimension, $entry] = $this->lists->check_blocks( $ip, $id, $fp ); } catch ( \Throwable $error ) { $blocked = false; }
		if ( $blocked ) {
			try { LogSafety::assert_safe( $entry->reason ); $events[] = $this->emit( 'blocked_attempt', $fp, $ip, $route, 'matched ' . $dimension . ': ' . $entry->reason, $id ); }
			catch ( \Throwable $error ) { $events[] = 'blocked_attempt'; }
			$base->allowed = false; $base->http_status = 403; $base->code = 'client_blocked'; $base->message_en = 'Forbidden'; $base->message_fa = 'دسترسی شما مسدود شده است.'; $base->events = $events; return $base;
		}
		try { $outcome = $this->limiter->consume( $ip, $id, $fp, $present, $route ); } catch ( \Throwable $error ) { $outcome = null; }
		if ( null !== $outcome && ! $outcome['allowed'] ) {
			$events[] = $this->emit( 'rate_limit_exceeded', $fp, $ip, $route, 'dimensions: ' . implode( ',', $outcome['exceeded_dimensions'] ), $id );
			$base->allowed = false; $base->http_status = 429; $base->code = 'rate_limit_exceeded'; $base->message_en = 'Too many requests'; $base->message_fa = 'درخواست‌های شما بیش از حد مجاز است.'; $base->retry_after = $outcome['retry_after'];
		} $base->events = $events; return $base;
	}
}
