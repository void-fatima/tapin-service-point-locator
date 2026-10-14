<?php
/** Inspect raw ZIP paths and runtime bytes without WordPress, a database or extraction. */
if ( PHP_SAPI !== 'cli' || ! isset( $argv[1] ) || ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "Usage: php (with zip) tests/package-archive.php <verification.zip>\n" ); exit( 1 );
}
$root = dirname( __DIR__ ); $passed = 0;
function archive_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); } $GLOBALS['passed']++;
}
$zip = new ZipArchive();
archive_check( true === $zip->open( $argv[1], ZipArchive::CHECKCONS ), 'Cannot open verification ZIP.' );
try {
	$expected = array_fill_keys( array( 'tapin-service-point-locator.php', 'uninstall.php', 'README.md', 'README.en.md', 'readme.txt', 'LICENSE' ), true );
	foreach ( array( 'src', 'assets', 'docs' ) as $directory ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) { $expected[str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) )] = true; }
		}
	}
	$entries = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		// Do not normalize: the previous Windows ZIP passed checks that hid backslashes.
		archive_check( is_string( $name ) && false === strpos( $name, '\\' ) && 0 === strpos( $name, 'tapin-service-point-locator/' ) && ! preg_match( '~(^|/)\.\.(/|$)~', $name ), 'Non-portable or invalid raw ZIP path.' );
		archive_check( ! isset( $entries[$name] ), 'Duplicate ZIP path.' );
		$relative = substr( $name, strlen( 'tapin-service-point-locator/' ) );
		archive_check( isset( $expected[$relative] ), 'Unexpected packaged runtime file.' );
		$forbidden = preg_match( '~(^|/)(\.env[^/]*|wp-config\.php|node_modules|tests|artifacts|\.git|[^/]*(credential|password|session)[^/]*)(/|$)|\.(sqlite3?|db|sql|pem|key|exe|log|zip)$~i', $relative );
		// This exact PHP class selects request-local credentials; it contains no stored credential data.
		archive_check( ! $forbidden || 'src/PriceCheck/LocationCredentials.php' === $relative, 'Prohibited data/development path in ZIP.' );
		$entries[$name] = $relative;
	}
	archive_check( count( $entries ) === count( $expected ), 'Missing runtime files.' );
	// Only the expected runtime files are read, after all prohibited-path checks pass.
	foreach ( $entries as $name => $relative ) {
		$bytes = $zip->getFromName( $name );
		archive_check( false !== $bytes && hash( 'sha256', $bytes ) === hash_file( 'sha256', $root . '/' . $relative ), 'Packaged runtime bytes differ.' );
	}
	echo "PASS ZIP inventory: {$zip->numFiles} files; {$passed} assertions, raw portable paths, exact runtime bytes, no WordPress installation claim.\n";
} finally { $zip->close(); }
