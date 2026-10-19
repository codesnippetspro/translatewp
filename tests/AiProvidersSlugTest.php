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
		unset( $a, $b, $c, $d, $f );
		$GLOBALS['wp_test_submenu_slugs'][] = $e;
		return true;
	}
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) {
		unset( $parent );
		$GLOBALS['wp_test_removed_slugs'][] = $slug;
		return true;
	}
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$GLOBALS['wp_test_submenu_slugs']          = [];
$GLOBALS['wp_test_removed_slugs']          = [];
$GLOBALS['wp_test_options_store']['setup'] = [ 'ended' => true ];
$GLOBALS['wp_test_options_store']['languages'] = [ 'list' => [ 'nl' => [ 'locale' => 'nl', 'slug' => 'nl', 'active' => true, 'default' => true ] ] ];

$menus = new Menus( 'translatewp', '0.0.0-test' );
$menus->add_admin_menu();

$registered = $GLOBALS['wp_test_submenu_slugs'];

assert_true( [ 'translatewp', 'translatewp-seo', 'translatewp-lexicon', 'translatewp-settings' ] === $registered, 'Exactly the Dashboard, SEO Tools, Lexicon and Settings pages are registered in that order, got ' . implode( ',', $registered ) );
assert_true( [] === $GLOBALS['wp_test_removed_slugs'], 'Every registered page stays visible in the menu' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AiProvidersSlugTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "AiProvidersSlugTest ok\n" );
