<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Deliberately excludes SQL, submitted values and wpdb's potentially private error text. */
final class StorageError extends \RuntimeException {
	public string $operation;

	public function __construct( string $operation ) {
		$this->operation = $operation;
		parent::__construct( 'ذخیره یا خواندن پیکربندی استعلام قیمت در پایگاه داده ناموفق بود.' );
	}
}
