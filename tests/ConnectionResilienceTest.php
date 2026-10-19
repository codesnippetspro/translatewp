<?php

declare(strict_types=1);

use TranslateWP\Components\Migrations;
use TranslateWP\Components\Options;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.22' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$connected_options = [
	'site' => [
		'id'                => '00000000-0000-4000-8000-000000000001',
		'token'             => 'secret-site-token',
		'home_url'          => 'https://example.com',
		'account_label'     => 'ops@example.com',
		'connection_status' => 'connected',
	],
];

// --- Repair: a stored invalidation clears when the status call succeeds ---
wp_test_reset( $connected_options );
SiteConnection::invalidate_connection();
assert_true( 'invalidated' === SiteConnection::connection_status(), 'precondition: connection is invalidated' );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];
assert_true( 'connected' === SiteConnection::verify_connection(), 'A successful status call repairs a stored invalidation' );
assert_true( true === SiteConnection::is_connected(), 'A repaired connection reads as connected' );

// --- Confirmed revoke: an armed re-check plus an explicit 401 invalidates ---
wp_test_reset( $connected_options );
SiteConnection::flag_for_reverify();
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'revoked' ] ),
];
assert_true( 'invalidated' === SiteConnection::verify_connection(), 'An explicit revoked answer to the status call invalidates' );
assert_true( false === SiteConnection::needs_reverify(), 'A resolved re-check is cleared' );

// --- Transport failure leaves the connection state untouched ---
wp_test_reset( $connected_options );
SiteConnection::flag_for_reverify();
$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'http_request_failed', 'connection refused' );
assert_true( 'connected' === SiteConnection::verify_connection(), 'A transport failure must not change the connection state' );
assert_true( true === SiteConnection::needs_reverify(), 'A transport failure keeps the re-check armed for later' );

// --- A maintenance page or any non-auth status is inconclusive, never a verdict ---
wp_test_reset( $connected_options );
SiteConnection::flag_for_reverify();
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 503 ],
	'body'     => '<html><body>Maintenance</body></html>',
];
assert_true( 'connected' === SiteConnection::verify_connection(), 'A maintenance page must not change the connection state' );

// --- A stored invalidation is not repaired while the service still rejects ---
wp_test_reset( $connected_options );
SiteConnection::invalidate_connection();
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'revoked' ] ),
];
assert_true( 'invalidated' === SiteConnection::verify_connection(), 'A still-rejecting service keeps the site invalidated' );

// --- Upgrade lifecycle: a version change and the activation hook keep the connection ---
wp_test_reset( $connected_options );
update_option( Migrations::VERSION_OPTION, '0.9.21' );
update_option( 'translatewp_setup', [ 'ended' => true ] );
update_option( 'translatewp_languages', [ 'list' => [ 'de' => [ 'locale' => 'de', 'active' => true, 'default' => true ] ] ] );
Migrations::maybe_run();
Lyfecycle::activate( false );
assert_true( 'secret-site-token' === Options::get( 'site.token', '' ), 'An upgrade must preserve the site token' );
assert_true( '00000000-0000-4000-8000-000000000001' === Options::get( 'site.id', '' ), 'An upgrade must preserve the site id' );
assert_true( 'connected' === SiteConnection::connection_status(), 'An upgrade must not change the connection state' );
assert_true( false === SiteConnection::needs_reverify(), 'An upgrade must not arm a re-check by itself' );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $script->conditions(), 'The visitor runtime keeps enqueuing across an upgrade' );

// --- Pro addon active: a transient 401 during the upgrade never drops the connection ---
wp_test_reset( $connected_options );
add_filter( 'translatewp_pro_addon_active', static function () {
	return true;
} );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '0.3.17';
} );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'Unauthorized' ] ),
];
ProEligibility::refresh();
assert_true( 'connected' === SiteConnection::connection_status(), 'A Pro eligibility 401 during the upgrade must not invalidate' );
assert_true( true === SiteConnection::needs_reverify(), 'A Pro eligibility 401 arms the status re-check' );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];
assert_true( 'connected' === SiteConnection::verify_connection(), 'The next successful status call confirms the connection' );
assert_true( false === SiteConnection::needs_reverify(), 'The resolved re-check is cleared after the self-heal' );

// --- The visitor runtime keeps enqueuing even while invalidated, so the store still serves ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
update_option( 'translatewp_languages', [ 'list' => [ 'it' => [ 'locale' => 'it', 'active' => true, 'default' => true ] ] ] );
SiteConnection::invalidate_connection();
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === SiteConnection::is_connected(), 'An invalidated connection reads as not connected' );
assert_true( true === $script->conditions(), 'The visitor runtime keeps enqueuing while invalidated so the store keeps serving' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ConnectionResilienceTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ConnectionResilienceTest: all assertions passed\n";
