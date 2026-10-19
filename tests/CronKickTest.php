<?php

declare(strict_types=1);

use TranslateWP\Components\CronKick;

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

// --- Overdue event: handler runs inline exactly once ---
wp_test_reset( [] );
$GLOBALS['wp_test_scheduled']['test_hook'] = time() - 120;
$runs = 0;
$kicked = CronKick::maybe_run_due( 'test_hook', static function () use ( &$runs ): void {
	$runs++;
} );
assert_true( true === $kicked, 'Overdue event must be kicked inline' );
assert_true( 1 === $runs, 'Handler must run exactly once' );
assert_true( false === wp_next_scheduled( 'test_hook' ), 'Kicked event must be unscheduled' );

// --- Lock prevents a second inline run in the same window ---
$GLOBALS['wp_test_scheduled']['test_hook'] = time() - 120;
$kicked = CronKick::maybe_run_due( 'test_hook', static function () use ( &$runs ): void {
	$runs++;
} );
assert_true( false === $kicked, 'Lock must prevent a concurrent double-run' );
assert_true( 1 === $runs, 'Handler must not run again while locked' );

// --- Not overdue: untouched ---
wp_test_reset( [] );
$GLOBALS['wp_test_scheduled']['test_hook'] = time() + 30;
$kicked = CronKick::maybe_run_due( 'test_hook', static function () use ( &$runs ): void {
	$runs++;
} );
assert_true( false === $kicked, 'Not-overdue event must not be kicked' );
assert_true( false !== wp_next_scheduled( 'test_hook' ), 'Not-overdue event must stay scheduled' );

// --- Nothing scheduled: untouched ---
wp_test_reset( [] );
$kicked = CronKick::maybe_run_due( 'test_hook', static function () use ( &$runs ): void {
	$runs++;
} );
assert_true( false === $kicked, 'Unscheduled hook must not be kicked' );

if ( $failures > 0 ) {
	fwrite( STDERR, "CronKickTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "CronKickTest: all assertions passed\n";
