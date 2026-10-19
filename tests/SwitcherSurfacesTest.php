<?php

declare(strict_types=1);

use TranslateWP\Functionality\SwitcherSurfaces;
use TranslateWP\Functionality\SwitcherWidget;

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

wp_test_reset( [] );
new SwitcherSurfaces( 'translatewp', TRANSLATEWP_VERSION );
do_action( 'widgets_init' );
do_action( 'admin_init' );

// --- Shortcode registered and renders the placeholder ---
assert_true( isset( $GLOBALS['wp_test_shortcodes']['twp-languages'] ), 'Shortcode twp-languages must be registered' );
$shortcode_output = (string) call_user_func( $GLOBALS['wp_test_shortcodes']['twp-languages'], [] );
assert_true( SwitcherSurfaces::PLACEHOLDER === $shortcode_output, 'The shortcode must render the shared placeholder, got ' . $shortcode_output );

// --- Widget registered and renders the placeholder ---
assert_true( in_array( SwitcherWidget::class, $GLOBALS['wp_test_widgets'] ?? [], true ), 'Switcher widget must be registered' );
$widget = new SwitcherWidget();
$widget_definition = end( $GLOBALS['wp_test_widget_definitions'] );
assert_true( 'TranslateWP' === ( $widget_definition['name'] ?? '' ), 'Switcher widget must use the TranslateWP title' );
ob_start();
$widget->render_admin_icon();
$widget_icon_output = (string) ob_get_clean();
assert_true( false !== strpos( $widget_icon_output, '\\f326' ) && false !== strpos( $widget_icon_output, 'font-family: dashicons' ), 'Switcher widget must use the translation dashicon' );
ob_start();
$widget->widget( [ 'before_widget' => '<div>', 'after_widget' => '</div>' ], [] );
$widget_output = (string) ob_get_clean();
assert_true( false !== strpos( $widget_output, 'data-twp-switcher' ), 'Widget must render the placeholder, got ' . $widget_output );

// --- A menu item with the switcher marker renders the placeholder ---
wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'  => 'custom',
	'menu-item-title' => 'Language Switcher',
	'menu-item-url'   => SwitcherSurfaces::MENU_ITEM_URL_MARKER,
] );
wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'  => 'post_type',
	'menu-item-title' => 'About',
	'menu-item-url'   => 'https://example.com/about',
] );
$filtered = array_values( (array) apply_filters( 'wp_nav_menu_objects', wp_get_nav_menu_items( 'main' ), (object) [] ) );
assert_true( SwitcherSurfaces::PLACEHOLDER === ( $filtered[0]->title ?? '' ), 'The switcher menu item must render the shared placeholder' );
assert_true( '' === ( $filtered[0]->url ?? null ), 'The switcher menu item must not link anywhere' );
assert_true( 'About' === ( $filtered[1]->title ?? '' ) && 'https://example.com/about' === ( $filtered[1]->url ?? '' ), 'Other menu items must keep their title and url' );
assert_true( false === apply_filters( 'wp_nav_menu_objects', false, (object) [] ), 'A menu with no items must pass through' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SwitcherSurfacesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SwitcherSurfacesTest: all assertions passed\n";
