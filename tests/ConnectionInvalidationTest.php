<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Components\Sites;
use TranslateWP\Functionality\Endpoints\Management;

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

function make_request( string $method, array $params = [] ): WP_REST_Request {
	return new WP_REST_Request( $method, '/translatewp/v1/management/reconnect', $params, [], '' );
}

$connected_options = [
	'site' => [
		'id'                => '00000000-0000-4000-8000-000000000001',
		'token'             => 'secret-site-token',
		'home_url'          => 'https://example.com',
		'account_label'     => 'ops@translatewp.ai',
		'connection_status' => 'connected',
	],
];

// --- A changed address only arms a re-check; it never invalidates locally ---
wp_test_reset( $connected_options );
Sites::detect_home_url_drift();
assert_true( 'connected' === SiteConnection::connection_status(), 'Matching address must not arm a re-check' );
assert_true( false === SiteConnection::needs_reverify(), 'Matching address must not arm a re-check' );

$GLOBALS['wp_test_home_url'] = 'https://neue-adresse.example.net';
Sites::detect_home_url_drift();
assert_true( 'connected' === SiteConnection::connection_status(), 'A changed address must not invalidate the connection' );
assert_true( true === SiteConnection::is_connected(), 'A changed address must stay connected so the runtime keeps serving' );
assert_true( true === SiteConnection::needs_reverify(), 'A changed address must arm the status re-check' );
assert_true( 'secret-site-token' === \TranslateWP\Components\Options::get( 'site.token', '' ), 'Credentials must be preserved across an address change' );

// --- A scheme, www, or trailing-slash difference is never a move ---
foreach ( [ 'http://example.com', 'https://www.example.com', 'https://example.com/' ] as $variant ) {
	wp_test_reset( $connected_options );
	$GLOBALS['wp_test_home_url'] = $variant;
	Sites::detect_home_url_drift();
	assert_true( false === SiteConnection::needs_reverify(), "A cosmetic address difference must not arm a re-check: {$variant}" );
	assert_true( 'connected' === SiteConnection::connection_status(), "A cosmetic address difference must not change state: {$variant}" );
}

// --- Invalidated: all token use stops ---
wp_test_reset( $connected_options );
SiteConnection::invalidate_connection();
assert_true( '' === SiteRequests::bearer_token(), 'Invalidated connection must not expose the bearer token' );
$result = SiteRequests::get( '/v1/health' );
assert_true( is_wp_error( $result ) && 'site_not_connected' === $result->get_error_code(), 'Invalidated connection must not call the API' );
assert_true( [] === $GLOBALS['wp_test_remote_posts'], 'No remote call may be made while invalidated' );

// --- A single 401 on a background call arms a re-check, it never invalidates ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'Unauthorized' ] ),
];
$result = SiteRequests::get( '/v1/health' );
assert_true( is_wp_error( $result ), '401 must surface as an error' );
assert_true( 'connected' === SiteConnection::connection_status(), 'A single 401 must not invalidate the connection' );
assert_true( true === SiteConnection::needs_reverify(), 'A single 401 must arm the status re-check' );
assert_true( 'secret-site-token' === \TranslateWP\Components\Options::get( 'site.token', '' ), '401 must preserve credentials' );

// --- Other error statuses neither invalidate nor arm a re-check ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 503 ],
	'body'     => wp_json_encode( [ 'error' => 'Unavailable' ] ),
];
$result = SiteRequests::get( '/v1/health' );
assert_true( is_wp_error( $result ), '503 must surface as an error' );
assert_true( 'connected' === SiteConnection::connection_status(), '503 must not invalidate the connection' );
assert_true( false === SiteConnection::needs_reverify(), '503 must not arm the status re-check' );

// --- Reconnect routes: relink keeps credentials, clone clears them ---
wp_test_reset( $connected_options );
SiteConnection::invalidate_connection();
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$relink = $management->reconnect( make_request( 'POST', [ 'path' => 'relink' ] ) );
assert_true( true === ( $relink['success'] ?? false ), 'Relink must succeed' );
assert_true( false === \TranslateWP\Components\Options::get( 'setup.ended', true ), 'Relink must re-arm the wizard' );
assert_true( 'secret-site-token' === \TranslateWP\Components\Options::get( 'site.token', '' ), 'Relink must keep credentials' );

$clone = $management->reconnect( make_request( 'POST', [ 'path' => 'clone' ] ) );
assert_true( true === ( $clone['success'] ?? false ), 'Clone reconnect must succeed' );
assert_true( '' === \TranslateWP\Components\Options::get( 'site.token', '' ), 'Clone reconnect must clear credentials' );
assert_true( false === \TranslateWP\Components\Options::get( 'setup.ended', true ), 'Clone reconnect must re-arm the wizard' );

$invalid = $management->reconnect( make_request( 'POST', [ 'path' => 'nonsense' ] ) );
assert_true( is_wp_error( $invalid ), 'Unknown reconnect path must be rejected' );

foreach ( [ '', 'connected', 'invalid_state' ] as $notice ) {
	wp_test_reset( $connected_options );
	SiteConnection::invalidate_connection();
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
	$_GET = [ 'page' => 'translatewp', SiteConnection::NOTICE_QUERY_ARG => $notice ];
	ob_start();
	SiteConnection::render_admin_notices();
	$html = ob_get_clean();
	assert_true( false !== strpos( $html, 'The service no longer recognises' ), 'Invalidated credentials have a persistent explanation' );
	assert_true( false !== strpos( $html, 'Reconnect this site' ) && false !== strpos( $html, 'Connect as a new site' ), 'Invalidated credentials offer both recovery actions' );
	assert_true( false !== strpos( $html, 'translatewp_connect=reconnect' ) && false !== strpos( $html, 'translatewp_connect=new' ), 'Recovery links use the existing distinct connect intents' );
	assert_true( 2 === substr_count( $html, '_wpnonce=nonce-translatewp_connect' ), 'Both recovery links carry nonces' );
}
foreach ( [ [ [], 'translatewp' ], [ [ 'read' ], 'translatewp' ], [ [ 'manage_options' ], 'unrelated' ] ] as $context ) {
	$GLOBALS['wp_test_caps'] = $context[0];
	$_GET = [ 'page' => $context[1] ];
	ob_start();
	SiteConnection::render_admin_notices();
	assert_true( '' === ob_get_clean(), 'Recovery notices require a manager on the plugin page' );
}
$_GET = [];

if ( $failures > 0 ) {
	fwrite( STDERR, "ConnectionInvalidationTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ConnectionInvalidationTest: all assertions passed\n";
