<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Same net.py mechanics as quotes, translating only the service-specific error vocabulary. */
final class WordPressLocationTransport implements ProviderTransport {
	private ProviderTransport $transport;
	public function __construct( ?ProviderTransport $transport = null ) { $this->transport = $transport ?? new WordPressProviderTransport(); }
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		try { return $this->transport->send( $request ); }
		catch ( ProviderCallError $error ) {
			if ( 504 === $error->status_code ) { throw new LocationError( 'سرویس شهر و استان در زمان مقرر پاسخ نداد.' ); }
			if ( 'پاسخ سرویس قیمت بیش از حد بزرگ است.' === $error->getMessage() ) { throw new LocationError( 'پاسخ سرویس شهر و استان بیش از حد بزرگ است.' ); }
			throw new LocationError( 'ارتباط با سرویس شهر و استان برقرار نشد.' );
		}
	}
}
