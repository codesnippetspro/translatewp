<?php

declare(strict_types=1);

use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [ 'flush_rewrite_rules' => TRANSLATEWP_VERSION ] );
$GLOBALS['wp_test_flush_count'] = 0;

Lyfecycle::maybe_flush_rewrite_rules();
assert_true( 1 === $GLOBALS['wp_test_flush_count'], 'First init with version mismatch must flush exactly once' );
assert_true(
	TRANSLATEWP_VERSION === get_option( 'translatewp_rewrite_version', '' ),
	'First flush must store the current version'
);
assert_true(
	'' === get_option( 'translatewp_flush_rewrite_rules', '' ),
	'First flush must clear the pending flush option'
);

Lyfecycle::maybe_flush_rewrite_rules();
assert_true( 1 === $GLOBALS['wp_test_flush_count'], 'Second init with no mismatch must not flush again' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LyfecycleTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "LyfecycleTest ok\n" );