<?php

declare(strict_types=1);

use TranslateWP\Functionality\Admin\MultisiteSettingsPage;
use TranslateWP\Components\NetworkAdmin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0-beta.1' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function run_begin_scenario( array $scenario ): array {
	$scenario_path = tempnam( sys_get_temp_dir(), 'twp-nb-scenario-' );
	$result_path   = tempnam( sys_get_temp_dir(), 'twp-nb-result-' );
	file_put_contents( $scenario_path, wp_json_encode( $scenario ) );
	$command = sprintf(
		'%s %s %s %s 2>/dev/null',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __DIR__ . '/fixtures/network-begin-runner.php' ),
		escapeshellarg( $scenario_path ),
		escapeshellarg( $result_path )
	);
	exec( $command, $output, $exit_code );
	unset( $output, $exit_code );
	$result = json_decode( (string) file_get_contents( $result_path ), true );
	unlink( $scenario_path );
	unlink( $result_path );

	return is_array( $result ) ? $result : [];
}

function render_network_page(): string {
	ob_start();
	$page = new MultisiteSettingsPage( 'translatewp', '0.0.0-test' );
	$page->render_network_page();
	return (string) ob_get_clean();
}

wp_test_reset( [] );
$GLOBALS['wp_test_multisite']    = true;
$GLOBALS['wp_test_current_blog'] = 1;
$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
$GLOBALS['wp_test_sites']        = [
	[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
	[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://site2.example.com' ],
	[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://site3.example.com' ],
];
$GLOBALS['wp_test_options_store']['site'] = [
	'id'            => '00000000-0000-4000-8000-000000000001',
	'token'         => 'primary-token',
	'home_url'      => 'https://example.com',
	'account_label' => 'owner@example.com',
];
$GLOBALS['wp_test_options_store']['blog_2_site'] = [
	'id'            => '00000000-0000-4000-8000-000000000002',
	'token'         => 'sub-token',
	'home_url'      => 'https://site2.example.com',
	'account_label' => 'owner@example.com',
];

$html = render_network_page();

$page_source = (string) file_get_contents( dirname( __DIR__ ) . '/Functionality/Admin/MultisiteSettingsPage.php' );
assert_true( false !== strpos( $page_source, 'add_menu_page(' ), 'Network controls must register a top-level menu' );
assert_true( false === strpos( $page_source, "'settings.php'," ), 'Network controls must not remain under WordPress Settings' );
assert_true( false !== strpos( $html, 'id="translatewp-network-admin"' ), 'Network page must render the React application mount' );

$GLOBALS['wp_test_options_store']['site'] = [];
$data = NetworkAdmin::page_data();
assert_true( 'translatewp_begin_network_connect' === $data['actions']['connect'], 'React bootstrap must expose the network connect action' );
assert_true( 3 === count( $data['subsites'] ), 'React bootstrap must include every subsite' );
assert_true( 'https://example.com/wp-admin/admin.php?page=translatewp-seo' === $data['subsites'][0]['seoUrl'], 'Subsite metadata must include a direct SEO Tools link' );
assert_true( isset( $data['connection']['pro']['entitled'] ), 'Connection bootstrap must include the Pro panel state' );

// --- Connect Network and Reconnect Network leave for the portal, an outside host ---

$sites = [
	[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
	[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://site2.example.com' ],
];
$connected = [
	'site'        => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'root-token', 'home_url' => 'https://example.com' ],
	'blog_2_site' => [ 'id' => '00000000-0000-4000-8000-000000000002', 'token' => 'sub-token', 'home_url' => 'https://site2.example.com' ],
];
$begin = run_begin_scenario( [ 'sites' => $sites, 'post' => [] ] );
$target = (string) ( $begin['redirects'][0] ?? '' );
assert_true( false !== strpos( $target, '/connect?' ) && false !== strpos( $target, 'subsites=site2.example.com' ), 'Connect Network must leave for the portal connect page with the subsites, got ' . $target );
assert_true( false === strpos( $target, 'reconnect_site' ), 'Connect Network carries no claim' );

$reconnect = run_begin_scenario( [ 'sites' => $sites, 'options_store' => $connected, 'post' => [ 'reconnect' => '1' ] ] );
$target    = (string) ( $reconnect['redirects'][0] ?? '' );
assert_true( false !== strpos( $target, '/connect?' ) && false !== strpos( $target, 'reconnect_site=00000000-0000-4000-8000-000000000001' ) && false !== strpos( $target, 'subsite_claims=' ), 'Reconnect Network must leave for the portal with the root claim and the subsite claims, got ' . $target );

// --- Connect a subsite: one host leaves for the portal alone, the others stay untouched ---

assert_true( '' === (string) ( $data['subsites'][0]['host'] ?? 'missing' ), 'The network root has no subsite host, got ' . wp_json_encode( $data['subsites'][0]['host'] ?? 'missing' ) );
assert_true( 'site2.example.com' === (string) ( $data['subsites'][1]['host'] ?? '' ), 'A subsite carries the host the network connect addresses it by, got ' . wp_json_encode( $data['subsites'][1]['host'] ?? '' ) );

$three_sites = [
	[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
	[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://site2.example.com' ],
	[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://site3.example.com' ],
];
$single = run_begin_scenario( [ 'sites' => $three_sites, 'post' => [ 'only_hosts' => 'site3.example.com' ] ] );
$target = (string) ( $single['redirects'][0] ?? '' );
assert_true( false !== strpos( $target, '/connect?' ) && false !== strpos( $target, 'subsites=site3.example.com' ), 'Connect a subsite must leave for the portal with that subsite only, got ' . $target );
assert_true( false === strpos( $target, 'site2.example.com' ), 'Connect a subsite must not carry the other subsites, got ' . $target );

// --- The network page is a top-level menu, so every way back to it uses admin.php ---

$connection_source = (string) file_get_contents( dirname( __DIR__ ) . '/Components/SiteConnection.php' );
assert_true( false === strpos( $page_source, 'settings.php?page=' ), 'The network page must send its own redirects to admin.php' );
assert_true( false === strpos( $connection_source, 'settings.php?page=' ), 'The portal return must land on admin.php' );

// --- a scope save returns to the Settings tab ---
$settings_url = MultisiteSettingsPage::settings_tab_url( 'scope-saved' );
assert_true( false !== strpos( $settings_url, 'translatewp_multisite_status=scope-saved' ) && '#settings' === substr( $settings_url, -9 ), 'a scope save returns to the Settings tab, got ' . $settings_url );

if ( $failures > 0 ) {
	fwrite( STDERR, "MultisiteSettingsPageTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "MultisiteSettingsPageTest ok\n" );
