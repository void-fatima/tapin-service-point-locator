<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;
interface ProviderTransport { public function send( ProviderRequest $request ): ProviderHttpResponse; }
