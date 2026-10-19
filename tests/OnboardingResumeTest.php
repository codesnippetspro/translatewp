<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Options;
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

$ok_probe = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'events' => [] ] ),
];

function seed_read_pages( int $count ): void {
	$queue = new IndexQueue();
	for ( $index = 1; $index <= $count; $index++ ) {
		$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => $index, 'url' => 'https://example.com/page-' . $index, 'priority' => 1 ] ] );
	}
	foreach ( $queue->claim_batch( IndexQueue::STAGE_EXTRACT, $count ) as $row ) {
		$queue->complete( (int) ( is_array( $row ) ? $row['id'] : $row ) );
	}
}

// --- Never-read, connected, pairing verified: resume the wizard at the reading step, no heal ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = $ok_probe;
assert_true( true === SiteConnection::needs_onboarding(), 'A connected, pairing-valid site with nothing read must resume onboarding' );
assert_true( 'read-site' === Options::get( 'setup.step', '' ), 'The resumed wizard must open at the read-site step, got ' . (string) Options::get( 'setup.step', '' ) );
assert_true( false === Options::get( 'setup.ended', false ), 'A never-read site must not be healed to finished' );

// --- Pages read but setup not finished: land on the Overview, never marked finished ---
wp_test_reset( $connected_options );
seed_read_pages( 2 );
$GLOBALS['wp_test_remote_get_response'] = $ok_probe;
assert_true( false === SiteConnection::needs_onboarding(), 'A read site with an unfinished setup must land on the Overview, not the wizard' );
assert_true( false === Options::get( 'setup.ended', false ), 'A read but unfinished site keeps setup.ended false so the Finish setting up banner shows' );
assert_true( true === SiteConnection::setup_unfinished(), 'A read but unfinished site reports an unfinished setup' );

// --- A genuinely finished site passes straight through, no banner ---
wp_test_reset( array_merge( $connected_options, [ 'setup' => [ 'ended' => true, 'step' => 'done' ] ] ) );
seed_read_pages( 2 );
$GLOBALS['wp_test_remote_get_response'] = $ok_probe;
assert_true( false === SiteConnection::needs_onboarding(), 'A finished site stays on the Overview' );
assert_true( false === SiteConnection::setup_unfinished(), 'A finished site reports no unfinished setup' );

// --- setup_unfinished only reports for a connected site with pages read ---
wp_test_reset( $connected_options );
assert_true( false === SiteConnection::setup_unfinished(), 'A connected site with nothing read is a resume case, not a banner case' );

wp_test_reset( [] );
assert_true( false === SiteConnection::setup_unfinished(), 'An unpaired site reports no unfinished setup' );

// --- Finish setting up banner: a dismissal lasts seven days, then the banner returns ---
wp_test_reset( $connected_options );
seed_read_pages( 2 );
$GLOBALS['wp_test_current_user'] = 1;
assert_true( false === SiteConnection::finish_setup_dismissed(), 'A user who never dismissed the banner is not treated as dismissed' );
SiteConnection::dismiss_finish_setup();
assert_true( true === SiteConnection::finish_setup_dismissed(), 'A just-dismissed banner stays hidden for that user' );
$GLOBALS['wp_test_user_meta'][1]['translatewp_finish_setup_dismissed'] = time() - ( 8 * 24 * 60 * 60 );
assert_true( false === SiteConnection::finish_setup_dismissed(), 'A dismissal older than seven days brings the banner back' );

// --- Fail open when the API is down, without falsely finishing a never-read site ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'remote_error', 'down', [ 'status' => 502 ] );
assert_true( false === SiteConnection::needs_onboarding(), 'API-down with credentials must fail open (no wizard)' );
assert_true( false === Options::get( 'setup.ended', false ), 'Fail open must not mark a never-read site finished' );

if ( $failures > 0 ) {
	fwrite( STDERR, "OnboardingResumeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "OnboardingResumeTest: all assertions passed\n";
