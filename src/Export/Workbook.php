<?php
namespace Tapin\ServicePointLocator\Export;

defined( 'ABSPATH' ) || exit;

/** Small OOXML writer: inline text cells, disk-backed sheet, no spreadsheet dependency. */
final class Workbook {
	private array $files = array();
	private $sheet;
	private int $row = 0;

	public function __construct() {
		if ( ! class_exists( 'ZipArchive' ) ) { throw new \RuntimeException( 'zip_unavailable' ); }
		$dir = realpath( sys_get_temp_dir() );
		$root = realpath( ABSPATH );
		$normalize = static fn( $p ) => strtolower( str_replace( '\\', '/', $p ) );
		if ( ! $dir || ! $root || 0 === strpos( $normalize( $dir ) . '/', rtrim( $normalize( $root ), '/' ) . '/' ) ) { throw new \RuntimeException( 'unsafe_temp' ); }
		register_shutdown_function( array( $this, 'cleanup' ) );
		foreach ( array( 'sheet', 'zip' ) as $key ) {
			$file = @tempnam( $dir, 'tapin-export-' );
			if ( ! $file || realpath( dirname( $file ) ) !== $dir ) { if ( $file ) { @unlink( $file ); } throw new \RuntimeException( 'temp_failed' ); }
			$this->files[$key] = $file; @chmod( $file, 0600 );
		}
		$this->sheet = @fopen( $this->files['sheet'], 'wb' );
		if ( ! $this->sheet ) { throw new \RuntimeException( 'temp_failed' ); }
		$this->write( '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' );
		foreach ( array( 22, 32, 20, 20, 65, 18, 24, 24, 24, 18, 18, 22 ) as $i => $width ) { $n = $i + 1; $this->write( '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>' ); }
		$this->write( '</cols><sheetData>' );
	}

	private function write( string $xml ): void {
		if ( @fwrite( $this->sheet, $xml ) !== strlen( $xml ) ) { throw new \RuntimeException( 'disk_write_failed' ); }
		if ( ftell( $this->sheet ) > 64 * 1024 * 1024 ) { throw new \RuntimeException( 'export_limit' ); }
	}

	public function row( array $values, bool $header = false ): void {
		$this->row++;
		$this->write( '<row r="' . $this->row . '">' );
		foreach ( array_values( $values ) as $i => $value ) {
			$ref = chr( 65 + $i ) . $this->row;
			if ( null === $value ) { continue; }
			if ( ! $header && in_array( $i, array( 9, 10 ), true ) && is_numeric( $value ) && is_finite( (float) $value ) ) {
				$this->write( '<c r="' . $ref . '" s="2"><v>' . json_encode( (float) $value ) . '</v></c>' );
			} else {
				$text = wp_strip_all_tags( (string) $value );
				$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text ) ?? '';
				// Fail rather than silently truncate a stored field beyond Excel's cell limit.
				if ( \Tapin\ServicePointLocator\Normalization\DataNormalizer::strlen( $text ) > 32767 ) { throw new \RuntimeException( 'cell_too_long' ); }
				// Explicit inlineStr (never <f>) prevents formula evaluation, preserving leading zeros.
				$this->write( '<c r="' . $ref . '" t="inlineStr" s="' . ( $header ? 1 : 0 ) . '"><is><t xml:space="preserve">' . htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</t></is></c>' );
			}
		}
		$this->write( '</row>' );
	}

	public function finish(): string {
		$this->write( '</sheetData><autoFilter ref="A1:L' . $this->row . '"/></worksheet>' );
		fclose( $this->sheet ); $this->sheet = null;
		$zip = new \ZipArchive();
		if ( true !== @$zip->open( $this->files['zip'], \ZipArchive::OVERWRITE ) ) { throw new \RuntimeException( 'zip_failed' ); }
		$parts = array(
			'[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
			'_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="نقاط خدماتی" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
			'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/><color rgb="FFFFFFFF"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF172238"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1" applyNumberFormat="1"><alignment vertical="top" wrapText="1" readingOrder="2"/></xf><xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1" applyFill="1" applyFont="1"><alignment horizontal="right" wrapText="1" readingOrder="2"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
		);
		try {
			foreach ( $parts as $name => $xml ) { if ( ! $zip->addFromString( $name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml ) ) { throw new \RuntimeException( 'zip_failed' ); } }
			if ( ! $zip->addFile( $this->files['sheet'], 'xl/worksheets/sheet1.xml' ) ) { throw new \RuntimeException( 'zip_failed' ); }
		} finally { $closed = @$zip->close(); }
		if ( ! $closed ) { throw new \RuntimeException( 'zip_failed' ); }
		return $this->files['zip'];
	}

	public function cleanup(): void {
		if ( is_resource( $this->sheet ) ) { fclose( $this->sheet ); $this->sheet = null; }
		foreach ( $this->files as $file ) { if ( is_file( $file ) ) { @unlink( $file ); } }
		$this->files = array();
	}
}
