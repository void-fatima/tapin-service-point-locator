<?php
namespace Tapin\ServicePointLocator\Import;

defined( 'ABSPATH' ) || exit;

/** Bounded, values-only table readers. No formula evaluation or external XML resources. */
final class TableReader {
	public static function rows( string $path, string $type ): \Generator {
		if ( 'csv' === $type ) {
			$handle = fopen( $path, 'rb' );
			if ( ! $handle ) { throw new \RuntimeException( 'خواندن فایل ممکن نیست.' ); }
			try {
				$delimiter = CsvImporter::detect_delimiter( $path );
				while ( false !== ( $row = fgetcsv( $handle, 1048576, $delimiter, '"', '' ) ) ) {
					if ( array( null ) === $row ) { continue; }
					if ( count( $row ) > 64 ) { throw new \RuntimeException( 'حداکثر ۶۴ ستون پشتیبانی می‌شود.' ); }
					foreach ( $row as &$cell ) {
						$cell = (string) $cell;
						if ( strlen( $cell ) > 16000 || ! preg_match( '//u', $cell ) ) { throw new \RuntimeException( 'فایل باید UTF-8 باشد و هر سلول کمتر از ۱۶ کیلوبایت باشد.' ); }
					}
					unset( $cell );
					yield $row;
				}
			} finally { fclose( $handle ); }
			return;
		}
		if ( ! class_exists( '\ZipArchive' ) || ! class_exists( '\XMLReader' ) || ! function_exists( 'simplexml_load_string' ) ) {
			throw new \RuntimeException( 'برای Excel افزونه‌های PHP zip، XMLReader و SimpleXML لازم است. می‌توانید CSV بارگذاری کنید.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) { throw new \RuntimeException( 'فایل XLSX معتبر نیست.' ); }
		$temps = array();
		try {
			$expanded = 0;
			if ( $zip->numFiles > 2000 ) { throw new \RuntimeException( 'ساختار فایل Excel بیش از حد بزرگ است.' ); }
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				$expanded += $stat['size'];
				if ( $expanded > 32 * 1024 * 1024 ) { throw new \RuntimeException( 'حجم بازشده Excel بیش از ۳۲ مگابایت است؛ فایل را به CSV تبدیل یا تقسیم کنید.' ); }
			}
			$workbook = self::xml( (string) $zip->getFromName( 'xl/workbook.xml' ) );
			$rels = self::xml( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
			$sheets = $workbook->xpath( '//*[local-name()="sheet"]' );
			if ( ! $sheets ) { throw new \RuntimeException( 'برگه‌ای در Excel پیدا نشد.' ); }
			$rid = (string) $sheets[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )['id'];
			$sheet = '';
			foreach ( $rels->children() as $rel ) {
				if ( (string) $rel['Id'] === $rid && (string) $rel['TargetMode'] !== 'External' ) {
					$target = (string) $rel['Target'];
					$sheet = '/' === substr( $target, 0, 1 ) ? ltrim( $target, '/' ) : 'xl/' . $target;
				}
			}
			if ( ! preg_match( '#^xl/worksheets/[a-zA-Z0-9_.-]+\.xml$#', $sheet ) ) { throw new \RuntimeException( 'مسیر برگه Excel معتبر نیست.' ); }
			$strings = array();
			$shared = $zip->statName( 'xl/sharedStrings.xml' );
			if ( $shared ) {
				if ( $shared['size'] > 8 * 1024 * 1024 ) { throw new \RuntimeException( 'متن‌های مشترک Excel بیش از حد بزرگ است؛ از CSV استفاده کنید.' ); }
				$reader = self::reader( $zip, 'xl/sharedStrings.xml', $temps );
				try {
					while ( $reader->read() ) {
						if ( \XMLReader::DOC_TYPE === $reader->nodeType ) { throw new \RuntimeException( 'DTD مجاز نیست.' ); }
						if ( \XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
							$strings[] = self::text( self::xml( $reader->readOuterXml() ) );
							if ( count( $strings ) > 100000 ) { throw new \RuntimeException( 'تعداد متن‌های Excel بیش از حد مجاز است.' ); }
						}
					}
				} finally { $reader->close(); }
			}
			$reader = self::reader( $zip, $sheet, $temps );
			try {
				while ( $reader->read() ) {
					if ( \XMLReader::DOC_TYPE === $reader->nodeType ) { throw new \RuntimeException( 'DTD مجاز نیست.' ); }
					if ( \XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) { continue; }
					$xml = self::xml( $reader->readOuterXml() );
					$row = array();
					foreach ( $xml->xpath( './*[local-name()="c"]' ) as $cell ) {
						preg_match( '/^([A-Z]+)/', (string) $cell['r'], $match );
						$index = 0;
						foreach ( str_split( $match[1] ?? '' ) as $letter ) { $index = $index * 26 + ord( $letter ) - 64; }
						if ( $index < 1 || $index > 64 ) { throw new \RuntimeException( 'حداکثر ۶۴ ستون پشتیبانی می‌شود.' ); }
						if ( $cell->xpath( './*[local-name()="f"]' ) ) { throw new \RuntimeException( 'فرمول در Excel مجاز نیست؛ ابتدا مقادیر را Paste Values کنید.' ); }
						$v = $cell->xpath( './*[local-name()="v"]' );
						$value = (string) ( $v[0] ?? '' );
						if ( 's' === (string) $cell['t'] ) {
							if ( ! isset( $strings[(int) $value] ) ) { throw new \RuntimeException( 'ارجاع متن Excel نامعتبر است.' ); }
							$value = $strings[(int) $value];
						} elseif ( 'inlineStr' === (string) $cell['t'] ) { $value = self::text( $cell ); }
						if ( strlen( $value ) > 16000 ) { throw new \RuntimeException( 'محتوای سلول بیش از حد طولانی است.' ); }
						$row[$index - 1] = $value;
					}
					if ( ! $row ) { continue; }
					$filled = array_fill( 0, max( array_keys( $row ) ) + 1, '' );
					yield array_replace( $filled, $row );
				}
			} finally { $reader->close(); }
		} finally {
			$zip->close();
			foreach ( $temps as $temp ) { wp_delete_file( $temp ); }
		}
	}

	private static function xml( string $text ): \SimpleXMLElement {
		if ( strlen( $text ) > 1048576 || stripos( $text, '<!DOCTYPE' ) !== false || stripos( $text, '<!ENTITY' ) !== false ) { throw new \RuntimeException( 'ساختار XML مجاز نیست.' ); }
		$xml = simplexml_load_string( $text, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml ) { throw new \RuntimeException( 'فایل Excel خراب است.' ); }
		return $xml;
	}

	private static function text( \SimpleXMLElement $xml ): string {
		return implode( '', array_map( 'strval', $xml->xpath( './/*[local-name()="t"]' ) ) );
	}

	private static function reader( \ZipArchive $zip, string $name, array &$temps ): \XMLReader {
		$stream = $zip->getStream( $name );
		if ( ! $stream ) { throw new \RuntimeException( 'بخش مورد نیاز در Excel پیدا نشد.' ); }
		$temp = tempnam( sys_get_temp_dir(), 'tapin-xml-' );
		$temps[] = $temp;
		$out = fopen( $temp, 'wb' );
		stream_copy_to_stream( $stream, $out );
		fclose( $stream ); fclose( $out );
		$reader = new \XMLReader();
		if ( ! $reader->open( $temp, null, LIBXML_NONET ) ) { throw new \RuntimeException( 'خواندن Excel ممکن نیست.' ); }
		$reader->setParserProperty( \XMLReader::SUBST_ENTITIES, false );
		$reader->setParserProperty( \XMLReader::LOADDTD, false );
		return $reader;
	}
}
