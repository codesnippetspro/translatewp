<?php

declare(strict_types=1);

use TranslateWP\Includes\Loader;

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

$loader_class = new ReflectionClass( Loader::class );
$loader_instance = $loader_class->newInstanceWithoutConstructor();
$unwrap = Closure::bind( function ( $response, $server, $request ) {
    return $this->unwrap_error_response( $response, $server, $request );
}, $loader_instance, Loader::class );

$wp_error = new WP_Error( 'duplicate_segment', 'Segment already queued', [ 'status' => 409, 'retryAfter' => 30 ] );
$wrapped = new WP_REST_Response( $wp_error );

$tw_request = new WP_REST_Request( 'POST', '/translatewp/v1/translation/jobs/job-1/batches' );
$result = $unwrap( $wrapped, null, $tw_request );

assert_true( $result instanceof WP_Error, 'unwrap must return the exact WP_Error object' );
assert_true( $result === $wp_error, 'unwrap must return the SAME WP_Error instance (no reconstruction)' );
assert_true( 'duplicate_segment' === $result->get_error_code(), 'error code must be preserved' );
assert_true( 409 === (int) $result->get_error_data()['status'], 'HTTP status must be preserved' );
assert_true( 30 === (int) $result->get_error_data()['retryAfter'], 'retryAfter data field must be preserved' );

$ok_response = new WP_REST_Response( [ 'ok' => true ] );
$result = $unwrap( $ok_response, null, $tw_request );
assert_true( $result instanceof WP_REST_Response, 'success responses must pass through unchanged' );

$other_request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
$result = $unwrap( $wrapped, null, $other_request );
assert_true( $result instanceof WP_REST_Response, 'unrelated routes must pass through unchanged' );

$non_request = null;
$result = $unwrap( $wrapped, null, $non_request );
assert_true( $result instanceof WP_REST_Response, 'non-WP_REST_Request must pass through unchanged' );

if ( $failures > 0 ) {
	fwrite( STDERR, "RestErrorUnwrapTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "RestErrorUnwrapTest ok\n" );