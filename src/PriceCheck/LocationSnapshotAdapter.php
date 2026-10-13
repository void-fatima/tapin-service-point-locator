<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Production api.py::_section_or_none boundary. Unexpected/cache/representation errors propagate. */
final class LocationSnapshotAdapter {
	private LocationService $locations;
	public function __construct( ?LocationService $locations = null ) { $this->locations = $locations ?? new LocationService(); }
	public function __invoke( $provider ): ?\stdClass { try { return $this->locations->provider_section( $provider ); } catch ( LocationError $error ) { return null; } }
}
