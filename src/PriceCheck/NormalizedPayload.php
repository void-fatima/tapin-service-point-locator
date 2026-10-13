<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Like Python NormalizedPayload: origin metadata never serializes into the payload. */
final class NormalizedPayload implements \JsonSerializable {
	public \stdClass $values;
	public ?\stdClass $origin;
	public function __construct( \stdClass $values, ?\stdClass $origin ) { $this->values = $values; $this->origin = $origin; }
	public function jsonSerialize(): \stdClass { return $this->values; }
}
