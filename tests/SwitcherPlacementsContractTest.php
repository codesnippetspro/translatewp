<?php

declare(strict_types=1);

// The switcher placements are Free: the shortcode, the classic widget, the
// block and the nav-menu item each print the switcher host once, whatever a
// Pro addon of any version answers on its translatewp_inline_placements
// filter. Each addon state runs in its own process.

use TranslateWP\Components\Migrations;
use TranslateWP\Functionality\Admin\SwitcherMenuMetaBox;
use TranslateWP\Functionality\GutenbergSwitcher;
use TranslateWP\Functionality\SwitcherSurfaces;
use TranslateWP\Functionality\SwitcherWidget;

const ADDON_FILTER = 'translatewp_inline_placements';
const MENU_MARKER  = '#translatewp-language-switcher';

if ( ! isset( $argv[1] ) ) {
	$failed = false;
	foreach ( [ 'none', 'off', 'on' ] as $addon_state ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . $addon_state . ' 2>&1';
		$status  = 0;
		passthru( $command, $status );
		$failed = $failed || 0 !== $status;
	}

	if ( $failed ) {
		fwrite( STDERR, "SwitcherPlacementsContractTest: failed\n" );
		exit( 1 );
	}

	echo "SwitcherPlacementsContractTest: all assertions passed\n";
	exit( 0 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

define( 'TRANSLATEWP_VERSION', '1.0.0' );
define( 'TRANSLATEWP_PATH', dirname( __DIR__ ) . '/' );
define( 'TRANSLATEWP_URL', 'https://example.com/wp-content/plugins/translatewp/' );

$addon_state = (string) $argv[1];
$failures    = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures, $addon_state;
	if ( ! $condition ) {
		fwrite( STDERR, "[addon filter: {$addon_state}] {$message}\n" );
		$failures++;
	}
}

function host_count( string $html ): int {
	return substr_count( $html, 'data-twp-switcher' );
}

wp_test_reset( [] );

$filter_applied = false;
if ( 'none' !== $addon_state ) {
	add_filter(
		ADDON_FILTER,
		static function () use ( &$filter_applied, $addon_state ): bool {
			$filter_applied = true;
			return 'on' === $addon_state;
		}
	);
}

new SwitcherSurfaces( 'translatewp', TRANSLATEWP_VERSION );
new GutenbergSwitcher( 'translatewp', TRANSLATEWP_VERSION );
new SwitcherMenuMetaBox( 'translatewp', TRANSLATEWP_VERSION );
do_action( 'widgets_init' );
do_action( 'init' );
do_action( 'admin_init' );

// --- Shortcode ---
$shortcode = $GLOBALS['wp_test_shortcodes']['twp-languages'] ?? null;
assert_true( is_callable( $shortcode ), 'The twp-languages shortcode must be registered' );
$shortcode_output = is_callable( $shortcode ) ? (string) call_user_func( $shortcode, [] ) : '';
assert_true( 1 === host_count( $shortcode_output ), 'The shortcode must print the switcher host once, got ' . var_export( $shortcode_output, true ) );

// --- Classic widget ---
assert_true( in_array( SwitcherWidget::class, $GLOBALS['wp_test_widgets'] ?? [], true ), 'The switcher widget must be registered' );
ob_start();
( new SwitcherWidget() )->widget( [ 'before_widget' => '<div>', 'after_widget' => '</div>' ], [] );
$widget_output = (string) ob_get_clean();
assert_true( 1 === host_count( $widget_output ), 'The widget must print the switcher host once, got ' . $widget_output );

// --- Language Switcher block ---
$block = $GLOBALS['wp_test_blocks']['translatewp/language-switcher'] ?? null;
assert_true( is_array( $block ), 'The language switcher block must be registered' );
$block_output = is_array( $block ) ? (string) call_user_func( $block['render_callback'], [], '', null ) : '';
assert_true( 1 === host_count( $block_output ), 'The block must print the switcher host once, got ' . var_export( $block_output, true ) );

// --- Nav-menu item ---
assert_true( in_array( 'twp-languages', $GLOBALS['wp_test_meta_boxes'] ?? [], true ), 'Appearance > Menus must offer the switcher item' );
// WordPress adds the checked items of the element whose id is the button id
// without its "submit-" prefix, and wraps the box in an element with the box id.
$menu_box = end( $GLOBALS['wp_test_meta_box_definitions'] );
ob_start();
call_user_func( $menu_box['callback'] );
$menu_box_html = (string) ob_get_clean();
assert_true( false !== strpos( $menu_box_html, 'id="submit-' . $menu_box['id'] . '"' ), 'The Add to Menu button id must be "submit-" plus the box id ' . $menu_box['id'] );
assert_true( false !== strpos( $menu_box_html, 'value="' . MENU_MARKER . '"' ), 'The menu box must add an item with the switcher marker url' );
wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'  => 'custom',
	'menu-item-title' => 'Language Switcher',
	'menu-item-url'   => MENU_MARKER,
] );
wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'  => 'post_type',
	'menu-item-title' => 'About',
	'menu-item-url'   => 'https://example.com/about',
] );
// --- An update keeps the menu items a site stored ---
$GLOBALS['wp_test_nav_menus'] = [ 'main' ];
update_option( Migrations::VERSION_OPTION, '0.10.20' );
Migrations::maybe_run();
$stored_urls = array_map( static fn( $item ) => (string) $item->url, (array) wp_get_nav_menu_items( 'main' ) );
assert_true( in_array( MENU_MARKER, $stored_urls, true ), 'The update must delete no stored switcher menu item' );

// --- The stored item renders as the switcher ---
$menu_items = array_values( (array) apply_filters( 'wp_nav_menu_objects', wp_get_nav_menu_items( 'main' ), (object) [] ) );
$menu_html  = implode( '', array_map( static fn( $item ) => (string) $item->title, $menu_items ) );
$menu_urls  = array_map( static fn( $item ) => (string) $item->url, $menu_items );
assert_true( 2 === count( $menu_items ), 'The switcher menu item must stay in the menu, got ' . count( $menu_items ) . ' item(s)' );
assert_true( 1 === host_count( $menu_html ), 'The menu must print the switcher host once, got ' . $menu_html );
assert_true( ! in_array( MENU_MARKER, $menu_urls, true ), 'The switcher menu item must not link to its marker' );
assert_true( in_array( 'https://example.com/about', $menu_urls, true ), 'Other menu items must keep their url' );

// --- No Pro filter is read ---
assert_true( false === $filter_applied, 'The free plugin must not apply the ' . ADDON_FILTER . ' filter' );

if ( $failures > 0 ) {
	fwrite( STDERR, "[addon filter: {$addon_state}] {$failures} assertion(s) failed\n" );
	exit( 1 );
}
