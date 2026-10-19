<?php

declare(strict_types=1);

/**
 * The plugin version is stated in four places and they must agree: the header
 * WordPress reads, the TRANSLATEWP_VERSION constant that cache-busts every
 * enqueued asset and keys migrations, package.json for the build, and the
 * readme stable tag wp.org serves. A mismatch ships stale assets to visitors.
 */

$options = getopt( '', [ 'root:', 'expected:', 'package' ] );
// A full version: three numbers, and for a beta a pre-release part such as -beta.1.
$version = '[0-9]+\\.[0-9]+\\.[0-9]+(?:-[0-9A-Za-z]+(?:\\.[0-9A-Za-z]+)*)?';
$root    = (string) ( $options['root'] ?? dirname( __DIR__ ) );
$fail = static function ( string $m ): void { fwrite( STDERR, "VersionConsistencyTest: {$m}\n" ); };

$main = file_get_contents( $root . '/translatewp.php' );
preg_match( '/^\s*\*\s*Version:\s*(' . $version . ')[ \t\r]*$/m', $main, $h );
preg_match( "/define\(\s*'TRANSLATEWP_VERSION',\s*'(" . $version . ")'/", $main, $c );
$header   = $h[1] ?? '';
$constant = $c[1] ?? '';

$pkg = '';
if ( ! isset( $options['package'] ) ) {
	$package = json_decode( (string) file_get_contents( $root . '/package.json' ), true );
	$pkg     = is_array( $package ) ? (string) ( $package['version'] ?? '' ) : '';
}

preg_match( '/^\s*Stable tag:\s*(' . $version . ')[ \t\r]*$/mi', (string) file_get_contents( $root . '/readme.txt' ), $s );
$stable = $s[1] ?? '';

$versions = [ 'plugin header' => $header, 'TRANSLATEWP_VERSION' => $constant, 'package.json' => $pkg, 'readme stable tag' => $stable ];

if ( isset( $options['package'] ) ) {
	unset( $versions['package.json'] );
}

$ok = true;
foreach ( $versions as $where => $value ) {
	if ( '' === $value ) { $fail( "could not read the version from {$where}." ); $ok = false; }
}
if ( $ok && count( array_unique( $versions ) ) !== 1 ) {
	$fail( 'the version strings disagree.' );
	$ok = false;
}
if ( isset( $options['expected'] ) && $header !== $options['expected'] ) {
	$fail( 'the plugin version does not match the requested release.' );
	$ok = false;
}
if ( ! $ok ) {
	exit( 1 );
}
echo "VersionConsistencyTest: all version strings agree on {$header}\n";
