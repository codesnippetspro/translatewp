<?php

declare(strict_types=1);

// Another plugin's Tailwind build resets every --tw-* variable on every
// element, unlayered, which beats the layered utilities of this plugin's
// admin app: blur, grayscale, shadows and transforms stop applying. On a
// TranslateWP screen those sheets are dequeued; everywhere else they stay.

use TranslateWP\Functionality\Admin\StyleConflicts;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function dequeued_on( string $screen_id ): array {
	wp_test_reset( [] );
	$GLOBALS['wp_test_dequeued_styles'] = [];
	$GLOBALS['wp_test_styles']          = (object) [
		'registered' => [
			'yoast-seo-admin-global' => (object) [ 'deps' => [ 'yoast-seo-tailwind', 'wp-components' ] ],
			'yoast-seo-tailwind'     => (object) [ 'deps' => [] ],
		],
	];
	$GLOBALS['wp_test_current_screen']  = (object) [ 'id' => $screen_id ];
	new StyleConflicts( 'translatewp', TRANSLATEWP_VERSION );
	do_action( 'admin_enqueue_scripts' );

	return $GLOBALS['wp_test_dequeued_styles'];
}

assert_true( in_array( 'yoast-seo-tailwind', dequeued_on( 'toplevel_page_translatewp' ), true ), 'the Yoast tailwind sheet is dequeued on the dashboard' );
assert_true( in_array( 'yoast-seo-tailwind', dequeued_on( 'translatewp_page_translatewp-settings' ), true ), 'and on a settings page' );
assert_true( in_array( 'yoast-seo-tailwind', dequeued_on( 'toplevel_page_translatewp-network' ), true ), 'and on the network page' );
assert_true( [ 'wp-components' ] === $GLOBALS['wp_test_styles']->registered['yoast-seo-admin-global']->deps, 'the dependency on the conflicting sheet is cut, else it would still print' );
assert_true( [] === dequeued_on( 'edit-post' ), 'elsewhere nothing is touched' );
assert_true( [ 'yoast-seo-tailwind', 'wp-components' ] === $GLOBALS['wp_test_styles']->registered['yoast-seo-admin-global']->deps, 'elsewhere the dependency stays' );
assert_true( [] === dequeued_on( 'toplevel_page_wpseo_dashboard' ), 'a Yoast screen keeps its own sheet' );

if ( $failures > 0 ) {
	fwrite( STDERR, "StyleConflictsTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "StyleConflictsTest: all assertions passed\n";
