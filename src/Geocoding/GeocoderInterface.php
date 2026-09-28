<?php
namespace Tapin\ServicePointLocator\Geocoding;

defined( 'ABSPATH' ) || exit;

interface GeocoderInterface {
	/** Stable adapter/version identifier, also namespaces the address cache. */
	public function name(): string;
	public function configured(): bool;
	/** Return normalized candidates or a WP_Error with retryable/retry_after data. */
	public function geocode( array $query );
}
