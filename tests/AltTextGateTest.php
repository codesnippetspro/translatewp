<?php

declare(strict_types=1);

// Translating image alt text is a Pro feature: the visitor runtime is told
// to leave alt text alone unless the account is entitled and the addon is
// active, whatever the stored setting says.

use TranslateWP\Components\ProEligibility;
use TranslateWP\Entities\Scripts\TranslateWP;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function include_alt( bool $entitled, bool $addon_active, ?bool $setting ): bool {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );
	if ( null !== $setting ) {
		\TranslateWP\Components\Options::update( 'translations.settings', [ 'translate_image_alt_tags' => $setting ] );
	}
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $entitled ? 'pro' : 'free', 'entitled' => $entitled ], 3600 );
	wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => $addon_active );

	$options = ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->get_frontend_options();

	return (bool) $options['extraction_options']['includeAlt'];
}

// --- Free: alt text stays in the source language ---
assert_true( false === include_alt( false, false, null ), 'On Free the default must leave alt text alone' );
assert_true( false === include_alt( false, false, true ), 'On Free a stored "on" from an older version must not translate alt text' );
assert_true( false === include_alt( true, false, true ), 'An entitled account without the active addon must not translate alt text' );
assert_true( false === include_alt( false, true, true ), 'An active addon on an account that is not entitled must not translate alt text' );

// --- Pro: alt text translates as it did, and the setting still switches it off ---
assert_true( true === include_alt( true, true, null ), 'With Pro the default translates alt text' );
assert_true( true === include_alt( true, true, true ), 'With Pro a stored "on" translates alt text' );
assert_true( false === include_alt( true, true, false ), 'With Pro the setting still switches alt text off' );

// --- The one question every gate asks ---
wp_test_reset( [] );
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => true );
assert_true( true === ProEligibility::unlocks_pro_features(), 'An entitled account with the active addon unlocks Pro features' );
wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => false );
assert_true( false === ProEligibility::unlocks_pro_features(), 'Without the active addon nothing is unlocked' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AltTextGateTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AltTextGateTest: all assertions passed\n";
