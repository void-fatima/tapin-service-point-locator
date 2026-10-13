<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** UTC clock; injectable for retention/expiry checks without changing the site timezone. */
final class OperationalClock {
	private $clock;
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn() => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
	public function now(): \DateTimeImmutable { return ( $this->clock )()->setTimezone( new \DateTimeZone( 'UTC' ) ); }
	public function iso( int $seconds_ago = 0 ): string { return $this->now()->modify( '-' . $seconds_ago . ' seconds' )->format( 'Y-m-d\TH:i:sP' ); }
	public function milliseconds(): int { return (int) floor( (float) $this->now()->format( 'U.u' ) * 1000 ); }
}
