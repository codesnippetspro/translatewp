<?php

declare(strict_types=1);

use TranslateWP\Functionality\Admin\Menus;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $a, $b, $c, $d, $e, $f = '', $g = null ) { unset( $a, $b, $c, $d, $e, $f, $g ); return true; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $a, $b, $c, $d, $e, $f = null ) { unset( $a, $b, $c, $d, $e, $f ); return true; }
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) { unset( $parent, $slug ); return true; }
}

if ( ! function_exists( 'esc_js' ) ) {
	// As WordPress does: double quotes become entities, single quotes are slashed.
	function esc_js( $text ): string {
		return str_replace( "'", "\\'", htmlspecialchars( (string) $text, ENT_COMPAT, 'UTF-8' ) );
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

$connected = [
	'site'      => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
	'setup'     => [ 'ended' => true ],
	'languages' => [ 'list' => [ 'nl' => [ 'locale' => 'nl', 'slug' => 'nl', 'active' => true, 'default' => true ] ] ],
];

/**
 * The extra sidebar entries for one Pro state, as [ label => href ].
 */
function pro_entries( ?string $installed_version, bool $active, bool $entitled ): array {
	global $connected;
	wp_test_reset( $connected );
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $entitled ? 'pro' : 'free', 'entitled' => $entitled ], 3600 );
	wp_test_set_filter( 'translatewp_pro_installed_version', static function () use ( $installed_version ) { return $installed_version ?? ''; } );
	wp_test_set_filter( 'translatewp_pro_addon_active', static function () use ( $active ) { return $active; } );

	$GLOBALS['submenu'] = [ 'translatewp' => [] ];
	( new Menus( 'translatewp', '0.0.0-test' ) )->add_admin_menu();

	$entries = [];
	foreach ( $GLOBALS['submenu']['translatewp'] as $entry ) {
		$entries[ (string) $entry[0] ] = (string) $entry[2];
	}

	return $entries;
}

$dashboard = admin_url( 'admin.php?page=translatewp#overview' );

// --- entitled, addon absent: "Install Pro" opens the Dashboard, where the install card is ---
$entries = pro_entries( null, false, true );
assert_true( $dashboard === ( $entries['Install Pro'] ?? '' ), 'an entitled site without the addon gets an Install Pro submenu to the Dashboard, got ' . wp_json_encode( $entries ) );
assert_true( ! isset( $entries['Upgrade'] ), 'an entitled site is never offered Upgrade' );

// --- entitled, addon installed but inactive: "Activate Pro" opens the Dashboard ---
$entries = pro_entries( '1.2.0', false, true );
assert_true( $dashboard === ( $entries['Activate Pro'] ?? '' ), 'an entitled site with the addon installed but inactive gets an Activate Pro submenu to the Dashboard, got ' . wp_json_encode( $entries ) );
assert_true( ! isset( $entries['Install Pro'] ) && ! isset( $entries['Upgrade'] ), 'an installed but inactive addon is offered neither Install Pro nor Upgrade' );

// --- entitled, addon active: nothing ---
$entries = pro_entries( '1.2.0', true, true );
assert_true( [] === $entries, 'an entitled site with the addon active gets no Pro submenu, got ' . wp_json_encode( $entries ) );

// --- free account: "Upgrade" ---
$entries = pro_entries( null, false, false );
assert_true( isset( $entries['Upgrade'] ) && ! isset( $entries['Install Pro'] ) && ! isset( $entries['Activate Pro'] ), 'a free account gets only Upgrade, got ' . wp_json_encode( $entries ) );

// --- the Upgrade link is found by a selector the browser can parse ---
// A style or script element decodes no entities, so an escaped quote in the
// selector makes it invalid: the style never applies and querySelector throws.
$menus = new Menus( 'translatewp', '0.0.0-test' );
ob_start();
$menus->render_upgrade_menu_styles();
$menus->render_upgrade_menu_script();
$printed = (string) ob_get_clean();
assert_true( false === strpos( $printed, '&quot;' ) && false === strpos( $printed, '&#039;' ), 'the upgrade link selector must print no escaped quote, got ' . $printed );
assert_true( 1 === preg_match( '/querySelector\( \'#adminmenu a\[href\*=([A-Za-z0-9_-]+)\]\' \)/', $printed, $selector ), 'the script must select the upgrade link by an unquoted href fragment, got ' . $printed );
assert_true( false !== strpos( (string) ( $entries['Upgrade'] ?? '' ), (string) ( $selector[1] ?? "\0" ) ), 'the Upgrade link href must contain the fragment the selector looks for' );
assert_true( 2 === substr_count( $printed, '#adminmenu a[href*=' . ( $selector[1] ?? '' ) . ']' ) - 1, 'the two style rules must use the same selector as the script' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ProMenuActionTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ProMenuActionTest: all assertions passed\n";
