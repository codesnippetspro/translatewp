<?php

declare(strict_types=1);

use TranslateWP\Entities\Scripts\TranslateWP;
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

// Issue #25 chrome defaults: every copy must agree with one contract.
const EXPECTED_OFFSET   = 24;
const EXPECTED_ICON     = 24;
const EXPECTED_PADDING  = 12;
const EXPECTED_BORDER   = '#E2E2E4';
const EXPECTED_LOADING_BORDER = '#0e3ecd';
const EXPECTED_URL_STRUCTURE = 'clean';

// --- Copy 1: frontend defaults (Entities/Scripts/TranslateWP.php) ---
wp_test_reset( [] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$options = $script->get_frontend_options();
$switcher = $options['languages']['switcher'] ?? [];
assert_true( EXPECTED_OFFSET === ( $switcher['offset'] ?? null ), 'Frontend default offset must be ' . EXPECTED_OFFSET . ', got ' . ( $switcher['offset'] ?? 'none' ) );
assert_true( EXPECTED_ICON === ( $switcher['icon_size'] ?? null ), 'Frontend default icon_size must be ' . EXPECTED_ICON . ', got ' . ( $switcher['icon_size'] ?? 'none' ) );
assert_true( EXPECTED_PADDING === ( $switcher['padding'] ?? null ), 'Frontend default padding must be ' . EXPECTED_PADDING . ', got ' . ( $switcher['padding'] ?? 'none' ) );
assert_true( 'full' === ( $switcher['regional_names'] ?? null ), 'Frontend default regional names must be full, got ' . ( $switcher['regional_names'] ?? 'none' ) );
assert_true( 'name_flag' === ( $switcher['trigger'] ?? null ), 'default switcher trigger is name_flag, got ' . ( $switcher['trigger'] ?? 'none' ) );
assert_true( EXPECTED_BORDER === ( $switcher['border_color'] ?? null ), 'Frontend default border colour must be ' . EXPECTED_BORDER . ', got ' . ( $switcher['border_color'] ?? 'none' ) );
assert_true( EXPECTED_LOADING_BORDER === ( $switcher['loading_border_color'] ?? null ), 'Frontend default loading border colour reproduces the ring colour ' . EXPECTED_LOADING_BORDER . ', got ' . ( $switcher['loading_border_color'] ?? 'none' ) );
$seo = $options['languages']['seo'] ?? [];
assert_true( EXPECTED_URL_STRUCTURE === ( $seo['url_structure'] ?? null ), 'Frontend default URL structure must be ' . EXPECTED_URL_STRUCTURE . ', got ' . ( $seo['url_structure'] ?? 'none' ) );

// --- Copy 2: finish_setup seed (Functionality/Endpoints/Management.php) ---
wp_test_reset( [] );
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/finish-setup', [
	'default_language' => 'en-us',
	'languages'        => [ 'en-us' ],
	'auto_translate'   => true,
	'context'          => '',
] );
$management->finish_setup( $request );
$seeded = \TranslateWP\Components\Options::get( 'languages.switcher', [] );
assert_true( EXPECTED_OFFSET === ( $seeded['offset'] ?? null ), 'Management seed offset must be ' . EXPECTED_OFFSET . ', got ' . ( $seeded['offset'] ?? 'none' ) );
assert_true( EXPECTED_ICON === ( $seeded['icon_size'] ?? null ), 'Management seed icon_size must be ' . EXPECTED_ICON . ', got ' . ( $seeded['icon_size'] ?? 'none' ) );
assert_true( EXPECTED_PADDING === ( $seeded['padding'] ?? null ), 'Management seed padding must be ' . EXPECTED_PADDING . ', got ' . ( $seeded['padding'] ?? 'none' ) );
assert_true( 'full' === ( $seeded['regional_names'] ?? null ), 'Management seed regional names must be full, got ' . ( $seeded['regional_names'] ?? 'none' ) );
assert_true( 'name_flag' === ( $seeded['trigger'] ?? null ), 'Management seed switcher trigger must be name_flag, got ' . ( $seeded['trigger'] ?? 'none' ) );
assert_true( EXPECTED_BORDER === ( $seeded['border_color'] ?? null ), 'Management seed border colour must be ' . EXPECTED_BORDER . ', got ' . ( $seeded['border_color'] ?? 'none' ) );
assert_true( EXPECTED_LOADING_BORDER === ( $seeded['loading_border_color'] ?? null ), 'Management seed loading border colour must be ' . EXPECTED_LOADING_BORDER . ', got ' . ( $seeded['loading_border_color'] ?? 'none' ) );

// --- A saved switcher without a trigger key reads as the stored trigger, never as missing ---
wp_test_reset( [ 'languages' => [ 'switcher' => [ 'trigger' => 'icon' ] ] ] );
$script  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$switcher = $script->get_frontend_options()['languages']['switcher'] ?? [];
assert_true( 'icon' === ( $switcher['trigger'] ?? null ), 'A stored icon trigger must reach the frontend, got ' . ( $switcher['trigger'] ?? 'none' ) );

// --- A site with a custom highlight and no loading border keeps that highlight as the ring colour ---
wp_test_reset( [ 'languages' => [ 'switcher' => [ 'highlight_bg' => '#ff0000' ] ] ] );
$script  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$switcher = $script->get_frontend_options()['languages']['switcher'] ?? [];
assert_true( '#ff0000' === ( $switcher['loading_border_color'] ?? null ), 'An unset loading border reproduces the saved highlight colour, got ' . ( $switcher['loading_border_color'] ?? 'none' ) );

// --- A stored loading border colour overrides the highlight fallback ---
wp_test_reset( [ 'languages' => [ 'switcher' => [ 'highlight_bg' => '#ff0000', 'loading_border_color' => '#00ff0080' ] ] ] );
$script  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$switcher = $script->get_frontend_options()['languages']['switcher'] ?? [];
assert_true( '#00ff0080' === ( $switcher['loading_border_color'] ?? null ), 'A stored loading border colour with alpha reaches the frontend, got ' . ( $switcher['loading_border_color'] ?? 'none' ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "SwitcherDefaultsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SwitcherDefaultsTest: all assertions passed\n";
