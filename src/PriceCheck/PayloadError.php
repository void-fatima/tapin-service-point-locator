<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

final class PayloadError extends \InvalidArgumentException {
	public array $errors;
	public function __construct( array $errors ) {
		$this->errors = $errors;
		parent::__construct( $errors ? $errors[0]['message'] : 'invalid payload' );
	}
}
