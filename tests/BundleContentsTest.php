<?php
/**
 * The shipped zip carries every source the plugin loads at runtime. The block
 * is registered from blocks/language-switcher/block.json, so a zip built from
 * scripts/bundle.mjs without `blocks` shipped no block.json (UX audit 0.1).
 *
 * The test stages the zip the way bundle.mjs does, from its BUNDLE_PATHS
 * (build and vendor are skipped: they are generated, not sources), and reads
 * the entries back. When `yarn bundle` has produced the real zip, it is
 * checked as well.
 */

declare(strict_types=1);

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$root   = dirname( __DIR__ );
$script = (string) file_get_contents( $root . '/scripts/bundle.mjs' );
$paths  = [];
if ( 1 === preg_match( '/BUNDLE_PATHS = \[([^\]]*)\]/s', $script, $match ) ) {
	preg_match_all( "/'([^']+)'/", $match[1], $names );
	$paths = $names[1];
}
assert_true( [] !== $paths, 'scripts/bundle.mjs must name its BUNDLE_PATHS' );

$package = json_decode( (string) file_get_contents( $root . '/package.json' ), true );
$slug    = (string) ( $package['wpSlug'] ?? 'translatewp' );
$block   = $slug . '/blocks/language-switcher/block.json';

$zip_path = tempnam( sys_get_temp_dir(), 'twp-bundle-' ) . '.zip';
$zip      = new ZipArchive();
$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
foreach ( $paths as $path ) {
	if ( in_array( $path, [ 'build', 'vendor' ], true ) ) {
		continue;
	}
	$source = $root . '/' . $path;
	if ( is_file( $source ) ) {
		$zip->addFile( $source, $slug . '/' . $path );
		continue;
	}
	if ( ! is_dir( $source ) ) {
		continue;
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		$zip->addFile( $file->getPathname(), $slug . '/' . substr( $file->getPathname(), strlen( $root ) + 1 ) );
	}
}
$zip->close();

$zip->open( $zip_path );
assert_true( false !== $zip->locateName( $block ), 'The bundled zip must carry ' . $block );
$zip->close();
unlink( $zip_path );

$built = $root . '/' . $slug . '.' . (string) ( $package['version'] ?? '' ) . '.zip';
if ( is_file( $built ) ) {
	$zip->open( $built );
	assert_true( false !== $zip->locateName( $block ), basename( $built ) . ' must carry ' . $block );
	$zip->close();
}

// The real zip, when present, carries no repository housekeeping under
// vendor/, no Composer command-line entry points in vendor/bin/ and no
// nested vendor/vendor/.
if ( is_file( $built ) ) {
	$zip = new ZipArchive();
	$zip->open( $built );
	$stray = [];
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( 1 === preg_match( '#^' . preg_quote( $slug, '#' ) . '/vendor/(vendor/|bin/|.*/(\.github/|\.gitignore$|\.gitattributes$|\.editorconfig$))#', $name ) ) {
			$stray[] = $name;
		}
	}
	$zip->close();
	assert_true( [] === $stray, 'The bundled zip carries vendor housekeeping: ' . implode( ', ', array_slice( $stray, 0, 5 ) ) );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "BundleContentsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "BundleContentsTest: all assertions passed\n";
