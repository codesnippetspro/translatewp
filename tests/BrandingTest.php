<?php

declare(strict_types=1);

// The "by TranslateWP" line in the switcher: Terrestrial always shows it; an
// Orbital site hides it by default and may show it again from edit mode.

use TranslateWP\Components\Options;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Functionality\Endpoints\Admin;

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

function hide_branding( bool $pro, ?bool $stored, ?bool $addon_active = null ): bool {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );
	if ( null !== $stored ) {
		Options::update( 'languages.switcher', [ 'hide_branding' => $stored ] );
	}
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $pro ? 'pro' : 'free', 'entitled' => $pro ], 3600 );
	$active = $addon_active ?? $pro;
	wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => $active );

	return (bool) ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->get_frontend_options()['languages']['switcher']['hide_branding'];
}

assert_true( false === hide_branding( false, null ), 'Free shows the branding' );
assert_true( false === hide_branding( false, true ), 'Free shows the branding whatever is stored' );
assert_true( true === hide_branding( true, null ), 'Pro hides the branding by default' );
assert_true( false === hide_branding( true, false ), 'Pro shows the branding when the site chose to' );
assert_true( true === hide_branding( true, true ), 'Pro hides it when the site chose to' );
// The choice is the Orbital plan's, made in the free plugin: it holds without the Pro addon.
assert_true( true === hide_branding( true, null, false ), 'an Orbital site without the addon hides the branding by default too' );
assert_true( false === hide_branding( true, false, false ), 'an Orbital site without the addon shows it when it chose to' );

// --- the edit-mode toggle saves through the switcher patch ---
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ], 'languages' => [ 'switcher' => [ 'type' => 'dropdown' ] ] ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( new WP_REST_Request( 'POST', '', [ 'option' => 'languages.switcher', 'data' => [ 'hide_branding' => false ] ] ) );
assert_true( false === Options::get( 'languages.switcher' )['hide_branding'], 'The toggle is stored as a boolean' );
assert_true( 'dropdown' === Options::get( 'languages.switcher' )['type'], 'Other switcher settings are kept' );

if ( $failures > 0 ) {
	fwrite( STDERR, "BrandingTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "BrandingTest: all assertions passed\n";
