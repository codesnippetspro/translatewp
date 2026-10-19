<?php

declare(strict_types=1);

use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.6' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset(
	[
		'site' => [
			'id'    => '00000000-0000-4000-8000-0000000000aa',
			'token' => 'secret-site-token',
		],
	]
);
$GLOBALS['wp_test_remote_posts'] = [];

$admin  = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$result = $admin->clear_cache( new WP_REST_Request( 'POST', '/translatewp/v1/admin/clear-cache' ) );

$clear_post = end( $GLOBALS['wp_test_remote_posts'] );
assert_true( is_array( $clear_post ) && '/v1/translation/cache/clear' === ( $clear_post['path'] ?? '' ), 'Clearing the cache must call the service' );
assert_true( is_array( $clear_post ) && 'Bearer secret-site-token' === ( $clear_post['headers']['Authorization'] ?? '' ), 'The clear request must carry the site credentials, got ' . wp_json_encode( $clear_post['headers'] ?? null ) );
assert_true( ! is_wp_error( $result ) && true === ( $result['success'] ?? false ), 'A clear the service accepts must succeed, got ' . wp_json_encode( is_wp_error( $result ) ? $result->get_error_message() : $result ) );
assert_true( ! is_wp_error( $result ) && 12 === ( $result['deleted'] ?? null ), 'The deleted count must be reported' );

wp_test_reset( [ 'site' => [ 'id' => '', 'token' => '' ] ] );
$result = $admin->clear_cache( new WP_REST_Request( 'POST', '/translatewp/v1/admin/clear-cache' ) );
assert_true( is_wp_error( $result ), 'An unconnected site cannot clear the cache' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ClearCacheTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "ClearCacheTest passed\n";
