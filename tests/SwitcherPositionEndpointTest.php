<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;
function check_position( bool $value, string $message ): void {
	global $failures;
	if ( ! $value ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [ 'languages' => [ 'switcher' => [ 'offset' => 24, 'text_color' => '#123456' ] ] ] );
$admin = new Admin( 'translatewp', '0.0.0-test' );
$routes = array_values( array_filter( $admin->define_endpoints(), static fn( $route ) => 'switcher-position' === $route->getPath() ) );
check_position( 1 === count( $routes ), 'A switcher-position route is registered' );
if ( 1 !== count( $routes ) ) {
	exit( 1 );
}
$route = $routes[0];
check_position( 'translatewp/v1/admin' === $route->getNamespace() && 'POST' === $route->getMethod(), 'The position route is an admin POST' );
$permission = $route->getPermissionCallback();
$save = $route->getConfig();
$position = [ 'anchor' => 'bottom-right', 'x' => 52, 'y' => 152 ];
$request = new WP_REST_Request( 'POST', '/translatewp/v1/admin/switcher-position', [ 'position' => $position, 'revision' => 0 ], [ 'X-WP-Nonce' => 'test-nonce' ] );
foreach ( [ [], [ 'read' ] ] as $caps ) {
	$GLOBALS['wp_test_caps'] = $caps;
	check_position( is_wp_error( $permission( $request ) ), 'Visitors and non-managers cannot save a position' );
}
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
foreach ( [ '', 'wrong-nonce' ] as $nonce ) {
	$denied = $permission( new WP_REST_Request( 'POST', '', [], [ 'X-WP-Nonce' => $nonce ] ) );
	check_position( is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'], 'A manager needs a valid nonce' );
}
check_position( true === $permission( $request ), 'A manager with a valid nonce is permitted' );
$result = $save( $request );
check_position( [ 'success' => true, 'position' => $position, 'revision' => 1 ] === $result, 'A saved position returns its new revision' );
check_position( $position === Options::get( 'languages.switcher.floating_position' ), 'The position persists in switcher settings' );
check_position( 24 === Options::get( 'languages.switcher.offset' ) && '#123456' === Options::get( 'languages.switcher.text_color' ), 'A drag preserves sibling settings' );
$again = $save( $request );
check_position( is_array( $again ) && 2 === $again['revision'] && $position === Options::get( 'languages.switcher.floating_position' ), 'A drag with an older revision still saves: the place the user let go of is the truth' );
$stale_form = new WP_REST_Request( 'POST', '', [ 'option' => 'languages.switcher', 'data' => [ 'offset' => 99 ], 'revision' => 0 ] );
check_position( is_wp_error( $admin->update_data( $stale_form ) ) && 24 === Options::get( 'languages.switcher.offset' ), 'A drag invalidates an older settings form' );

foreach ( [ null, [], true, [ 'anchor' => 'middle', 'x' => 2, 'y' => 3 ], [ 'anchor' => 'top-left', 'x' => [], 'y' => 3 ], [ 'anchor' => 'top-left', 'x' => true, 'y' => 3 ], [ 'anchor' => 'top-left', 'x' => INF, 'y' => 3 ], [ 'anchor' => 'top-left', 'x' => 3 ] ] as $invalid ) {
	$result = $save( new WP_REST_Request( 'POST', '', [ 'position' => $invalid, 'revision' => 1 ] ) );
	check_position( is_wp_error( $result ) && 400 === $result->get_error_data()['status'], 'Malformed positions are refused with 400' );
	check_position( $position === Options::get( 'languages.switcher.floating_position' ), 'Malformed positions write nothing' );
}
$result = $save( new WP_REST_Request( 'POST', '', [ 'position' => $position ] ) );
check_position( is_array( $result ) && ! empty( $result['success'] ), 'A position save needs no revision at all' );
$result = $save( new WP_REST_Request( 'POST', '', [ 'position' => $position, 'revision' => 1, 'option' => 'site.token', 'data' => [ 'offset' => 999 ] ] ) );
check_position( 4 === $result['revision'] && 24 === Options::get( 'languages.switcher.offset' ) && null === Options::get( 'site.token' ), 'The position route cannot write caller-selected settings' );


// --- A drop on a phone-width screen is its own position ---
$current = Options::get( 'languages.switcher.floating_position' );
$phone = [ 'anchor' => 'top-left', 'x' => 12, 'y' => 90 ];
$result = $save( new WP_REST_Request( 'POST', '/translatewp/v1/admin/switcher-position', [ 'position' => $phone, 'device' => 'mobile' ], [ 'X-WP-Nonce' => 'test-nonce' ] ) );
check_position( true === ( $result['success'] ?? false ), 'A phone position is saved' );
check_position( $phone === Options::get( 'languages.switcher.floating_position_mobile' ), 'A drop on a phone is kept as the phone position' );
check_position( $current === Options::get( 'languages.switcher.floating_position' ), 'A drop on a phone leaves the wide-screen position alone' );

if ( $failures ) {
	fwrite( STDERR, "SwitcherPositionEndpointTest: {$failures} failure(s)\n" );
	exit( 1 );
}
echo "SwitcherPositionEndpointTest: ok\n";
