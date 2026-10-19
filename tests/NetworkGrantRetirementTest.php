<?php

declare(strict_types=1);

use TranslateWP\Components\CleanupInventory;
use TranslateWP\Components\Migrations;
use TranslateWP\Components\MultisiteSettings;
use TranslateWP\Components\NetworkAdmin;
use TranslateWP\Components\Sites;
use TranslateWP\Functionality\Admin\MultisiteSettingsPage;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.19' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function network_setup(): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 1;
	$GLOBALS['wp_test_super_admin']  = true;
	$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
	];
}

// --- the network page registers no grant, access or copy handlers and no delegated page ---
network_setup();
new MultisiteSettingsPage( 'translatewp', TRANSLATEWP_VERSION );
foreach ( [ 'translatewp_apply_multisite_settings', 'translatewp_grant_multisite_settings', 'translatewp_revoke_multisite_settings', 'translatewp_save_multisite_access' ] as $action ) {
	assert_true( ! isset( $GLOBALS['wp_test_filters'][ 'admin_post_' . $action ] ), $action . ' is no longer handled' );
}
assert_true( isset( $GLOBALS['wp_test_filters']['admin_post_translatewp_save_settings_scope'] ), 'the scope form is still handled' );
assert_true( ! isset( $GLOBALS['wp_test_filters']['admin_menu'] ), 'no delegated apply page is added to subsite menus' );

// --- the grant and copy code paths are gone ---
foreach ( [ 'network_apply_grants', 'replace_network_apply_grants', 'current_user_can_apply_network_wide' ] as $method ) {
	assert_true( ! method_exists( Sites::class, $method ), 'Sites::' . $method . ' is gone' );
}
foreach ( [ 'apply_to_site', 'apply_network_wide' ] as $method ) {
	assert_true( ! method_exists( MultisiteSettings::class, $method ), 'MultisiteSettings::' . $method . ' is gone' );
}

// --- the network page carries no access control ---
network_setup();
$data = NetworkAdmin::page_data();
assert_true( ! isset( $data['access'] ), 'the page carries no access control data' );
assert_true( ! isset( $data['actions']['saveAccess'] ) && ! isset( $data['actions']['nonces']['saveAccess'] ), 'the page carries no access form action' );

// --- stored grants are removed once on upgrade, and uninstall still clears the old records ---
network_setup();
$GLOBALS['wp_test_site_options']['translatewp_network_apply_grants'] = [ 4, 9 ];
update_option( Migrations::VERSION_OPTION, '0.10.18' );
Migrations::maybe_run();
assert_true( ! isset( $GLOBALS['wp_test_site_options']['translatewp_network_apply_grants'] ), 'the stored grants are deleted on upgrade' );
assert_true( in_array( 'translatewp_network_apply_grants', CleanupInventory::NETWORK_OPTIONS, true ), 'uninstall clears any grants left behind' );

if ( $failures > 0 ) {
	fwrite( STDERR, "NetworkGrantRetirementTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "NetworkGrantRetirementTest: all assertions passed\n";
