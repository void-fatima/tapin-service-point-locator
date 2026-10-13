<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** locations.py::LocationError; only this expected failure is tolerated by the bulk adapter. */
final class LocationError extends \RuntimeException {}
