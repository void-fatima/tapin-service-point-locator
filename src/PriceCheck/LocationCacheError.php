<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Infrastructure failure: not a source LocationError and never a successful save/unlock. */
final class LocationCacheError extends \RuntimeException {}
