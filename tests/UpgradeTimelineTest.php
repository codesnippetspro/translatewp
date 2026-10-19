<?php

declare(strict_types=1);

use TranslateWP\Components\ProEligibility;

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

function eligibility_answer( array $payload ): array {
	return [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( $payload ),
	];
}

function upgrade_at( string $stage ): array {
	$times = [
		'checkout_started_at'   => '2026-09-25T15:40:00Z',
		'checkout_completed_at' => null,
		'payment_received_at'   => null,
		'confirmed_at'          => null,
		'declined_at'           => null,
	];
	$order = [ 'checkout_started', 'checkout_completed', 'payment_received', 'confirmed' ];
	$reach = array_search( $stage, $order, true );
	foreach ( $order as $index => $step ) {
		if ( false !== $reach && $index <= $reach && $index > 0 ) {
			$times[ $step . '_at' ] = '2026-09-25T15:4' . ( $index + 1 ) . ':00Z';
		}
	}
	if ( 'declined' === $stage ) {
		$times['declined_at'] = '2026-09-25T15:45:00Z';
	}

	return array_merge( [ 'stage' => $stage ], $times );
}

function answer( bool $entitled, ?array $upgrade ): array {
	$payload = [
		'plan'     => $entitled ? 'pro' : 'free',
		'entitled' => $entitled,
		'latest'   => [
			'version'     => '1.2.0',
			'released_at' => '2026-09-12T10:00:00Z',
		],
		'upgrade'  => $upgrade,
	];
	if ( $entitled ) {
		$payload['package_url'] = 'https://api.translatewp.ai/v1/pro/package?token=signed-abc';
	}

	return $payload;
}

function eligibility_ttl(): int {
	return ( $GLOBALS['wp_test_transient_expirations']['translatewp_pro_eligibility'] ?? 0 ) - time();
}

$connected = [
	'site' => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
];

// --- parse: a valid upgrade is kept, anything else reads as no upgrade ---
$parsed = ProEligibility::parse( answer( false, upgrade_at( 'checkout_completed' ) ) );
assert_true( 'checkout_completed' === ( $parsed['upgrade']['stage'] ?? null ), 'a valid stage is kept, got ' . wp_json_encode( $parsed['upgrade'] ?? null ) );
assert_true( '2026-09-25T15:42:00Z' === ( $parsed['upgrade']['checkout_completed_at'] ?? null ), 'the step times are kept' );
assert_true( array_key_exists( 'payment_received_at', $parsed['upgrade'] ?? [] ) && null === $parsed['upgrade']['payment_received_at'], 'a step not reached has no time' );
assert_true( null === ProEligibility::parse( answer( false, null ) )['upgrade'], 'a null upgrade reads as no upgrade' );
assert_true( null === ProEligibility::parse( [ 'plan' => 'free', 'entitled' => false ] )['upgrade'], 'an answer without upgrade reads as no upgrade' );
assert_true( null === ProEligibility::parse( answer( false, array_merge( upgrade_at( 'checkout_started' ), [ 'stage' => 'refunded' ] ) ) )['upgrade'], 'an unknown stage reads as no upgrade' );
assert_true( null === ProEligibility::parse( answer( false, [ 'stage' => 'checkout_started', 'checkout_started_at' => 42 ] ) )['upgrade']['checkout_started_at'], 'a time that is not a string reads as null' );
assert_true( null === ProEligibility::parse( array_merge( answer( false, null ), [ 'upgrade' => 'checkout_started' ] ) )['upgrade'], 'an upgrade that is not an object reads as no upgrade' );
assert_true( null === ProEligibility::default_state()['upgrade'], 'the default state has no upgrade' );
assert_true( ! array_key_exists( 'payment_pending', ProEligibility::parse( array_merge( answer( false, null ), [ 'payment_pending' => true ] ) ) ), 'the retired payment_pending flag is not read' );

// --- panel state: the stage and each step time in the site time format ---
wp_test_reset( $connected );
$GLOBALS['wp_test_raw_options']['time_format'] = 'g:i a';
$GLOBALS['wp_test_remote_get_response']        = eligibility_answer( answer( false, upgrade_at( 'payment_received' ) ) );
ProEligibility::refresh();
$panel = ProEligibility::panel_state();
assert_true( 'payment_received' === ( $panel['upgrade']['stage'] ?? null ), 'the panel carries the stage, got ' . wp_json_encode( $panel['upgrade'] ?? null ) );
assert_true( '3:40 pm' === ( $panel['upgrade']['times']['checkoutStarted'] ?? null ), 'the checkout start time uses the site time format, got ' . wp_json_encode( $panel['upgrade']['times'] ?? null ) );
assert_true( '3:43 pm' === ( $panel['upgrade']['times']['paymentReceived'] ?? null ), 'the payment time is shown' );
assert_true( array_key_exists( 'confirmed', $panel['upgrade']['times'] ?? [] ) && null === $panel['upgrade']['times']['confirmed'], 'a step not reached has no time' );
assert_true( ! array_key_exists( 'paymentPending', $panel ) && ! array_key_exists( 'upgradeStarted', $panel ) && ! array_key_exists( 'checkoutStarted', $panel ), 'the retired flags are gone from the panel state' );

// --- the local click counts as a started checkout until the service reports the upgrade ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_answer( answer( false, null ) );
ProEligibility::start_upgrade();
ProEligibility::refresh();
$panel = ProEligibility::panel_state();
assert_true( 'checkout_started' === ( $panel['upgrade']['stage'] ?? null ), 'the click alone shows the checkout as started' );
assert_true( is_string( $panel['upgrade']['times']['checkoutStarted'] ?? null ), 'the click time is shown as the checkout start' );
assert_true( 15 === eligibility_ttl(), 'after the click the next check comes in 15 seconds, got ' . eligibility_ttl() );

$GLOBALS['wp_test_remote_get_response'] = eligibility_answer( answer( false, upgrade_at( 'checkout_completed' ) ) );
ProEligibility::refresh();
assert_true( 'checkout_completed' === ( ProEligibility::panel_state()['upgrade']['stage'] ?? null ), 'the service stage wins over the local click' );

// --- each stage and its cache lifetime, through to Pro ---
wp_test_reset( $connected );
$steps = [
	[ answer( false, null ), false, null, 300 ],
	[ answer( false, upgrade_at( 'checkout_started' ) ), false, 'checkout_started', 15 ],
	[ answer( false, upgrade_at( 'checkout_completed' ) ), false, 'checkout_completed', 15 ],
	[ answer( false, upgrade_at( 'payment_received' ) ), false, 'payment_received', 15 ],
	[ answer( true, upgrade_at( 'confirmed' ) ), true, 'confirmed', 15 ],
	[ answer( true, null ), true, null, 3600 ],
];
foreach ( $steps as $index => [ $payload, $entitled, $stage, $ttl ] ) {
	$GLOBALS['wp_test_remote_get_response'] = eligibility_answer( $payload );
	ProEligibility::refresh();
	$panel = ProEligibility::panel_state();
	$label = 'step ' . ( $index + 1 );
	assert_true( $stage === ( $panel['upgrade']['stage'] ?? null ), $label . ': stage ' . wp_json_encode( $panel['upgrade'] ?? null ) );
	assert_true( $entitled === $panel['entitled'], $label . ': entitled ' . wp_json_encode( $panel['entitled'] ) );
	assert_true( $ttl === eligibility_ttl(), $label . ': cache lifetime ' . eligibility_ttl() );
}
assert_true( true === ProEligibility::should_show_install_prompt(), 'the confirmed account without the addon is offered the install' );

// --- a declined upgrade stops the quick checks and ends the local click window ---
wp_test_reset( $connected );
ProEligibility::start_upgrade();
$GLOBALS['wp_test_remote_get_response'] = eligibility_answer( answer( false, upgrade_at( 'declined' ) ) );
ProEligibility::refresh();
$panel = ProEligibility::panel_state();
assert_true( 'declined' === ( $panel['upgrade']['stage'] ?? null ), 'the declined stage reaches the panel' );
assert_true( is_string( $panel['upgrade']['times']['declined'] ?? null ), 'the decline time is shown' );
assert_true( 300 === eligibility_ttl(), 'a declined upgrade goes back to the usual interval, got ' . eligibility_ttl() );
assert_true( false === ProEligibility::upgrade_started(), 'a declined upgrade ends the click window' );

// --- the account page for a declined upgrade comes from the portal base ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_answer( answer( false, upgrade_at( 'declined' ) ) );
ProEligibility::refresh();
assert_true( 'https://app.translatewp.ai/account' === ( ProEligibility::panel_state()['accountUrl'] ?? null ), 'the panel links to the portal account page, got ' . wp_json_encode( ProEligibility::panel_state()['accountUrl'] ?? null ) );
wp_test_set_filter( 'translatewp_app_base_url', static fn() => 'https://portal.example.test/' );
assert_true( 'https://portal.example.test/account' === ( ProEligibility::panel_state()['accountUrl'] ?? null ), 'the account page follows the configured portal base, got ' . wp_json_encode( ProEligibility::panel_state()['accountUrl'] ?? null ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "UpgradeTimelineTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "UpgradeTimelineTest: all assertions passed\n";
