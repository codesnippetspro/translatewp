<?php

declare(strict_types=1);

use TranslateWP\Components\PageCachePurge;
use TranslateWP\Functionality\Endpoints\Management;

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

$GLOBALS['purge_calls'] = [];
function w3tc_flush_all(): void {
	$GLOBALS['purge_calls'][] = 'w3tc';
}
function wp_cache_clear_cache(): void {
	$GLOBALS['purge_calls'][] = 'super-cache';
}
function rocket_clean_domain(): void {
	$GLOBALS['purge_calls'][] = 'rocket';
}
function wpfc_clear_all_cache( bool $minified = false ): void {
	$GLOBALS['purge_calls'][] = 'fastest' . ( $minified ? '+minified' : '' );
}

function purge_actions( string $tag ): array {
	return array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => $tag === $action['tag'] ) );
}

// --- Every known page cache is cleared and the generic action carries the reason ---
wp_test_reset( [] );
$GLOBALS['wp_test_actions_fired'] = [];
PageCachePurge::purge_all( 'setup_finished' );
assert_true( [ 'w3tc', 'super-cache', 'rocket', 'fastest+minified' ] === $GLOBALS['purge_calls'], 'Every present cache function is called once, got ' . wp_json_encode( $GLOBALS['purge_calls'] ) );
foreach ( [ 'litespeed_purge_all', 'cache_enabler_clear_complete_cache', 'rt_nginx_helper_purge_all' ] as $tag ) {
	assert_true( 1 === count( purge_actions( $tag ) ), 'The ' . $tag . ' action fires once' );
}
$generic = purge_actions( PageCachePurge::ACTION );
assert_true( 1 === count( $generic ) && 'setup_finished' === ( $generic[0]['args'][0] ?? '' ), 'The plugin action fires once with the reason' );

// --- Finishing the wizard purges the page caches ---
wp_test_reset( [] );
$GLOBALS['wp_test_options_store']['site'] = [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => str_repeat( 'a', 43 ), 'home_url' => 'https://example.com', 'connection_status' => 'connected' ];
$GLOBALS['wp_test_actions_fired'] = [];
$GLOBALS['purge_calls']           = [];
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
$management->finish_setup( new WP_REST_Request( 'POST', '/translatewp/v1/management/finish-setup', [ 'default_language' => 'de', 'languages' => [ 'de', 'it' ], 'auto_translate' => true, 'context' => 'A bakery' ] ) );
$generic = purge_actions( PageCachePurge::ACTION );
assert_true( 1 === count( $generic ) && 'setup_finished' === ( $generic[0]['args'][0] ?? '' ), 'Finishing the wizard purges the page caches once, got ' . count( $generic ) );
assert_true( in_array( 'w3tc', $GLOBALS['purge_calls'], true ), 'Finishing the wizard reaches the cache plugins' );

// --- Changing who sees translations purges the page caches; another settings key does not ---
$admin_request = static function ( array $data ) {
	return new WP_REST_Request( 'POST', '/translatewp/v1/admin/update-data', [ 'option' => 'translations.settings', 'data' => $data ] );
};
wp_test_reset( [] );
$GLOBALS['wp_test_options_store']['site'] = [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => str_repeat( 'a', 43 ), 'home_url' => 'https://example.com', 'connection_status' => 'connected' ];
$GLOBALS['wp_test_caps']                   = [ 'manage_options' ];
$admin                                     = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( $admin_request( [ 'visible_roles' => [ 'editor' ] ] ) );
$generic = purge_actions( PageCachePurge::ACTION );
assert_true( 1 === count( $generic ) && 'audience_saved' === ( $generic[0]['args'][0] ?? '' ), 'Saving the roles purges the page caches once, got ' . wp_json_encode( array_column( $generic, 'args' ) ) );
$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( $admin_request( [ 'visible_roles' => [ 'editor' ], 'preserve_branded_terms' => false ] ) );
assert_true( [] === purge_actions( PageCachePurge::ACTION ), 'A settings save that leaves the roles alone purges nothing' );

if ( $failures > 0 ) {
	fwrite( STDERR, "PageCachePurgeTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "PageCachePurgeTest ok\n" );
