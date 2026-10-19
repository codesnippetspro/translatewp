<?php

declare(strict_types=1);

use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteConnection;
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

$connected = [
	'site' => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
];

// The site's Pro state, with the addon installed or not and active or not.
function pro_site( array $connected, bool $entitled, bool $installed, bool $active ): void {
	wp_test_reset( $connected );
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $entitled ? 'pro' : 'free', 'entitled' => $entitled ], 3600 );
	wp_test_set_filter( 'translatewp_pro_installed_version', static function () use ( $installed ) { return $installed ? '1.2.0' : 'none'; } );
	wp_test_set_filter( 'translatewp_pro_addon_active', static function () use ( $active ) { return $active; } );
}

// --- welcomePending is true only for an entitled site with the addon active and the option unset ---
pro_site( $connected, true, true, true );
assert_true( true === ProEligibility::welcome_pending(), 'An entitled site whose addon just became active is welcomed to Pro' );

pro_site( $connected, false, false, false );
assert_true( false === ProEligibility::welcome_pending(), 'A Free site is never welcomed to Pro' );

pro_site( $connected, false, true, true );
assert_true( false === ProEligibility::welcome_pending(), 'An active addon without Pro on the account is not welcomed' );

pro_site( $connected, true, false, false );
assert_true( false === ProEligibility::welcome_pending(), 'An entitled site without the addon sees the install card, not the welcome' );

pro_site( $connected, true, true, false );
assert_true( false === ProEligibility::welcome_pending(), 'An entitled site with the addon inactive sees the activate card, not the welcome' );

// --- dismissing records the welcome for the site, for good ---
pro_site( $connected, true, true, true );
ProEligibility::dismiss_welcome();
assert_true( false !== get_option( 'translatewp_pro_welcome_seen', false ), 'Dismissing stores the site option' );
assert_true( false === ProEligibility::welcome_pending(), 'A dismissed welcome does not return' );

// --- the connect status carries the flag the Dashboard reads ---
pro_site( $connected, true, true, true );
$payload = SiteConnection::status_payload();
assert_true( true === ( $payload['proWelcomePending'] ?? null ), 'The connect status reports a pending welcome' );
ProEligibility::dismiss_welcome();
$payload = SiteConnection::status_payload();
assert_true( false === ( $payload['proWelcomePending'] ?? null ), 'The connect status stops reporting the welcome after dismissal' );

// --- the dismiss route is registered for admins ---
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$source     = (string) file_get_contents( dirname( __DIR__ ) . '/Functionality/Endpoints/Management.php' );
assert_true( false !== strpos( $source, "'dismiss-pro-welcome'" ), 'Management registers the dismiss-pro-welcome route' );

pro_site( $connected, true, true, true );
$result = $management->dismiss_pro_welcome( null );
assert_true( true === ( $result['success'] ?? false ), 'The dismiss route answers success' );
assert_true( false === ProEligibility::welcome_pending(), 'The dismiss route records the welcome for the site' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ProWelcomeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ProWelcomeTest: all assertions passed\n";
