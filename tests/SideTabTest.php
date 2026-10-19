<?php

declare(strict_types=1);

use TranslateWP\Components\NetworkScope;
use TranslateWP\Components\Options;
use TranslateWP\Components\SettingsScope;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.0.0-test' );
}

$failures = 0;
function side_tab_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

/**
 * Sets the plan and the addon the way a site would have them.
 *
 * @param bool $entitled Whether the site's plan is entitled to Pro.
 * @param bool $active   Whether the Pro addon is active.
 */
function side_tab_plan( bool $entitled, bool $active ): void {
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $entitled ? 'pro' : 'free', 'entitled' => $entitled ], 3600 );
	wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => $active );
}

/**
 * Starts a single site with the given switcher settings.
 *
 * @param array $switcher The stored switcher settings.
 */
function side_tab_site( array $switcher ): Admin {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ], 'languages' => [ 'switcher' => $switcher ] ] );
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
	return new Admin( 'translatewp', TRANSLATEWP_VERSION );
}

/**
 * Saves switcher settings through the settings form.
 *
 * @param Admin $admin The endpoint.
 * @param array $data  The submitted switcher fields.
 */
function side_tab_save( Admin $admin, array $data ) {
	return $admin->update_data( new WP_REST_Request( 'POST', '', [ 'option' => 'languages.switcher', 'data' => $data ] ) );
}

/**
 * Drags the switcher through the position endpoint.
 *
 * @param Admin  $admin    The endpoint.
 * @param array  $position The dropped position.
 * @param string $kind     The payload kind, or '' for none.
 * @param string $device   The screen width.
 */
function side_tab_drag( Admin $admin, array $position, string $kind = 'side_tab', string $device = 'desktop' ) {
	$params = [ 'position' => $position, 'device' => $device ];
	if ( '' !== $kind ) {
		$params['kind'] = $kind;
	}
	return $admin->save_switcher_position( new WP_REST_Request( 'POST', '', $params ) );
}

/**
 * Whether an endpoint answered with success.
 *
 * @param mixed $result The endpoint's answer.
 */
function side_tab_ok( $result ): bool {
	return is_array( $result ) && true === ( $result['success'] ?? false );
}

function side_tab_served(): array {
	return ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->get_frontend_options()['languages']['switcher'];
}

function side_tab_purges(): int {
	return count( array_filter( $GLOBALS['wp_test_actions_fired'] ?? [], static fn( array $event ): bool => 'translatewp_page_cache_purged' === $event['tag'] ) );
}

// --- the type is a Pro choice ---
$admin = side_tab_site( [ 'type' => 'dropdown' ] );
side_tab_plan( true, true );
side_tab_save( $admin, [ 'type' => 'side_tab' ] );
side_tab_check( 'side_tab' === Options::get( 'languages.switcher.type' ), 'With Pro the Side tab is stored' );
side_tab_check( 'side_tab' === side_tab_served()['type'], 'With Pro the site renders the Side tab' );
side_tab_check( 'side_tab' === side_tab_served()['selected_type'], 'With Pro the page is told the Side tab is selected' );

foreach ( [ [ false, true, 'an unentitled site with the addon' ], [ true, false, 'an entitled site without the addon' ], [ false, false, 'a Free site' ] ] as [ $entitled, $active, $who ] ) {
	$admin = side_tab_site( [ 'type' => 'dropdown' ] );
	side_tab_plan( $entitled, $active );
	side_tab_save( $admin, [ 'type' => 'side_tab' ] );
	side_tab_check( 'dropdown' === Options::get( 'languages.switcher.type' ), "{$who} cannot store the Side tab" );

	$admin = side_tab_site( [ 'type' => 'side_tab' ] );
	side_tab_plan( $entitled, $active );
	side_tab_check( 'dropdown' === side_tab_served()['type'], "{$who} renders the dropdown when the Side tab is stored" );
	side_tab_check( 'side_tab' === side_tab_served()['selected_type'], "{$who} still tells the page the Side tab is the selected type" );
	side_tab_check( 'side_tab' === Options::get( 'languages.switcher.type' ), "{$who} keeps the stored Side tab for a renewal" );
}

$admin = side_tab_site( [ 'type' => 'native_select' ] );
side_tab_plan( false, false );
side_tab_check( 'native_select' === side_tab_served()['selected_type'] && 'native_select' === side_tab_served()['type'], 'The native dropdown is selected and served as it is' );
$admin = side_tab_site( [ 'type' => 'carousel' ] );
side_tab_check( 'dropdown' === side_tab_served()['selected_type'] && 'dropdown' === side_tab_served()['type'], 'An unknown stored type is the dropdown, selected and served' );

// --- positions: the edge and a share of it, kept apart per screen width ---
$admin = side_tab_site( [ 'type' => 'side_tab', 'floating_position' => [ 'anchor' => 'top-left', 'x' => 5, 'y' => 6 ] ] );
side_tab_plan( true, true );
$result = side_tab_drag( $admin, [ 'edge' => 'left', 'offset' => 30 ] );
side_tab_check( side_tab_ok( $result ) && [ 'edge' => 'left', 'offset' => 30 ] === ( is_array( $result ) ? ( $result['position'] ?? null ) : null ), 'A Side tab drop is saved and returned' );
side_tab_check( [ 'edge' => 'left', 'offset' => 30 ] === Options::get( 'languages.switcher.side_tab_position' ), 'The wide position is stored' );
side_tab_check( [ 'anchor' => 'top-left', 'x' => 5, 'y' => 6 ] === Options::get( 'languages.switcher.floating_position' ), 'The dropdown keeps its own place' );
side_tab_drag( $admin, [ 'edge' => 'bottom', 'offset' => 150 ], 'side_tab', 'mobile' );
side_tab_check( [ 'edge' => 'bottom', 'offset' => 100 ] === Options::get( 'languages.switcher.side_tab_position_mobile' ), 'A phone drop is kept apart and its share is clamped' );
side_tab_check( [ 'edge' => 'left', 'offset' => 30 ] === Options::get( 'languages.switcher.side_tab_position' ), 'A phone drop leaves the wide position alone' );
side_tab_drag( $admin, [ 'edge' => 'right', 'offset' => 33.333 ] );
side_tab_check( [ 'edge' => 'right', 'offset' => 33.3 ] === Options::get( 'languages.switcher.side_tab_position' ), 'A share keeps a tenth of a percent, got ' . wp_json_encode( Options::get( 'languages.switcher.side_tab_position' ) ) );
side_tab_drag( $admin, [ 'edge' => 'top', 'offset' => 40 ] );
side_tab_check( [ 'edge' => 'top', 'offset' => 40 ] === Options::get( 'languages.switcher.side_tab_position' ), 'A wide screen allows the top edge' );

foreach ( [ [ 'edge' => 'top', 'offset' => 40 ], [ 'edge' => 'middle', 'offset' => 4 ], [ 'edge' => 'left' ], [ 'edge' => 'left', 'offset' => [] ], [ 'edge' => 'left', 'offset' => INF ], [ 'anchor' => 'top-left', 'x' => 1, 'y' => 1 ] ] as $invalid ) {
	$result = side_tab_drag( $admin, $invalid, 'side_tab', 'mobile' );
	side_tab_check( is_wp_error( $result ) && 400 === $result->get_error_data()['status'], 'A malformed or top-edge phone Side tab drop is refused with 400: ' . wp_json_encode( $invalid ) );
}
side_tab_check( [ 'edge' => 'bottom', 'offset' => 100 ] === Options::get( 'languages.switcher.side_tab_position_mobile' ), 'Refused phone drops write nothing' );

// --- the server picks the key from the type the site renders ---
$result = side_tab_drag( $admin, [ 'anchor' => 'bottom-right', 'x' => 9, 'y' => 9 ], 'floating' );
side_tab_check( is_wp_error( $result ) && 'position_mismatch' === $result->get_error_code() && 400 === $result->get_error_data()['status'], 'A dropdown drop on a site rendering the Side tab is refused' );
$result = side_tab_drag( $admin, [ 'anchor' => 'bottom-right', 'x' => 9, 'y' => 9 ], '' );
side_tab_check( is_wp_error( $result ) && 'position_mismatch' === $result->get_error_code(), 'A drop without a kind counts as the dropdown and is refused too' );

$admin = side_tab_site( [ 'type' => 'dropdown' ] );
side_tab_plan( true, true );
$result = side_tab_drag( $admin, [ 'edge' => 'left', 'offset' => 30 ] );
side_tab_check( is_wp_error( $result ) && 'position_mismatch' === $result->get_error_code(), 'A Side tab drop on a site rendering the dropdown is refused' );
$result = side_tab_drag( $admin, [ 'anchor' => 'bottom-right', 'x' => 9, 'y' => 9 ], '' );
side_tab_check( side_tab_ok( $result ), 'The dropdown drop without a kind still saves (older clients)' );

// --- after a lapse the fallback dropdown still drags ---
$admin = side_tab_site( [ 'type' => 'side_tab', 'side_tab_position' => [ 'edge' => 'right', 'offset' => 20 ] ] );
side_tab_plan( true, false );
$result = side_tab_drag( $admin, [ 'anchor' => 'bottom-left', 'x' => 3, 'y' => 4 ], 'floating' );
side_tab_check( side_tab_ok( $result ), 'After a lapse the fallback dropdown drag is accepted' );
side_tab_check( [ 'anchor' => 'bottom-left', 'x' => 3, 'y' => 4 ] === Options::get( 'languages.switcher.floating_position' ), 'It moves the dropdown place' );
side_tab_check( [ 'edge' => 'right', 'offset' => 20 ] === Options::get( 'languages.switcher.side_tab_position' ), 'It keeps the Side tab place' );
$result = side_tab_drag( $admin, [ 'edge' => 'left', 'offset' => 30 ] );
side_tab_check( is_wp_error( $result ) && 'pro_required' === $result->get_error_code() && 403 === $result->get_error_data()['status'], 'A Side tab drop without Pro is refused with 403' );

// --- a drag is not a settings change; a reset is, and resets only its own type ---
$both = [
	'type'                       => 'side_tab',
	'floating_position'          => [ 'anchor' => 'top-left', 'x' => 5, 'y' => 6 ],
	'floating_position_mobile'   => [ 'anchor' => 'top-left', 'x' => 7, 'y' => 8 ],
	'side_tab_position'          => [ 'edge' => 'right', 'offset' => 20 ],
	'side_tab_position_mobile'   => [ 'edge' => 'left', 'offset' => 70 ],
];
$admin = side_tab_site( $both );
side_tab_plan( true, true );
$before = side_tab_purges();
side_tab_drag( $admin, [ 'edge' => 'left', 'offset' => 31 ] );
side_tab_drag( $admin, [ 'edge' => 'bottom', 'offset' => 32 ], 'side_tab', 'mobile' );
side_tab_check( $before === side_tab_purges(), 'A Side tab drag purges no page cache' );
side_tab_save( $admin, [ 'side_tab_position' => null ] );
side_tab_check( null === Options::get( 'languages.switcher.side_tab_position' ) && null === Options::get( 'languages.switcher.side_tab_position_mobile' ), 'Reset of the Side tab removes both of its places' );
side_tab_check( $both['floating_position'] === Options::get( 'languages.switcher.floating_position' ) && $both['floating_position_mobile'] === Options::get( 'languages.switcher.floating_position_mobile' ), 'Reset of the Side tab leaves the dropdown places' );
side_tab_check( $before + 1 === side_tab_purges(), 'A Side tab reset purges the page caches' );

$admin = side_tab_site( array_merge( $both, [ 'type' => 'dropdown' ] ) );
side_tab_plan( true, true );
side_tab_save( $admin, [ 'floating_position' => null ] );
side_tab_check( null === Options::get( 'languages.switcher.floating_position' ) && null === Options::get( 'languages.switcher.floating_position_mobile' ), 'Reset of the dropdown removes both of its places' );
side_tab_check( $both['side_tab_position'] === Options::get( 'languages.switcher.side_tab_position' ) && $both['side_tab_position_mobile'] === Options::get( 'languages.switcher.side_tab_position_mobile' ), 'Reset of the dropdown leaves the Side tab places' );

// --- a phone dropdown drag does not purge either ---
$admin = side_tab_site( [ 'type' => 'dropdown' ] );
side_tab_plan( false, false );
$before = side_tab_purges();
side_tab_drag( $admin, [ 'anchor' => 'top-left', 'x' => 1, 'y' => 2 ], 'floating', 'mobile' );
side_tab_check( $before === side_tab_purges(), 'A phone dropdown drag purges no page cache' );

// --- multisite: the positions are a subsite's own placement ---
foreach ( [ 'side_tab_position', 'side_tab_position_mobile', 'floating_position_mobile' ] as $key ) {
	side_tab_check( 'switcher_placement' === SettingsScope::group_for( 'translatewp_languages', 'switcher', $key ), "{$key} belongs to the subsite's switcher placement" );
}

/**
 * Starts a network of three sites.
 */
function side_tab_network(): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 1;
	$GLOBALS['wp_test_super_admin']  = true;
	$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
	];
	$GLOBALS['wp_test_options_store']['languages']        = [ 'switcher' => [ 'type' => 'side_tab', 'side_tab_position' => [ 'edge' => 'left', 'offset' => 40 ], 'side_tab_position_mobile' => [ 'edge' => 'bottom', 'offset' => 60 ], 'floating_position' => [ 'anchor' => 'top-left', 'x' => 1, 'y' => 1 ] ] ];
	$GLOBALS['wp_test_options_store']['blog_2_languages'] = [ 'switcher' => [ 'type' => 'dropdown' ] ];
}

$type_post = [ 'op' => 'value', 'group' => 'switcher_appearance', 'apply_to' => 'all', 'values' => [ 'languages.switcher.type' => 'side_tab' ] ];
foreach ( [ [ false, true, 'an unentitled network with the addon' ], [ true, false, 'an entitled network without the addon' ] ] as [ $entitled, $active, $who ] ) {
	side_tab_network();
	side_tab_plan( $entitled, $active );
	NetworkScope::apply_post( $type_post );
	side_tab_check( 'dropdown' === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['type'] ?? null ), "{$who} cannot give subsites the Side tab" );
	side_tab_check( 'pro-required' === NetworkScope::apply_post( [ 'op' => 'switcher_position' ] ), "{$who} cannot apply the main site's position" );
}
side_tab_network();
side_tab_plan( true, true );
side_tab_check( 'scope-saved' === NetworkScope::apply_post( $type_post ), 'With Pro the network saves the Side tab' );
side_tab_check( 'side_tab' === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['type'] ?? null ), 'and a subsite takes it' );
side_tab_check( 'position-applied' === NetworkScope::apply_post( [ 'op' => 'switcher_position' ] ), 'With Pro the main site position is applied' );
$sub = $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher'];
side_tab_check( [ 'edge' => 'left', 'offset' => 40 ] === ( $sub['side_tab_position'] ?? null ) && [ 'edge' => 'bottom', 'offset' => 60 ] === ( $sub['side_tab_position_mobile'] ?? null ), 'Apply position copies both Side tab places of a main site on the Side tab' );
side_tab_check( ! isset( $sub['floating_position'] ), 'and not the dropdown place' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SideTabTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "SideTabTest: all assertions passed\n";
