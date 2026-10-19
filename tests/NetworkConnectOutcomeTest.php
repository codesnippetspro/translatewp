<?php

declare(strict_types=1);

use TranslateWP\Components\Migrations;
use TranslateWP\Components\NetworkAdmin;
use TranslateWP\Components\SiteConnection;

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
	$GLOBALS['wp_test_current_user'] = 1;
	$GLOBALS['wp_test_super_admin']  = true;
	$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Editorial', 'siteurl' => 'https://site2.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Shop', 'siteurl' => 'https://site3.example.com' ],
	];
}

function outcome_of( array $data, string $host ): ?array {
	foreach ( $data['connection']['outcome'] as $entry ) {
		if ( $host === $entry['host'] ) {
			return $entry;
		}
	}

	return null;
}

// --- the page shows a run's outcome once, naming each subsite, then it is gone ---
network_setup();
SiteConnection::remember_network_connect_outcome(
	[
		[ 'host' => 'example.com', 'blog_id' => 1, 'ok' => true ],
		[ 'host' => 'site2.example.com', 'blog_id' => 2, 'ok' => true, 'reconnected' => true ],
		[ 'host' => 'site3.example.com', 'blog_id' => 3, 'ok' => false, 'error' => 'invalid_connect_code' ],
		[ 'host' => 'evil.example.com', 'blog_id' => 0, 'ok' => false, 'error' => 'unknown_host' ],
	]
);
$data = NetworkAdmin::page_data();
assert_true( 4 === count( $data['connection']['outcome'] ?? [] ), 'the first page load carries the run outcome, got ' . wp_json_encode( $data['connection']['outcome'] ?? null ) );
$editorial = outcome_of( $data, 'site2.example.com' );
assert_true( 'Editorial' === ( $editorial['name'] ?? '' ) && true === ( $editorial['ok'] ?? null ) && true === ( $editorial['reconnected'] ?? null ), 'a connected subsite is named, got ' . wp_json_encode( $editorial ) );
$shop = outcome_of( $data, 'site3.example.com' );
assert_true( false === ( $shop['ok'] ?? null ) && '' !== ( $shop['reason'] ?? '' ) && true === ( $shop['retry'] ?? null ), 'a failed subsite says why and offers a retry, got ' . wp_json_encode( $shop ) );
$unknown = outcome_of( $data, 'evil.example.com' );
assert_true( false === ( $unknown['retry'] ?? null ) && '' !== ( $unknown['reason'] ?? '' ), 'an address outside the network says why and offers no retry, got ' . wp_json_encode( $unknown ) );
assert_true( [] === NetworkAdmin::page_data()['connection']['outcome'], 'the outcome is gone on the next load' );

// --- the outcome belongs to the admin who ran the connect ---
network_setup();
SiteConnection::remember_network_connect_outcome( [ [ 'host' => 'example.com', 'blog_id' => 1, 'ok' => true ] ] );
$GLOBALS['wp_test_current_user'] = 2;
assert_true( [] === NetworkAdmin::page_data()['connection']['outcome'], 'another admin does not see the outcome' );

// --- nothing is kept to dismiss ---
network_setup();
$data = NetworkAdmin::page_data();
assert_true( ! isset( $data['connection']['results'] ), 'the page carries no stored results list' );
assert_true( ! isset( $data['actions']['dismiss'] ) && ! isset( $data['actions']['nonces']['dismiss'] ), 'the page carries no dismiss action' );
$page_source = (string) file_get_contents( dirname( __DIR__ ) . '/Functionality/Admin/MultisiteSettingsPage.php' );
assert_true( false === strpos( $page_source, 'translatewp_dismiss_network_results' ), 'the dismiss handler is gone' );
assert_true( ! method_exists( SiteConnection::class, 'network_connect_results' ), 'the stored results reader is gone' );

// --- the subsites table carries each subsite's connection state ---
network_setup();
$GLOBALS['wp_test_options_store']['blog_2_site'] = [ 'id' => '00000000-0000-4000-8000-000000000002', 'token' => 'token-2', 'home_url' => 'https://site2.example.com' ];
$subsites = NetworkAdmin::page_data()['subsites'];
$by_id    = array_column( $subsites, 'connected', 'id' );
assert_true( true === ( $by_id[2] ?? null ) && false === ( $by_id[3] ?? null ), 'each subsite row says whether it is connected, got ' . wp_json_encode( $by_id ) );

// --- the stored results list left by an earlier version is removed once ---
network_setup();
$GLOBALS['wp_test_site_options']['translatewp_network_connect_results'] = [ [ 'host' => 'example.com', 'ok' => true ] ];
update_option( Migrations::VERSION_OPTION, '0.10.18' );
Migrations::maybe_run();
assert_true( ! isset( $GLOBALS['wp_test_site_options']['translatewp_network_connect_results'] ), 'the old results list is deleted on upgrade' );

if ( $failures > 0 ) {
	fwrite( STDERR, "NetworkConnectOutcomeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "NetworkConnectOutcomeTest: all assertions passed\n";
