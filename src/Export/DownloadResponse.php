<?php
namespace Tapin\ServicePointLocator\Export;

defined( 'ABSPATH' ) || exit;

/** Private response handle; neither a filesystem path nor workbook JSON is exposed. */
final class DownloadResponse extends \WP_REST_Response {
	private string $file;
	private Workbook $workbook;
	public function __construct( Workbook $workbook, string $file ) {
		parent::__construct( null, 200 );
		$this->workbook = $workbook; $this->file = $file;
		$this->header( 'Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		$this->header( 'Content-Disposition', 'attachment; filename="tapin-service-points-' . wp_date( 'Y-m-d' ) . '.xlsx"' );
		$this->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$this->header( 'X-Content-Type-Options', 'nosniff' );
	}
	public static function serve( $served, $response, $request, $server ) {
		if ( ! $response instanceof self ) { return $served; }
		try { if ( ! $served ) { readfile( $response->file ); } }
		finally { $response->workbook->cleanup(); }
		return true;
	}
}
