<?php

declare(strict_types=1);

// A cache plugin that delays or defers JavaScript must leave the visitor
// runtime alone, or the switcher only appears after the first interaction.

use TranslateWP\Functionality\CacheScriptExclusions;

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

wp_test_reset( [] );
new CacheScriptExclusions( 'translatewp', TRANSLATEWP_VERSION );

$patterns = CacheScriptExclusions::patterns();
assert_true( in_array( '/plugins/translate/build/', $patterns, true ), 'the plugin build folder is excluded' );
assert_true( in_array( 'window.TranslateWP', $patterns, true ), 'the inline runtime config is excluded' );
foreach ( [ 'wp-includes/js/dist/i18n', 'wp-includes/js/dist/hooks', 'wp-includes/js/dist/api-fetch', 'wp-includes/js/dist/dom-ready', 'wp-includes/js/dist/element', 'wp-includes/js/dist/vendor/react', 'wp-includes/js/dist/vendor/wp-polyfill' ] as $dependency ) {
	assert_true( in_array( $dependency, $patterns, true ), "the runtime's dependency {$dependency} is excluded too" );
}

foreach ( [ 'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes', 'perfmatters_delay_js_exclusions', 'perfmatters_defer_js_exclusions' ] as $filter ) {
	$result = apply_filters( $filter, [ 'other-plugin.js' ] );
	assert_true( is_array( $result ) && in_array( 'other-plugin.js', $result, true ) && in_array( '/plugins/translate/build/', $result, true ), "{$filter} keeps what was there and adds the runtime, got " . wp_json_encode( $result ) );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "CacheScriptExclusionsTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "CacheScriptExclusionsTest: all assertions passed\n";
