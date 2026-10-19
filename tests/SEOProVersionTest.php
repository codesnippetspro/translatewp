<?php

declare(strict_types=1);

use TranslateWP\Functionality\SEO;

// A constant cannot be undefined inside one process, so each addon version
// runs in its own child process of this same script.
if ( isset( $argv[1] ) ) {
	require dirname( __DIR__ ) . '/vendor/autoload.php';
	require_once __DIR__ . '/wp-test-stubs.php';
	require_once __DIR__ . '/seo-test-stubs.php';

	if ( 'none' !== $argv[1] ) {
		define( 'TRANSLATEWP_PRO_VERSION', $argv[1] );
	}

	wp_test_reset( [] );
	$GLOBALS['wp_test_removed_actions'] = [];
	$seo = new SEO( 'translatewp', '0.10.20' );
	$seo->register_language_tags();

	echo wp_json_encode(
		[
			'hreflang'  => seo_test_registered( 'wp_head', 'add_hreflang_tags' ),
			'canonical' => seo_test_registered( 'wp_head', 'add_canonical_tag' ),
			'removed'   => count( $GLOBALS['wp_test_removed_actions'] ),
		]
	);
	exit( 0 );
}

$failures = 0;

$cases = [
	'none'   => true,
	'0.3.37' => false,
	'0.3.9'  => false,
	'0.3.38' => true,
	'0.4.0'  => true,
];

foreach ( $cases as $version => $free_prints ) {
	$json   = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $version ) );
	$result = json_decode( (string) $json, true );
	if ( ! is_array( $result ) ) {
		fwrite( STDERR, "Addon {$version}: the child process failed: {$json}\n" );
		$failures++;
		continue;
	}

	$expected = $free_prints
		? [ 'hreflang' => true, 'canonical' => true, 'removed' => 1 ]
		: [ 'hreflang' => false, 'canonical' => false, 'removed' => 0 ];
	if ( $expected !== $result ) {
		fwrite( STDERR, "Addon {$version}: expected " . json_encode( $expected ) . ', got ' . json_encode( $result ) . "\n" );
		$failures++;
	}
}

if ( $failures > 0 ) {
	fwrite( STDERR, "SEOProVersionTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SEOProVersionTest: all assertions passed\n";
