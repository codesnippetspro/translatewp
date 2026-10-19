<?php

declare(strict_types=1);

use TranslateWP\Components\SettingsScope;
use TranslateWP\Entities\Scripts\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.0.0-test' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function subsite_admin( bool $super = false ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 2;
	$GLOBALS['wp_test_super_admin']  = $super;
	$GLOBALS['wp_test_caps']         = $super ? [ 'manage_options', 'manage_network_options' ] : [ 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
	];
}

// --- a subsite admin sees which groups the network has locked ---
subsite_admin();
SettingsScope::set_group_state( 'switcher_appearance', 'locked' );
$scope = SettingsScope::subsite_scope();
assert_true( true === ( $scope['switcher_appearance'] ?? null ), 'a locked group reads locked for a subsite admin, got ' . wp_json_encode( $scope ) );
assert_true( false === ( $scope['style_tone'] ?? null ), 'an open group reads unlocked' );
assert_true( true === ( $scope['account'] ?? null ) && true === ( $scope['uninstall'] ?? null ), 'network-only groups read locked' );
assert_true( true === ( $scope['ai_crawlers'] ?? null ), 'a delegable group starts locked' );
assert_true( ! array_key_exists( 'translation_switch', $scope ), 'there is no translation switch group' );
assert_true( ! array_key_exists( 'target_languages', $scope ), 'per-subsite groups are not listed' );

$context = ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->context_data();
assert_true( false !== strpos( $context, '"settings_scope":{' ) && false !== strpos( $context, '"switcher_appearance":true' ), 'the admin bootstrap carries the scope, got ' . $context );
assert_true( false !== strpos( $context, '"network_settings_url":""' ), 'a subsite admin gets no network link' );

// --- a super admin's network link opens the Settings tab ---
subsite_admin( true );
$context = ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->context_data();
assert_true( 1 === preg_match( '/"network_settings_url":"[^"]*translatewp-network-settings#settings"/', $context ), 'the network link opens the Settings tab, got ' . $context );

// --- a super admin is never locked out ---
subsite_admin( true );
SettingsScope::set_group_state( 'switcher_appearance', 'locked' );
assert_true( [] === array_filter( SettingsScope::subsite_scope() ), 'nothing reads locked for a super admin' );

// --- a single site shows no lock state ---
wp_test_reset( [] );
assert_true( [] === SettingsScope::subsite_scope(), 'a single site has no scope' );
assert_true( false !== strpos( ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->context_data(), '"settings_scope":{}' ), 'a single site bootstraps an empty scope' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SubsiteScopeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SubsiteScopeTest: all assertions passed\n";
