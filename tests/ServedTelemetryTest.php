<?php

declare(strict_types=1);

use TranslateWP\Components\ServedTelemetry;
use TranslateWP\Functionality\Endpoints\TranslationJobs;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';
spl_autoload_register( static function ( $class ) {
	$prefix = 'TranslateWP\\';
	if ( 0 === strpos( $class, $prefix ) ) {
		$path = dirname( __DIR__ ) . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) { require_once $path; }
	}
}, true, true );

$failures = 0;
function check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failures++; }
}
$event = [
	'version' => 1, 'kind' => 'switch', 'path' => 'ready', 'outcome' => 'complete',
	'firstAppliedMs' => 20, 'completedMs' => 60, 'elapsedMs' => 60,
	'sourceCount' => 2, 'appliedCount' => 2, 'failedGroups' => 0, 'pendingGroups' => 0,
	'sampleRate' => 0.03125,
];
$site = [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ];
wp_test_reset( $site );
$forwarded = [];
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$forwarded ) {
	$forwarded[] = [ 'url' => $url, 'args' => $args ];
	return [ 'response' => [ 'code' => 204 ], 'body' => '' ];
}, 10, 3 );
$request = static fn( array $body ): WP_REST_Request => new WP_REST_Request(
	'POST', '/translatewp/v1/translation/telemetry/served', [], [], wp_json_encode( $body )
);
$endpoint = new TranslationJobs( 'translatewp', 'test' );
check( method_exists( $endpoint, 'report_served_timing' ), 'Proxy handler must be wired into the existing endpoints' );
if ( ! class_exists( ServedTelemetry::class ) ) {
	check( false, 'Bounded served telemetry handler is missing' );
	exit( 1 );
}
$result = $endpoint->report_served_timing( $request( $event ) );
check( ! is_wp_error( $result ), 'Valid served observation is accepted' );
check( count( $forwarded ) === 1, 'Valid observation forwards exactly once through real SiteRequests' );
if ( $forwarded ) {
	check( '/v1/translation/telemetry/served' === parse_url( $forwarded[0]['url'], PHP_URL_PATH ), 'Destination is fixed' );
	check( 'Bearer site-token' === $forwarded[0]['args']['headers']['Authorization'], 'Server token authenticates forwarding' );
	check( $event === json_decode( $forwarded[0]['args']['body'], true ), 'Only bounded observation fields are forwarded' );
}
foreach ( [ 'siteId', 'url', 'content', 'token', 'destination', 'visitorId' ] as $field ) {
	$bad = $event;
	$bad[$field] = 'forged';
	check( is_wp_error( ServedTelemetry::record( $request( $bad ) ) ), 'Reject unexpected ' . $field );
}
$visible = $event;
$visible['firstVisibleAppliedMs'] = 30;
check( ! is_wp_error( ServedTelemetry::record( $request( $visible ) ) ), 'Additive viewport estimate is accepted' );
$visible['firstVisibleAppliedMs'] = 10;
check( is_wp_error( ServedTelemetry::record( $request( $visible ) ) ), 'Viewport observation cannot precede the actual DOM application' );
$bad = $event;
$bad['firstAppliedMs'] = 70;
check( is_wp_error( ServedTelemetry::record( $request( $bad ) ) ), 'First DOM cannot follow terminal elapsed time' );
$bad = $event;
$bad['completedMs'] = null;
check( is_wp_error( ServedTelemetry::record( $request( $bad ) ) ), 'Completion requires actual completion timing' );
$bad = $event;
$bad['elapsedMs'] = 180001;
check( is_wp_error( ServedTelemetry::record( $request( $bad ) ) ), 'Elapsed bound enforced' );
$oversize = new WP_REST_Request( 'POST', '', [], [], str_repeat( ' ', 2049 ) );
check( is_wp_error( ServedTelemetry::record( $oversize ) ), 'Oversized body rejected before decoding' );
for ( $i = 0; $i < 25; $i++ ) { $result = ServedTelemetry::record( $request( $event ) ); }
check( is_wp_error( $result ), 'Public forwarding is bounded per site and client' );
check( count( $forwarded ) <= 12, 'Rate rejection never forwards upstream' );
$before = count( $GLOBALS['wp_test_transients'] );
$site_key = \TranslateWP\Components\TranslationProxyStorage::rate_limit_key(
	'served_telemetry_site', $site['site']['id'], 'site', 60
);
set_transient( $site_key, 120, 60 );
set_transient( \TranslateWP\Components\TranslationProxyStorage::rate_limit_reset_key( $site_key ), time() + 60, 60 );
$before = count( $GLOBALS['wp_test_transients'] );
$requests_before = count( $forwarded );
for ( $i = 1; $i <= 25; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
	$result = ServedTelemetry::record( $request( $event ) );
	check( is_wp_error( $result ), 'Exhausted site cap rejects distinct clients' );
}
check( count( $GLOBALS['wp_test_transients'] ) === $before, 'Exhausted site cap creates no per-client keys' );
check( count( $forwarded ) === $requests_before, 'Exhausted site cap never forwards' );
wp_test_reset( [ 'site' => [ 'id' => $site['site']['id'], 'token' => '' ] ] );
check( is_wp_error( ServedTelemetry::record( $request( $event ) ) ), 'Disconnected site does not forward' );
if ( $failures ) { exit( 1 ); }
echo "ServedTelemetryTest: all assertions passed\n";
