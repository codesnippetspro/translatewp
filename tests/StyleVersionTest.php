<?php

declare(strict_types=1);

use TranslateWP\Entities\Styles\Shared;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$build_root = sys_get_temp_dir() . '/twp-style-version-' . uniqid();
mkdir( $build_root . '/build', 0777, true );

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0-beta.1' );
}
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
}
if ( ! defined( 'TRANSLATEWP_PATH' ) ) {
	define( 'TRANSLATEWP_PATH', $build_root . '/' );
}
if ( ! defined( 'TRANSLATEWP_URL' ) ) {
	define( 'TRANSLATEWP_URL', 'https://example.com/wp-content/plugins/translatewp/' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [] );
$style = new Shared( 'translatewp', TRANSLATEWP_VERSION );

// --- Without a built file the plugin version is used ---
assert_true( TRANSLATEWP_VERSION === $style->version(), 'a missing built file falls back to the plugin version' );

// --- A built file is versioned by its modification time, so a rebuild changes the version ---
$file = $build_root . '/build/style-shared.css';
file_put_contents( $file, '.a{}' );
touch( $file, 1700000000 );
clearstatcache();
assert_true( '1700000000' === $style->version(), 'the built file versions the stylesheet by its modification time, got ' . $style->version() );

touch( $file, 1700000600 );
clearstatcache();
assert_true( '1700000600' === $style->version(), 'a rebuilt file changes the version, got ' . $style->version() );

unlink( $file );
rmdir( $build_root . '/build' );
rmdir( $build_root );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "StyleVersionTest: all assertions passed\n";
