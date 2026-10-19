<?php

declare(strict_types=1);

use TranslateWP\Functionality\RestNoCache;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function nocache_actions(): array {
	return array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'litespeed_control_set_nocache' === $action['tag'] ) );
}

// --- A plugin route response leaves with no-cache headers and the LiteSpeed signal ---
wp_test_reset( [] );
$GLOBALS['wp_test_filters'] = [];
new RestNoCache( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_actions_fired'] = [];
$request  = new WP_REST_Request( 'GET', '/translatewp/v1/connect/status' );
$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( [ 'connected' => true ] ), null, $request );
$headers  = $response->get_headers();
assert_true( isset( $headers['Cache-Control'] ) && false !== strpos( $headers['Cache-Control'], 'no-store' ) && false !== strpos( $headers['Cache-Control'], 'private' ), 'A plugin route answers with a no-store private Cache-Control' );
assert_true( 'no-cache' === ( $headers['X-LiteSpeed-Cache-Control'] ?? '' ), 'A plugin route tells the LiteSpeed server not to cache' );
assert_true( 1 === count( nocache_actions() ), 'A plugin route fires the LiteSpeed Cache plugin no-cache action once' );
assert_true( [ 'connected' => true ] === $response->get_data(), 'The response body is untouched' );

// --- The namespace root and a nested route count; a look-alike prefix does not ---
foreach ( [ '/translatewp/v1' => true, '/translatewp/v1/indexing/status' => true, '/translatewp/v10/x' => false, '/wp/v2/posts' => false, '/translatewp/v1x' => false ] as $route => $expected ) {
	assert_true( $expected === RestNoCache::is_plugin_route( new WP_REST_Request( 'GET', $route ) ), 'Route ' . $route . ' plugin match must be ' . ( $expected ? 'true' : 'false' ) );
}

// --- A core route is left alone ---
$GLOBALS['wp_test_actions_fired'] = [];
$core = apply_filters( 'rest_post_dispatch', new WP_REST_Response( [ 'id' => 1 ] ), null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
assert_true( [] === $core->get_headers(), 'A core route gets no headers from the plugin' );
assert_true( 0 === count( nocache_actions() ), 'A core route fires no LiteSpeed action' );

// --- A response that is not a response object (an error) passes through untouched ---
$error = new WP_Error( 'x', 'y' );
assert_true( $error === apply_filters( 'rest_post_dispatch', $error, null, $request ), 'An error passes through unchanged' );

if ( $failures > 0 ) {
	fwrite( STDERR, "RestNoCacheTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "RestNoCacheTest ok\n" );
