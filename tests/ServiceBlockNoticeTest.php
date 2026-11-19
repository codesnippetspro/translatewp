<?php

declare(strict_types=1);

use TranslateWP\Components\RemoteApi;
use TranslateWP\Components\ServiceBlockNotice;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;
function check_block( bool $value, string $message ): void {
	global $failures;
	if ( ! $value ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function render_block_notice(): string {
	ob_start();
	ServiceBlockNotice::render_admin_notice();
	return (string) ob_get_clean();
}

wp_test_reset( [] );
$origin = RemoteApi::base_url();
$admin  = new Admin( 'translatewp', '0.0.0-test' );
$routes = array_values( array_filter( $admin->define_endpoints(), static fn( $route ) => 'service-blocked' === $route->getPath() ) );
check_block( 1 === count( $routes ), 'A service-blocked route is registered' );
if ( 1 !== count( $routes ) ) {
	exit( 1 );
}
$route = $routes[0];
check_block( 'translatewp/v1/admin' === $route->getNamespace() && 'POST' === $route->getMethod(), 'The report route is an admin POST' );
$permission = $route->getPermissionCallback();
$report     = $route->getConfig();
$request    = static fn( array $params, string $nonce = 'test-nonce' ) => new WP_REST_Request( 'POST', '/translatewp/v1/admin/service-blocked', $params, [ 'X-WP-Nonce' => $nonce ] );
$valid      = [
	'origin' => $origin,
	'path'   => '/shop/?email=visitor@example.com#top',
];

// --- only an administrator with a valid nonce may report ---
foreach ( [ [], [ 'read' ], [ 'edit_posts', \TranslateWP\Components\Access::SETTINGS ] ] as $caps ) {
	$GLOBALS['wp_test_caps'] = $caps;
	check_block( is_wp_error( $permission( $request( $valid ) ) ), 'Visitors and users who do not administer the plugin cannot report' );
}
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
foreach ( [ '', 'wrong-nonce' ] as $nonce ) {
	$denied = $permission( $request( $valid, $nonce ) );
	check_block( is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'], 'An administrator needs a valid nonce' );
}
check_block( true === $permission( $request( $valid ) ), 'An administrator with a valid nonce may report' );

// --- the payload is narrowed to the service origin and a page path ---
foreach ( [ '', 'https://cdn.example.com', $origin . '.example.com', 'http://' . wp_parse_url( $origin, PHP_URL_HOST ) ] as $other ) {
	$refused = $report( $request( [ 'origin' => $other, 'path' => '/' ] ) );
	check_block( is_wp_error( $refused ) && 400 === $refused->get_error_data()['status'], 'A report for another origin is refused: ' . $other );
}
check_block( null === get_option( ServiceBlockNotice::OPTION, null ), 'A refused report stores nothing' );

$result = $report( $request( $valid + [ 'time' => 1, 'note' => '<script>' ] ) );
check_block( [ 'recorded' => true ] === $result, 'A report for the service origin is recorded' );
$stored = get_option( ServiceBlockNotice::OPTION );
check_block( [ 'time', 'origin', 'path' ] === array_keys( $stored ), 'Only the time, the origin and the path are stored' );
check_block( '/shop/' === $stored['path'] && $origin === $stored['origin'], 'The page is kept as its path alone' );
check_block( abs( time() - $stored['time'] ) < 5, 'The stored time is the server time, not one the caller sends' );

ServiceBlockNotice::clear();
$report( $request( [ 'origin' => $origin, 'path' => 'javascript:alert(1)' ] ) );
check_block( '/' === get_option( ServiceBlockNotice::OPTION )['path'], 'A page that is not a path is stored as the root' );
ServiceBlockNotice::clear();
$report( $request( [ 'origin' => $origin, 'path' => '/' . str_repeat( 'a', 500 ) ] ) );
check_block( 200 === strlen( get_option( ServiceBlockNotice::OPTION )['path'] ), 'A long path is cut to a bounded length' );

// --- one write an hour ---
update_option( ServiceBlockNotice::OPTION, [ 'time' => time() - 600, 'origin' => $origin, 'path' => '/first/' ] );
check_block( [ 'recorded' => false ] === $report( $request( [ 'origin' => $origin, 'path' => '/second/' ] ) ), 'A second report within the hour writes nothing' );
check_block( '/first/' === get_option( ServiceBlockNotice::OPTION )['path'], 'The first report of the hour is kept' );
update_option( ServiceBlockNotice::OPTION, [ 'time' => time() - 3601, 'origin' => $origin, 'path' => '/first/' ] );
check_block( [ 'recorded' => true ] === $report( $request( [ 'origin' => $origin, 'path' => '/second/' ] ) ), 'A report after the hour is written' );
check_block( '/second/' === get_option( ServiceBlockNotice::OPTION )['path'], 'The newer report replaces the older one' );

// --- the notice: error level, on TranslateWP screens, for administrators ---
$_GET   = [ 'page' => 'translatewp-settings' ];
$notice = render_block_notice();
check_block( str_contains( $notice, '<div class="notice notice-error">' ), 'The notice is an error-level WordPress notice, got ' . $notice );
check_block( \TranslateWP\Components\AdminNotices::has_action_required(), 'The notice marks action required, so promotional TranslateWP notices step aside' );
check_block( str_contains( $notice, '<p><strong>Your site blocks TranslateWP.</strong> A security header on this site (Content-Security-Policy) blocks ' . $origin . ', so visitors see the original language. Add ' . $origin . ' to connect-src where the header is set (a security plugin, a code snippet or the server configuration).</p>' ), 'The notice names the configured service origin in the agreed words' );
check_block( str_contains( $notice, 'name="action" value="' . ServiceBlockNotice::CHECK_AGAIN_ACTION . '"' ) && str_contains( $notice, '<button type="submit" class="button">Check again</button>' ), 'The notice offers Check again' );

add_filter( 'translatewp_api_base_url', static fn() => 'https://api.translatewp.ai:8443/v2/' );
check_block( str_contains( render_block_notice(), 'blocks https://api.translatewp.ai:8443, so' ), 'The notice follows the configured origin, without its path' );
$GLOBALS['wp_test_filters'] = [];

// A base with an explicit default port is the origin the browser reports.
$saved = get_option( ServiceBlockNotice::OPTION );
foreach ( [ 'https://API.translatewp.ai:443' => 'https://api.translatewp.ai', 'http://api.translatewp.ai:80/' => 'http://api.translatewp.ai' ] as $base => $browser_origin ) {
	add_filter( 'translatewp_api_base_url', static fn() => $base );
	ServiceBlockNotice::clear();
	check_block( [ 'recorded' => true ] === ServiceBlockNotice::record( $browser_origin, '/' ), 'A report from ' . $browser_origin . ' is accepted for the base ' . $base );
	$GLOBALS['wp_test_filters'] = [];
}
update_option( ServiceBlockNotice::OPTION, $saved );

$_GET = [ 'page' => 'plugins' ];
check_block( '' === render_block_notice(), 'The notice stays off screens other than TranslateWP ones' );
$_GET = [];
check_block( '' === render_block_notice(), 'The notice stays off a wp-admin screen without a page' );
$_GET                    = [ 'page' => 'translatewp' ];
$GLOBALS['wp_test_caps'] = [ 'edit_posts' ];
check_block( '' === render_block_notice(), 'The notice is not shown to users who do not administer the plugin' );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

// --- Check again clears the record; a later report brings it back ---
$GLOBALS['wp_test_caps'] = [ 'edit_posts' ];
$refused = false;
try {
	ServiceBlockNotice::handle_check_again();
} catch ( RuntimeException $error ) {
	$refused = str_starts_with( $error->getMessage(), 'wp_die:' );
}
check_block( $refused && null !== ServiceBlockNotice::current(), 'A user who does not administer the plugin cannot clear the record' );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
ServiceBlockNotice::clear();
check_block( '' === render_block_notice(), 'A cleared record shows no notice' );
check_block( [ 'recorded' => true ] === $report( $request( $valid ) ), 'The next report after Check again is written at once' );
check_block( '' !== render_block_notice(), 'A block still in place shows the notice again' );

// --- a record expires after a week without a new report ---
update_option( ServiceBlockNotice::OPTION, [ 'time' => time() - 7 * DAY_IN_SECONDS + 60, 'origin' => $origin, 'path' => '/' ] );
check_block( '' !== render_block_notice(), 'A record younger than a week still shows' );
update_option( ServiceBlockNotice::OPTION, [ 'time' => time() - 7 * DAY_IN_SECONDS - 1, 'origin' => $origin, 'path' => '/' ] );
check_block( '' === render_block_notice(), 'A record older than a week shows nothing' );
check_block( null === get_option( ServiceBlockNotice::OPTION, null ), 'An expired record is removed' );

// --- the notice and its button are wired ---
new \TranslateWP\Functionality\Admin\ServiceBlockNotice( 'translatewp', '0.0.0' );
$registered = array_column( $GLOBALS['wp_test_action_registrations'], 'tag' );
check_block( in_array( 'admin_notices', $registered, true ), 'The notice renders with the admin notices' );
check_block( in_array( 'admin_post_' . ServiceBlockNotice::CHECK_AGAIN_ACTION, $registered, true ), 'Check again posts to admin-post' );

if ( $failures ) {
	fwrite( STDERR, "ServiceBlockNoticeTest: {$failures} failure(s)\n" );
	exit( 1 );
}
echo "ServiceBlockNoticeTest: ok\n";
