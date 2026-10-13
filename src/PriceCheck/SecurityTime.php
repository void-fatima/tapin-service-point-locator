<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Preserve injected millisecond boundaries without a floating seconds round trip. */
final class SecurityTime {
	public static function milliseconds( OperationalClock $clock ): int { $now = $clock->now(); return (int) $now->format( 'U' ) * 1000 + intdiv( (int) $now->format( 'u' ), 1000 ); }
}
