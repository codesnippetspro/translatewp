<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;

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

function seed_read_pages( int $count ): void {
	$queue = new \TranslateWP\Components\IndexQueue();
	for ( $index = 1; $index <= $count; $index++ ) {
		$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => $index, 'url' => 'https://example.com/page-' . $index, 'priority' => 1 ] ] );
	}
	foreach ( $queue->claim_batch( \TranslateWP\Components\IndexQueue::STAGE_EXTRACT, $count ) as $row ) {
		$queue->complete( (int) ( is_array( $row ) ? $row['id'] : $row ) );
	}
}

$connected_options = [
	'site'      => [
		'id'                => '00000000-0000-4000-8000-000000000001',
		'token'             => 'secret-site-token',
		'home_url'          => 'https://example.com',
		'account_label'     => 'ops@translatewp.ai',
		'connection_status' => 'connected',
	],
	'languages' => [
		'list' => [
			'nl' => [ 'locale' => 'nl', 'slug' => 'nl', 'active' => true, 'default' => true ],
			'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
		],
	],
];

// --- Paired without ever choosing languages (a subsite paired by the network connect): wizard, no heal ---
wp_test_reset( [ 'site' => $connected_options['site'] ] );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];
assert_true( true === SiteConnection::needs_onboarding(), 'A paired site with no default language must need onboarding' );
assert_true( false === \TranslateWP\Components\Options::get( 'setup.ended', false ), 'A site that never chose its languages is not healed as set up' );

// --- A site marked set up whose languages carry no default (a botched earlier setup) is re-onboarded ---
wp_test_reset( [
	'site'      => $connected_options['site'],
	'setup'     => [ 'ended' => true, 'step' => 'done' ],
	'languages' => [ 'list' => [ [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ], [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ] ] ],
] );
assert_true( true === SiteConnection::needs_onboarding(), 'A set-up flag without a default language must still need onboarding' );

// --- Fresh install (no credentials, no setup.ended): wizard ---
wp_test_reset( [] );
assert_true( true === SiteConnection::needs_onboarding(), 'Fresh install must need onboarding' );

// --- Reinstall of a read site: setup.ended lost but pairing approved server-side -> Overview, no wizard ---
wp_test_reset( $connected_options );
seed_read_pages( 2 );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];
assert_true( false === SiteConnection::needs_onboarding(), 'Reinstall of a read site with approved pairing must not need onboarding' );
assert_true( false === \TranslateWP\Components\Options::get( 'setup.ended', false ), 'A read site is never healed to finished; the Finish setting up banner leads back' );
assert_true(
	'/v1/site/job-events' === (string) parse_url( $GLOBALS['wp_test_remote_get_urls'][0] ?? '', PHP_URL_PATH ),
	'Pairing probe must use the job-events route (405-proof), got ' . ( $GLOBALS['wp_test_remote_get_urls'][0] ?? 'none' )
);

// --- Transient prevents repeat remote calls ---
wp_test_reset( $connected_options );
seed_read_pages( 2 );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];
SiteConnection::needs_onboarding();
$GLOBALS['wp_test_remote_get_response'] = null;
assert_true( false === SiteConnection::needs_onboarding(), 'Cached verdict must skip the remote call' );

// --- Invalidated pairing -> reconnect flow (onboarding needed), no remote call ---
wp_test_reset( $connected_options );
SiteConnection::invalidate_connection();
assert_true( true === SiteConnection::needs_onboarding(), 'Invalidated pairing must need onboarding' );

// --- API down + connected -> fail open, no wizard ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'remote_error', 'down', [ 'status' => 502 ] );
assert_true( false === SiteConnection::needs_onboarding(), 'API-down with credentials must fail open (no wizard)' );
assert_true( false === \TranslateWP\Components\Options::get( 'setup.ended', false ), 'Fail open must not mark a never-read site finished' );

// --- 401 + connected -> onboarding needed (invalidation path) ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'Unauthorized' ] ),
];
assert_true( true === SiteConnection::needs_onboarding(), '401 must require onboarding (reconnect)' );

// --- Deliberate restart (P3 settings action) defeats the heal gate ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];
\TranslateWP\Components\Options::update( 'setup.restarted', true );
assert_true( true === SiteConnection::needs_onboarding(), 'Deliberate restart must re-open wizard even with approved pairing' );

// --- Network admin: the network settings page opens before any site is set up ---
wp_test_reset( [] );
$GLOBALS['wp_test_multisite']     = true;
$GLOBALS['wp_test_network_admin'] = true;
$GLOBALS['wp_test_caps']          = [ 'manage_options', 'manage_network_options' ];
$_GET['page']                     = 'translatewp-network-settings';
( new \TranslateWP\Functionality\Admin\Menus( 'translatewp', TRANSLATEWP_VERSION ) )->redirect_to_setup();
assert_true( [] === $GLOBALS['wp_test_redirects'], 'The network settings page must not be redirected to a subsite setup, got ' . wp_json_encode( $GLOBALS['wp_test_redirects'] ) );
unset( $_GET['page'] );
$GLOBALS['wp_test_network_admin'] = false;
$GLOBALS['wp_test_multisite']     = false;

// --- PHP/TS slug agreement: zh-hans -> zh ---
wp_test_reset( $connected_options );
$management = new \TranslateWP\Functionality\Endpoints\Management( 'translatewp', TRANSLATEWP_VERSION );
$reflection = new ReflectionClass( $management );
$method = $reflection->getMethod( 'slug_from_locale' );
$method->setAccessible( true );
$slug = $method->invoke( $management, 'zh-hans' );
assert_true( 'zh' === $slug, 'PHP slug_from_locale must derive zh from zh-hans (agrees with TS defaultSlugFromLocale)' );

if ( $failures > 0 ) {
	fwrite( STDERR, "OnboardingGateTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "OnboardingGateTest: all assertions passed\n";
