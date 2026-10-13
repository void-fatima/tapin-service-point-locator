<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Extend the Stage 6 boundary without changing existing QuoteStore callers. */
interface BulkQuoteStore extends QuoteStore {
	public function providers(): array;
	public function config(): \stdClass;
}
