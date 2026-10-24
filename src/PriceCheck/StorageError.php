<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Deliberately excludes SQL, submitted values and wpdb's potentially private error text. */
final class StorageError extends \RuntimeException {
	public string $operation;
	public ?string $unique_constraint;

	public function __construct( string $operation, ?string $unique_constraint = null ) {
		$this->operation = $operation;
		$this->unique_constraint = $unique_constraint;
		parent::__construct( 'ذخیره یا خواندن پیکربندی استعلام قیمت در پایگاه داده ناموفق بود.' );
	}
}
