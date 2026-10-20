<?php

declare(strict_types=1);

// The connect return address must reach the plugin's route on every
// permalink setting: without a structure there are no rewrite rules, so the
// route is only reachable through its query variable.

use TranslateWP\Components\SiteConnection;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_same( $expected, $actual, string $message ): void {
	global $failures;
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		$failures++;
	}
}

wp_test_reset( [] );
$home = rtrim( home_url( '/' ), '/' );

$GLOBALS['wp_test_raw_options']['permalink_structure'] = '';
assert_same( $home . '/?route_name=translatewp-connect-return', SiteConnection::return_url(), 'plain permalinks return through the route query variable' );
assert_same( $home . '/?route_name=translatewp-connect-network-return', SiteConnection::network_return_url(), 'plain permalinks network return through the route query variable' );
assert_same( SiteConnection::connect_host_from_return_url( home_url( '/' ) ), SiteConnection::connect_host_from_return_url( SiteConnection::return_url() ), 'the query form keeps the site host the portal checks' );

$GLOBALS['wp_test_raw_options']['permalink_structure'] = '/%postname%/';
assert_same( $home . '/translatewp-connect/return/', SiteConnection::return_url(), 'pretty permalinks keep the path the rewrite rule matches' );
assert_same( $home . '/translatewp-connect/network-return/', SiteConnection::network_return_url(), 'pretty permalinks keep the network path' );

unset( $GLOBALS['wp_test_raw_options']['permalink_structure'] );
assert_same( $home . '/translatewp-connect/return/', SiteConnection::return_url(), 'an unread structure keeps the path form' );

$routes = ( new TranslateWP\Functionality\Connect( 'translatewp', '0.0.0-test' ) )->register_routes( [] );
$names  = array_map( static fn( $route ) => $route->getName(), $routes );
assert_same( [ SiteConnection::RETURN_ROUTE_NAME, SiteConnection::NETWORK_RETURN_ROUTE_NAME ], $names, 'the registered routes are the ones the query form names' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ConnectReturnPermalinkTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "ConnectReturnPermalinkTest ok\n" );
