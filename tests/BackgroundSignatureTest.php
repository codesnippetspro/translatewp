<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundSignature;
use TranslateWP\Functionality\Endpoints\Background;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_signature( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function signed_request( string $raw, string $key, ?string $signature = null ): WP_REST_Request {
	$body = json_decode( $raw, true );
	$signature = $signature ?? 'v2=' . hash_hmac( 'sha256', $body['ts'] . '.' . $body['nonce'] . '.' . hash( 'sha256', $raw ), hex2bin( $key ) );
	return new WP_REST_Request( 'POST', '/translatewp/v1/background/step', [], [ 'Content-Type' => 'application/json', 'X-TranslateWP-Signature' => $signature ], $raw );
}
$key = 'f0a9873b1fbe3b934e27535af79af167abbcb050ba23634c9f99211bbc9d1ec9';
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ] ] );
BackgroundSignature::observe( [ 'engineKey' => $key ] );
$raw = '{"ts":1763574300,"nonce":"0123456789abcdef0123456789abcdef"}';
$vector = signed_request( $raw, $key, 'v2=ff67ae76cfdf403358c5aa13d53fe4bbde6d48800a791fc469ea1eb54dbe732a' );
check_signature( BackgroundSignature::verify( $vector, 1763574300 ), 'The shared Node/PHP v2 key and raw-body test vector verifies' );
check_signature( ! BackgroundSignature::verify( $vector, 1763574300 ), 'The nonce cannot be replayed' );
delete_option( BackgroundSignature::NONCES_OPTION );
check_signature( ! BackgroundSignature::verify( $vector, 1763574421 ), 'A correctly signed stale timestamp is refused' );
check_signature( BackgroundSignature::verify( $vector, 1763574420 ), 'The freshness boundary is inclusive' );
delete_option( BackgroundSignature::NONCES_OPTION );
$endpoint = new Background();
$now_raw = wp_json_encode( [ 'ts' => time(), 'nonce' => str_repeat( 'a', 32 ) ] );
$valid = signed_request( $now_raw, $key );
$failures = [
	signed_request( $now_raw, str_repeat( 'cd', 32 ) ),
	signed_request( $now_raw, $key, 'v1=' . str_repeat( '0', 64 ) ),
	signed_request( $now_raw . ' ', $key, $valid->get_header( 'X-TranslateWP-Signature' ) ),
	signed_request( wp_json_encode( [ 'ts' => time() - 121, 'nonce' => str_repeat( 'b', 32 ) ] ), $key ),
	signed_request( wp_json_encode( [ 'ts' => time(), 'nonce' => str_repeat( 'B', 32 ) ] ), $key ),
	signed_request( wp_json_encode( [ 'ts' => time(), 'nonce' => str_repeat( 'c', 32 ), 'pause' => 'false' ] ), $key ),
];
$expected = null;
foreach ( $failures as $request ) {
	$error = $endpoint->step_permissions_check( $request );
	check_signature( is_wp_error( $error ), 'Every malformed or unauthorized signed request is refused' );
	$shape = [ $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ];
	$expected = $expected ?? $shape;
	check_signature( $shape === $expected && 401 === $shape[2]['status'], 'All authentication failures have the same fixed 401 body' );
}
check_signature( true === $endpoint->step_permissions_check( $valid ), 'Cookie-free site signature authorizes the endpoint' );
check_signature( is_wp_error( $endpoint->step_permissions_check( $valid ) ), 'The actual REST permission rejects a replay' );

delete_option( BackgroundSignature::KEY_OPTION );
delete_option( BackgroundSignature::REFRESH_OPTION );
$GLOBALS['wp_test_remote_get_urls'] = [];
$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'plan' => 'free', 'engineKey' => $key ] ) ];
$request = signed_request( wp_json_encode( [ 'ts' => time(), 'nonce' => str_repeat( 'd', 32 ) ] ), $key );
check_signature( is_wp_error( $endpoint->step_permissions_check( $request ) ), 'The first missing-key request still answers 401' );
check_signature( $key === get_option( BackgroundSignature::KEY_OPTION ), 'Authenticated eligibility supplies the independent key' );
$endpoint->step_permissions_check( signed_request( $now_raw, str_repeat( 'ef', 32 ) ) );
check_signature( 1 === count( $GLOBALS['wp_test_remote_get_urls'] ), 'Auth failures trigger at most one eligibility refresh per five minutes' );
check_signature( true === $endpoint->step_permissions_check( $request ), 'A subsequent signed request can use the refreshed key' );
check_signature( ! BackgroundSignature::verify( signed_request( $raw, $key, 'v1=c29adfa9944964d273afa1e4bacb85e521040b7c1969704e5a312c06c31137f8' ), 1763574300 ), 'The former token-derived v1 signature is never accepted' );
$state = \TranslateWP\Components\ProEligibility::cached();
check_signature( ! str_contains( wp_json_encode( $state ), $key ), 'Eligibility presentation never exposes the signing key' );
$admin = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', '1.1.2' );
$error = $admin->get_data( new WP_REST_Request( 'GET', '', [ 'option' => 'engine_key' ] ) );
check_signature( is_wp_error( $error ) && 403 === $error->get_error_data()['status'], 'The admin data endpoint cannot read the private key option' );
\TranslateWP\Components\SiteConnection::disconnect();
check_signature( false === get_option( BackgroundSignature::KEY_OPTION, false ), 'Disconnect deletes the private signing key' );
fwrite( STDOUT, "BackgroundSignatureTest: all assertions passed\n" );
