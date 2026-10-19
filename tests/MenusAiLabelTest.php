<?php

declare(strict_types=1);

use TranslateWP\Functionality\Admin\Menus;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $a, $b, $c, $d, $e, $f = '', $g = null ) { unset( $a, $b, $c, $d, $e, $f, $g ); return true; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $a, $b, $c, $d, $e, $f = null ) {
		unset( $a, $d, $f );
		$GLOBALS['wp_test_submenu_titles'][ (string) $e ] = [
			'page' => (string) $b,
			'menu' => (string) $c,
		];
		return true;
	}
}

if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) { unset( $parent ); $GLOBALS['wp_test_removed_slugs'][] = (string) $slug; return true; }
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- The AI page submenu is labelled "AI Models" in both title positions ---

wp_test_reset( [] );
$GLOBALS['wp_test_submenu_titles']         = [];
$GLOBALS['wp_test_removed_slugs']          = [];
$GLOBALS['wp_test_options_store']['setup'] = [ 'ended' => true ];
$GLOBALS['wp_test_options_store']['languages'] = [ 'list' => [ 'nl' => [ 'locale' => 'nl', 'slug' => 'nl', 'active' => true, 'default' => true ] ] ];

$menus = new Menus( 'translatewp', '0.0.0-test' );
$menus->add_admin_menu();

$titles = $GLOBALS['wp_test_submenu_titles'];

assert_true( [ 'translatewp', 'translatewp-seo', 'translatewp-lexicon', 'translatewp-settings' ] === array_keys( $titles ), 'Exactly the Dashboard, SEO Tools, Lexicon and Settings pages are registered in that order' );
assert_true( 'SEO Tools' === ( $titles['translatewp-seo']['page'] ?? '' ), 'SEO Tools carries its own page title' );
assert_true( [] === ( $GLOBALS['wp_test_removed_slugs'] ?? [] ), 'Every registered page stays visible in the menu' );

foreach ( $titles as $entry ) {
	assert_true( 'AI Providers' !== $entry['menu'], 'No submenu may still read "AI Providers"' );
}

set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
wp_test_set_filter( 'translatewp_pro_addon_active', static function () { return false; } );
$menus->add_admin_menu();
assert_true( false !== strpos( $GLOBALS['wp_test_submenu_titles']['translatewp-seo']['menu'], 'translatewp-pro-pill' ), 'SEO Tools keeps its Pro badge when the entitled site has no active addon' );

wp_test_set_filter( 'translatewp_pro_addon_active', static function () { return true; } );
$menus->add_admin_menu();
assert_true( 'SEO Tools' === $GLOBALS['wp_test_submenu_titles']['translatewp-seo']['menu'], 'SEO Tools has no Pro badge when the entitled addon is active' );

if ( $failures > 0 ) {
	fwrite( STDERR, "MenusAiLabelTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "MenusAiLabelTest ok\n" );
