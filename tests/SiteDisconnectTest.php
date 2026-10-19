<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Connect;

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

function assert_disconnect_cleared_local(): void {
	assert_true(
		'' === Options::get( 'site.token', '' ),
		'Local token must be cleared after disconnect'
	);
	assert_true(
		'' === Options::get( 'site.id', '' ),
		'Local site id must be cleared after disconnect'
	);
	assert_true(
		'' === Options::get( 'site.account_label', '' ),
		'Local account label must be cleared after disconnect'
	);
}

function assert_disconnect_kept_local(): void {
	assert_true(
		'site-token' === Options::get( 'site.token', '' ),
		'Local token must survive a failed disconnect'
	);
	assert_true(
		'00000000-0000-4000-8000-000000000001' === Options::get( 'site.id', '' ),
		'Local site id must survive a failed disconnect'
	);
	assert_true(
		'owner@example.com' === Options::get( 'site.account_label', '' ),
		'Local account label must survive a failed disconnect'
	);
}

function connected_options(): array {
	return [
		'site' => [
			'id'            => '00000000-0000-4000-8000-000000000001',
			'token'         => 'site-token',
			'home_url'      => 'https://example.com',
			'account_label' => 'owner@example.com',
		],
	];
}

$connect = new Connect( 'translatewp', '0.0.0-test' );

wp_test_reset( connected_options() );
$GLOBALS['wp_test_remote_disconnect_posts'] = [];
$result = $connect->disconnect( null );
assert_true( is_array( $result ) && true === $result['success'], '2xx disconnect must return success' );
assert_true( 1 === count( $GLOBALS['wp_test_remote_disconnect_posts'] ), '2xx disconnect must POST /v1/site/disconnect' );
assert_true(
	'/v1/site/disconnect' === $GLOBALS['wp_test_remote_disconnect_posts'][0]['path'],
	'Disconnect must call the remote disconnect path'
);
assert_disconnect_cleared_local();

wp_test_reset( connected_options() );
$GLOBALS['wp_test_remote_disconnect_posts']   = [];
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'token already revoked' ] ),
];
$result = $connect->disconnect( null );
assert_true( is_array( $result ) && true === $result['success'], '401 disconnect must clear local and return success' );
assert_true( 1 === count( $GLOBALS['wp_test_remote_disconnect_posts'] ), '401 disconnect must still POST' );
assert_disconnect_cleared_local();
unset( $GLOBALS['wp_test_disconnect_response'] );

wp_test_reset( connected_options() );
$GLOBALS['wp_test_remote_disconnect_posts']   = [];
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 500 ],
	'body'     => wp_json_encode( [ 'error' => 'server error' ] ),
];
$result = $connect->disconnect( null );
assert_true( $result instanceof WP_Error, '5xx disconnect must return WP_Error' );
assert_true( 1 === count( $GLOBALS['wp_test_remote_disconnect_posts'] ), '5xx disconnect must still POST' );
assert_disconnect_kept_local();
unset( $GLOBALS['wp_test_disconnect_response'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "SiteDisconnectTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "SiteDisconnectTest ok\n" );