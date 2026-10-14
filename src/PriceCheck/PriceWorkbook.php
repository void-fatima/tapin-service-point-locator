<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Values-only OOXML adapter. Uses the plugin's existing optional PHP zip/XML facilities. */
final class PriceWorkbook {
	private static function xml( string $text ): \DOMDocument {
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $text ) ) { throw new \RuntimeException( 'Unsafe workbook XML.' ); }
		$previous = libxml_use_internal_errors( true ); try { $doc = new \DOMDocument(); if ( ! $doc->loadXML( $text, LIBXML_NONET ) ) { throw new \RuntimeException( 'Invalid workbook XML.' ); } return $doc; } finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
	}
	private static function children( \DOMNode $node, string $name ): array { $out = array(); foreach ( $node->childNodes as $child ) { if ( $child instanceof \DOMElement && $child->localName === $name ) { $out[] = $child; } } return $out; }
	private static function texts( \DOMElement $node ): string { $out = ''; foreach ( $node->getElementsByTagName( 't' ) as $item ) { $out .= $item->textContent; } return $out; }
	public static function read( string $path, bool $active_only = false ): array {
		$zip = new \ZipArchive(); if ( true !== $zip->open( $path ) ) { throw new \RuntimeException( 'Invalid workbook.' ); }
		try {
			$size = 0; if ( $zip->numFiles > 2000 ) { throw new \RuntimeException( 'Workbook package too large.' ); } for ( $i = 0; $i < $zip->numFiles; $i++ ) { $size += $zip->statIndex( $i )['size']; if ( $size > 32 * 1024 * 1024 ) { throw new \RuntimeException( 'Expanded workbook too large.' ); } }
			$workbook = self::xml( (string) $zip->getFromName( 'xl/workbook.xml' ) ); $rels = self::xml( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) ); $paths = array(); foreach ( $rels->getElementsByTagName( 'Relationship' ) as $rel ) { if ( 'External' !== $rel->getAttribute( 'TargetMode' ) ) { $target = $rel->getAttribute( 'Target' ); $paths[$rel->getAttribute( 'Id' )] = '/' === substr( $target, 0, 1 ) ? ltrim( $target, '/' ) : 'xl/' . $target; } }
			$strings = array(); $raw = $zip->getFromName( 'xl/sharedStrings.xml' ); if ( false !== $raw ) { foreach ( self::xml( $raw )->getElementsByTagName( 'si' ) as $item ) { $strings[] = self::texts( $item ); } }
			// Source openpyxl style/calendar semantics; dates become the strings consumed by the API.
			$date_styles = array(); $raw = $zip->getFromName( 'xl/styles.xml' ); if ( false !== $raw ) { $styles = self::xml( $raw ); $custom = array(); foreach ( $styles->getElementsByTagName( 'numFmt' ) as $fmt ) { $custom[(int) $fmt->getAttribute( 'numFmtId' )] = $fmt->getAttribute( 'formatCode' ); } foreach ( $styles->getElementsByTagName( 'cellXfs' ) as $group ) { foreach ( self::children( $group, 'xf' ) as $i => $xf ) { $id = (int) $xf->getAttribute( 'numFmtId' ); $date_styles[$i] = isset( $custom[$id] ) ? WorkbookDates::format( $custom[$id] ) : array( in_array( $id, array_merge( range( 14, 22 ), range( 45, 47 ) ), true ), 46 === $id ); } } }
			$mac = false; foreach ( $workbook->getElementsByTagName( 'workbookPr' ) as $pr ) { $mac = in_array( $pr->getAttribute( 'date1904' ), array( '1', 'true' ), true ); } $active = 0; foreach ( $workbook->getElementsByTagName( 'workbookView' ) as $view ) { $active = (int) $view->getAttribute( 'activeTab' ); break; } $sheets = array();
			foreach ( $workbook->getElementsByTagName( 'sheet' ) as $i => $sheet ) { if ( $active_only && $i !== $active ) { $sheets[] = array( 'title' => $sheet->getAttribute( 'name' ), 'rows' => array() ); continue; } $rid = $sheet->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' ); $file = $paths[$rid] ?? ''; if ( ! preg_match( '#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#D', $file ) ) { throw new \RuntimeException( 'Unsupported workbook relationship.' ); } $doc = self::xml( (string) $zip->getFromName( $file ) ); $rows = array(); $line = 0;
				foreach ( $doc->getElementsByTagName( 'row' ) as $row ) { $line = $row->hasAttribute( 'r' ) ? (int) $row->getAttribute( 'r' ) : $line + 1; $cells = array(); $col = 0;
					foreach ( self::children( $row, 'c' ) as $cell ) { $ref = $cell->getAttribute( 'r' ); if ( preg_match( '/^([A-Z]+)[0-9]+$/D', $ref, $match ) ) { $col = 0; foreach ( str_split( $match[1] ) as $letter ) { $col = $col * 26 + ord( $letter ) - 64; } } else { $col++; } if ( $col < 1 || $col > 16384 || $line < 1 || $line > 1048576 ) { throw new \RuntimeException( 'Invalid cell coordinates.' ); }
						$values = self::children( $cell, 'v' ); $raw = $values ? $values[0]->textContent : null; $type = $cell->getAttribute( 't' ); $value = $raw;
						if ( 'inlineStr' === $type ) { $inline = self::children( $cell, 'is' ); $value = $inline ? self::texts( $inline[0] ) : null; }
						elseif ( 's' === $type && null !== $raw ) { $index = V::integer( $raw ); if ( ! array_key_exists( $index, $strings ) ) { throw new \RuntimeException( 'Invalid shared string.' ); } $value = $strings[$index]; }
						elseif ( 'b' === $type && null !== $raw ) { $value = 0 !== V::integer( $raw ); }
						elseif ( null !== $raw && ( '' === $type || 'n' === $type ) ) { $value = preg_match( '/[.eE]/', $raw ) ? V::number( $raw ) : V::integer( $raw ); $style = $date_styles[(int) $cell->getAttribute( 's' )] ?? array( false, false ); if ( $style[0] ) { $value = WorkbookDates::serial( $value, $mac, $style[1] ); } }
						elseif ( 'd' === $type ) { $value = WorkbookDates::iso( $raw ?? '' ); }
						elseif ( ! in_array( $type, array( '', 'n', 's', 'b', 'str', 'e', 'inlineStr' ), true ) ) { throw new \RuntimeException( 'Unsupported workbook cell type.' ); }
						while ( count( $cells ) < $col ) { $cells[] = null; } $cells[$col - 1] = $value;
					} $rows[$line - 1] = $cells;
				} $sheets[] = array( 'title' => $sheet->getAttribute( 'name' ), 'rows' => $rows );
			} if ( ! isset( $sheets[$active] ) ) { throw new \RuntimeException( 'No active worksheet.' ); } return array( 'active' => $active, 'sheets' => $sheets );
		} finally { $zip->close(); }
	}
	public static function write( array $sheets ): string {
		$open = false; $path = tempnam( sys_get_temp_dir(), 'tapin-price-workbook-' ); if ( false === $path ) { throw new \RuntimeException( 'Workbook temporary file failed.' ); } @chmod( $path, 0600 );
		try { $zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) { throw new \RuntimeException( 'Workbook creation failed.' ); } $open = true; $names = $rels = $types = ''; $escape = static fn( $text ) => htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			foreach ( $sheets as $i => $sheet ) { $id = $i + 1; $names .= '<sheet name="' . $escape( $sheet['title'] ) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>'; $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>'; $types .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; $data = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
				foreach ( $sheet['rows'] as $index => $row ) { $line = $index + 1; $data .= '<row r="' . $line . '">'; foreach ( array_values( $row ) as $col => $value ) { if ( null === $value ) { continue; } $n = $col + 1; $letters = ''; while ( $n > 0 ) { $n--; $letters = chr( 65 + $n % 26 ) . $letters; $n = intdiv( $n, 26 ); } $ref = $letters . $line; if ( is_bool( $value ) ) { $data .= '<c r="' . $ref . '" t="b"><v>' . (int) $value . '</v></c>'; } elseif ( is_int( $value ) || is_float( $value ) ) { $data .= '<c r="' . $ref . '"><v>' . $escape( V::string( $value ) ) . '</v></c>'; } else { $data .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escape( (string) $value ) . '</t></is></c>'; } } $data .= '</row>'; }
				if ( ! $zip->addFromString( 'xl/worksheets/sheet' . $id . '.xml', $data . '</sheetData></worksheet>' ) ) { throw new \RuntimeException( 'Workbook write failed.' ); }
			}
			$parts = array( '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $types . '</Types>', '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>', 'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $names . '</sheets></workbook>', 'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>' ); foreach ( $parts as $name => $xml ) { if ( ! $zip->addFromString( $name, $xml ) ) { throw new \RuntimeException( 'Workbook write failed.' ); } } if ( ! $zip->close() ) { throw new \RuntimeException( 'Workbook close failed.' ); } $open = false; $bytes = file_get_contents( $path ); if ( false === $bytes ) { throw new \RuntimeException( 'Workbook read failed.' ); } return $bytes;
		} finally { if ( $open ) { try { $zip->close(); } catch ( \Throwable $error ) {} } @unlink( $path ); }
	}
}
