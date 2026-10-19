<?php

declare(strict_types=1);

use TranslateWP\Components\CleanupInventory;
use TranslateWP\Components\Options;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Functionality\Endpoints\Management;
use TranslateWP\Includes\Lyfecycle;

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

$connected_options = [
	'site' => [
		'id'                => '00000000-0000-4000-8000-000000000001',
		'token'             => 'secret-site-token',
		'home_url'          => 'https://example.com',
		'account_label'     => 'ops@translatewp.ai',
		'connection_status' => 'connected',
	],
];

// --- Inventory completeness: every Options schema root is covered ---
$schema_roots = [ 'setup', 'site', 'languages', 'translations', 'ai', 'advanced' ];
foreach ( $schema_roots as $root ) {
	assert_true(
		in_array( 'translatewp_' . $root, CleanupInventory::option_names(), true ),
		"Inventory must cover option root translatewp_{$root}"
	);
}

// --- Inventory completeness: every registered CPT slug is covered ---
$cpt_slugs = [ 'lexicon', 'translatewp_metric', 'twp_index_item' ];
foreach ( $cpt_slugs as $slug ) {
	assert_true(
		in_array( $slug, CleanupInventory::CPT_SLUGS, true ),
		"Inventory must cover CPT slug {$slug}"
	);
}

// --- Inventory completeness: every scheduled hook name is covered ---
$scheduled_hooks = [
	'translatewp_language_sync_retry',
	'translatewp_context_scan_run',
	'translatewp_index_queue_drain',
	'translatewp_lexicon_stale_debounce',
	'translatewp_settings_rebuild_debounce',
	'translatewp_rewarm_debounce',
];
foreach ( $scheduled_hooks as $hook ) {
	assert_true(
		in_array( $hook, CleanupInventory::SCHEDULED_HOOKS, true ),
		"Inventory must cover scheduled hook {$hook}"
	);
}

// --- Restart endpoint: clears setup flag + transient, sets restart marker, keeps credentials ---
wp_test_reset( $connected_options );
Options::update( 'setup.ended', true );
set_transient( 'translatewp_pairing_verified', 1, 600 );
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/restart-setup' );
$management->restart_setup( $request );
assert_true( false === Options::get( 'setup.ended', true ), 'Restart must clear setup.ended' );
assert_true( true === Options::get( 'setup.restarted', false ), 'Restart must set the restart marker' );
assert_true( false === get_transient( 'translatewp_pairing_verified' ), 'Restart must delete the pairing-verified transient' );
assert_true( 'secret-site-token' === Options::get( 'site.token', '' ), 'Restart must keep credentials intact' );

// --- Reset endpoint: wipes the full inventory ---
wp_test_reset( $connected_options );
Options::update( 'setup.ended', true );
Options::update( 'languages.list', [ 'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ] ] );
Options::update( 'translations.settings', [ 'auto_translate' => true ] );
Options::update( 'advanced.delete_on_uninstall', true );
update_option( 'translatewp_version', TRANSLATEWP_VERSION );
update_option( \TranslateWP\Components\AltTextNotice::FLAG_OPTION, 1 );
set_transient( 'translatewp_pairing_verified', 1, 600 );
$GLOBALS['wp_test_scheduled']['translatewp_language_sync_retry'] = time() + 60;
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'success' => true ] ),
];
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' );
$management->reset_data( $request );
assert_true( 'sentinel' === get_option( 'translatewp_setup', 'sentinel' ), 'Reset must wipe the setup option' );
assert_true( [] === Options::get( 'languages.list', 'sentinel' ) || 'sentinel' === Options::get( 'languages.list', 'sentinel' ), 'Reset must wipe the languages option' );
assert_true( false === get_transient( 'translatewp_pairing_verified' ), 'Reset must wipe the pairing transient' );
assert_true( false === wp_next_scheduled( 'translatewp_language_sync_retry' ), 'Reset must clear scheduled hooks' );
assert_true( 'sentinel' === get_option( 'translatewp_version', 'sentinel' ), 'Reset must wipe the version marker' );
assert_true( 'sentinel' === get_option( \TranslateWP\Components\AltTextNotice::FLAG_OPTION, 'sentinel' ), 'Reset must remove the pending alt text notice' );
assert_true( in_array( \TranslateWP\Components\AltTextNotice::FLAG_OPTION, \TranslateWP\Components\CleanupInventory::INFRASTRUCTURE_OPTIONS, true ), 'Uninstall and reset must both know the alt text notice option' );
update_option( 'translatewp_settings_revision', 7 );
$GLOBALS['wp_test_post_meta']['_twp_translation_store'] = [ 1 => 'x' ];
$management->reset_data( $request );
assert_true( 'sentinel' === get_option( 'translatewp_settings_revision', 'sentinel' ), 'Reset must wipe the settings revision' );
assert_true( ! isset( $GLOBALS['wp_test_post_meta']['_twp_translation_store'] ), 'Reset must wipe the stored translation post meta' );

// --- Reset endpoint: blocks on non-401 remote failure ---
wp_test_reset( $connected_options );
Options::update( 'setup.ended', true );
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 500 ],
	'body'     => wp_json_encode( [ 'error' => 'upstream' ] ),
];
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' );
$result     = $management->reset_data( $request );
assert_true( is_wp_error( $result ), 'Reset must block on non-401 remote failure' );
assert_true( true === Options::get( 'setup.ended', false ), 'Blocked reset must not wipe options' );

// --- Reset endpoint: 401 still wipes locally ---
wp_test_reset( $connected_options );
Options::update( 'setup.ended', true );
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 401 ],
	'body'     => wp_json_encode( [ 'error' => 'Unauthorized' ] ),
];
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' );
$result     = $management->reset_data( $request );
assert_true( ! is_wp_error( $result ), 'Reset must proceed locally on 401' );
assert_true( 'sentinel' === get_option( 'translatewp_setup', 'sentinel' ), '401 reset must wipe options' );

// --- Reset endpoint: a site without a pairing wipes locally without the remote call ---
wp_test_reset( [] );
Options::update( 'setup.ended', true );
Options::update( 'languages.list', [ 'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ] ] );
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 500 ],
	'body'     => wp_json_encode( [ 'error' => 'must not be called' ] ),
];
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' );
$result     = $management->reset_data( $request );
assert_true( ! is_wp_error( $result ), 'A site without a pairing must reset without the remote disconnect' );
assert_true( 'sentinel' === get_option( 'translatewp_setup', 'sentinel' ), 'The unpaired reset must wipe the setup option' );

// --- Reset on a subsite never reaches the network's locked groups ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_multisite']    = true;
$GLOBALS['wp_test_sites']        = [ [ 'blog_id' => 1 ], [ 'blog_id' => 2 ] ];
$GLOBALS['wp_test_current_blog'] = 2;
$GLOBALS['wp_test_super_admin']  = true;
$GLOBALS['wp_test_site_options'] = [
	'translatewp_network_settings_defaults' => [
		'translatewp_advanced'  => [ 'delete_on_uninstall' => true ],
		'translatewp_languages' => [ 'switcher' => [ 'style' => 'dropdown' ] ],
	],
	'translatewp_network_settings_scope'    => [ 'groups' => [ 'switcher_appearance' => [ 'state' => 'locked', 'exceptions' => [ 2 => 'local' ] ] ] ],
];
$network_before = $GLOBALS['wp_test_site_options'];
$GLOBALS['wp_test_disconnect_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode( [ 'success' => true ] ),
];
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$result     = $management->reset_data( new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' ) );
assert_true( ! is_wp_error( $result ), 'A super admin reset on a subsite must proceed' );
assert_true( $network_before === $GLOBALS['wp_test_site_options'], 'A subsite reset must leave the network defaults and the scope record, so the locked groups stay as the network set them' );
$GLOBALS['wp_test_super_admin']  = false;
$result = $management->reset_data( new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' ) );
assert_true( is_wp_error( $result ), 'A subsite admin must not reset the network-only account connection' );
$GLOBALS['wp_test_multisite']    = false;
$GLOBALS['wp_test_current_blog'] = 1;
$GLOBALS['wp_test_site_options'] = [];

// --- Uninstall: flag off keeps user data ---
wp_test_reset( $connected_options );
Options::update( 'setup.ended', true );
Options::update( 'languages.list', [ 'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ] ] );
Lyfecycle::uninstall();
assert_true( true === Options::get( 'setup.ended', false ), 'Uninstall with flag off must keep setup option' );
assert_true( is_array( Options::get( 'languages.list', [] ) ) && ! empty( Options::get( 'languages.list', [] ) ), 'Uninstall with flag off must keep languages' );

// --- Uninstall: flag on wipes user data ---
wp_test_reset( $connected_options );
Options::update( 'setup.ended', true );
Options::update( 'languages.list', [ 'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ] ] );
Options::update( 'advanced.delete_on_uninstall', true );
update_option( 'translatewp_version', TRANSLATEWP_VERSION );
Lyfecycle::uninstall();
assert_true( 'sentinel' === get_option( 'translatewp_setup', 'sentinel' ), 'Uninstall with flag on must wipe setup option' );
assert_true( [] === Options::get( 'languages.list', 'sentinel' ) || 'sentinel' === Options::get( 'languages.list', 'sentinel' ), 'Uninstall with flag on must wipe languages' );
assert_true( 'sentinel' === get_option( 'translatewp_version', 'sentinel' ), 'Uninstall with flag on must wipe the version marker' );

// --- Permission callback: nonce enforced ---
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$request    = new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data', [], [ 'X-WP-Nonce' => 'bad-nonce' ] );
$result     = $management->admin_write_permissions_check( $request );
assert_true( is_wp_error( $result ), 'Write permission check must reject an invalid nonce' );

// --- Route wiring: reset-data and restart-setup use the nonce-guarded write check ---
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$endpoints  = $management->define_endpoints();
$by_path    = [];
foreach ( $endpoints as $endpoint ) {
	$by_path[ $endpoint->getPath() ] = $endpoint;
}

foreach ( [ 'reset-data', 'restart-setup' ] as $path ) {
	assert_true( isset( $by_path[ $path ] ), "Management must register the {$path} route" );
	$permission = $by_path[ $path ]->getPermissionCallback();
	assert_true(
		is_array( $permission ) && 'admin_write_permissions_check' === ( $permission[1] ?? '' ),
		"{$path} must be wired to admin_write_permissions_check, got " . ( is_array( $permission ) ? ( $permission[1] ?? 'none' ) : 'non-array' )
	);
}

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsResetTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SettingsResetTest: all assertions passed\n";
