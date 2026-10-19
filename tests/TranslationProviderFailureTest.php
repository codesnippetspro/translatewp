<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationProviderFailure;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_provider_failure( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- a typed, key-free byok code from the API surfaces as the reason ---
foreach ( [ 'pro_required', 'byok_invalid_key', 'byok_model_unsupported', 'byok_provider_error' ] as $code ) {
	$error  = new WP_Error( 'remote_error', 'upstream message', [ 'status' => 502, 'payload' => [ 'error' => $code ] ] );
	$reason = TranslationProviderFailure::reason( $error, 'remote_error' );
	assert_provider_failure( $code === $reason, "the $code answer must surface as the failure reason, got $reason" );
}

// --- a transport failure with no payload keeps the fallback code ---
$plain = new WP_Error( 'remote_error', 'connection reset', [ 'status' => 502 ] );
assert_provider_failure(
	'remote_error' === TranslationProviderFailure::reason( $plain, 'remote_error' ),
	'a payload-less failure must keep the transport code'
);

// --- an unrecognised upstream code does not pass through to the admin screen ---
$unknown = new WP_Error( 'remote_error', 'boom', [ 'status' => 500, 'payload' => [ 'error' => 'internal_detail' ] ] );
assert_provider_failure(
	'job_create_failed' === TranslationProviderFailure::reason( $unknown, 'job_create_failed' ),
	'an unrecognised upstream code must not pass through'
);

// --- a key smuggled into the payload never becomes the reason ---
$leaky  = new WP_Error(
	'remote_error',
	'boom',
	[ 'status' => 502, 'payload' => [ 'error' => 'byok_provider_error', 'detail' => 'sk-LEAK-123456' ] ]
);
$reason = TranslationProviderFailure::reason( $leaky, 'remote_error' );
assert_provider_failure( 'byok_provider_error' === $reason, 'a safe code must still surface alongside leaky payload detail' );
assert_provider_failure( false === strpos( $reason, 'sk-LEAK-123456' ), 'the reason must never contain a key from the payload' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationProviderFailureTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslationProviderFailureTest: all assertions passed\n";
