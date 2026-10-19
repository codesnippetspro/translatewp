<?php

declare(strict_types=1);

use TranslateWP\Components\ProEligibility;
use TranslateWP\Functionality\Admin\Menus;
use TranslateWP\Functionality\Pro;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $a, $b, $c, $d, $e, $f = '', $g = null ) { unset( $a, $b, $c, $d, $e, $f, $g ); return true; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $a, $b, $c, $d, $e, $f = null ) { unset( $a, $b, $c, $d, $e, $f ); return true; }
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) { unset( $parent, $slug ); return true; }
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function free_eligibility(): array {
	return [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				'plan'     => 'free',
				'entitled' => false,
				'latest'   => [
					'version'     => '1.2.0',
					'released_at' => '2026-09-12T10:00:00Z',
				],
			]
		),
	];
}

function stored_pro_record(): array {
	return [
		'plan'               => 'pro',
		'entitled'           => true,
		'latest_version'     => '1.2.0',
		'latest_released_at' => '2026-09-12T10:00:00Z',
		'package_url'        => '',
		'payment_pending'    => false,
		'capabilities'       => [ 'seo' => true, 'custom_style' => true, 'byok' => true ],
		'shape'              => 2,
	];
}

function sidebar_labels(): array {
	$GLOBALS['submenu'] = [ 'translatewp' => [] ];
	$menus = new Menus( 'translatewp', '0.0.0-test' );
	$menus->add_admin_menu();

	return array_map( static fn( $entry ) => (string) $entry[0], $GLOBALS['submenu']['translatewp'] );
}

function admin_menu_page_load( Pro $pro ): void {
	foreach ( $GLOBALS['wp_test_action_registrations'] as $registration ) {
		if ( 'admin_menu' === $registration['tag'] && [ $pro, 'maybe_refresh_eligibility' ] === $registration['callback'] ) {
			$registration['callback']();
		}
	}
}

$connected = [
	'site'  => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
	'setup'     => [ 'ended' => true ],
	'languages' => [ 'list' => [ 'nl' => [ 'locale' => 'nl', 'slug' => 'nl', 'active' => true, 'default' => true ] ] ],
];

// --- the eligibility check runs before the sidebar is built ---
wp_test_reset( $connected );
$pro          = new Pro( 'translatewp', '0.0.0-test' );
$before_menus = array_filter(
	$GLOBALS['wp_test_action_registrations'],
	static fn( $registration ) => 'admin_menu' === $registration['tag']
		&& [ $pro, 'maybe_refresh_eligibility' ] === $registration['callback']
		&& $registration['priority'] < 10
);
assert_true( 1 === count( $before_menus ), 'the eligibility check hooks admin_menu ahead of the sidebar' );
$network_before_menus = array_filter(
	$GLOBALS['wp_test_action_registrations'],
	static fn( $registration ) => 'network_admin_menu' === $registration['tag']
		&& [ $pro, 'maybe_refresh_eligibility' ] === $registration['callback']
		&& $registration['priority'] < 10
);
assert_true( 1 === count( $network_before_menus ), 'the eligibility check hooks network_admin_menu ahead of the network sidebar' );

// --- a stored Pro record for an account that is now free does not keep "Install Pro" on any admin page ---
foreach ( [ 'translatewp', '' ] as $page ) {
	wp_test_reset( $connected );
	$GLOBALS['wp_test_options_store']['pro_capabilities_last_known'] = stored_pro_record();
	$GLOBALS['wp_test_remote_get_response']                          = free_eligibility();
	$_GET = '' === $page ? [] : [ 'page' => $page ];

	$pro = new Pro( 'translatewp', '0.0.0-test' );
	admin_menu_page_load( $pro );
	$labels = sidebar_labels();

	$where = '' === $page ? 'another admin page' : 'a TranslateWP page';
	assert_true( ! in_array( 'Install Pro', $labels, true ), 'on ' . $where . ' a free account is not offered "Install Pro", got ' . implode( ', ', $labels ) );
	assert_true( in_array( 'Upgrade', $labels, true ), 'on ' . $where . ' a free account is offered "Upgrade", got ' . implode( ', ', $labels ) );
}

// --- other admin pages do not ask the service when nothing Pro is stored ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = free_eligibility();
$_GET = [];
admin_menu_page_load( new Pro( 'translatewp', '0.0.0-test' ) );
assert_true( [] === $GLOBALS['wp_test_remote_get_urls'], 'another admin page with nothing Pro stored makes no eligibility request' );

// --- other admin pages do not ask again while the cached answer is fresh ---
wp_test_reset( $connected );
set_transient( 'translatewp_pro_eligibility', stored_pro_record(), 3600 );
$GLOBALS['wp_test_remote_get_response'] = free_eligibility();
admin_menu_page_load( new Pro( 'translatewp', '0.0.0-test' ) );
assert_true( [] === $GLOBALS['wp_test_remote_get_urls'], 'a fresh cached answer makes no eligibility request' );
$_GET = [];

if ( $failures > 0 ) {
	fwrite( STDERR, "ProMenuFreshnessTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ProMenuFreshnessTest: all assertions passed\n";
