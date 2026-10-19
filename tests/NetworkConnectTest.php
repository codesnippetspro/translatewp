<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function network_test_setup(): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 1;
	$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://site2.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://site3.example.com' ],
	];
}

function run_return_scenario( array $scenario ): array {
	$scenario_path = tempnam( sys_get_temp_dir(), 'twp-nc-scenario-' );
	$result_path   = tempnam( sys_get_temp_dir(), 'twp-nc-result-' );
	file_put_contents( $scenario_path, wp_json_encode( $scenario ) );

	$command = sprintf(
		'%s %s %s %s 2>/dev/null',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __DIR__ . '/fixtures/network-return-runner.php' ),
		escapeshellarg( $scenario_path ),
		escapeshellarg( $result_path )
	);
	exec( $command, $output, $exit_code );
	unset( $output );

	$result = json_decode( (string) file_get_contents( $result_path ), true );
	unlink( $scenario_path );
	unlink( $result_path );

	return is_array( $result ) ? $result : [];
}

function base_scenario(): array {
	return [
		'multisite'    => true,
		'current_blog' => 1,
		'caps'         => [ 'manage_network_options', 'manage_options' ],
		'sites'        => [
			[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
			[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://site2.example.com' ],
			[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://site3.example.com' ],
		],
		'state'        => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'code'         => 'primary-code',
		'codes'        => wp_json_encode(
			[
				[ 'host' => 'site2.example.com', 'code' => 'code-site2' ],
				[ 'host' => 'site3.example.com', 'code' => 'code-site3' ],
			]
		),
	];
}

// --- begin_network_connect: subsites param assembly ---

network_test_setup();
$begin = SiteConnection::begin_network_connect();
assert_true( ! empty( $begin['ok'] ), 'Network begin must succeed for a super admin' );
$url = (string) ( $begin['redirect_url'] ?? '' );
assert_true( '' !== $url, 'Network begin must return a redirect URL' );
assert_true( false !== strpos( $url, '/connect?' ), 'Redirect URL must target the portal connect page' );
assert_true( false !== strpos( $url, 'return=' . rawurlencode( 'https://example.com/translatewp-connect/network-return/' ) ), 'Redirect URL must carry the network return URL' );
assert_true( false !== strpos( $url, 'subsites=site2.example.com%2Csite3.example.com' ), 'Redirect URL must carry comma-separated subsite hosts excluding the primary' );
assert_true( false === strpos( $url, 'subsites=example.com' ), 'Primary host must never appear in the subsites list' );

// --- network reconnect: the root claim and one signed claim per connected subsite ride on the link ---

network_test_setup();
$GLOBALS['wp_test_options_store']['site']        = [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'root-token', 'home_url' => 'https://example.com' ];
$GLOBALS['wp_test_options_store']['blog_2_site'] = [ 'id' => '00000000-0000-4000-8000-000000000002', 'token' => 'sub-two-token', 'home_url' => 'https://old2.example.net' ];
$begin = SiteConnection::begin_network_connect( [], true );
$url   = (string) ( $begin['redirect_url'] ?? '' );
assert_true( ! empty( $begin['ok'] ), 'Network reconnect begin must succeed' );
assert_true( false !== strpos( $url, 'reconnect_site=00000000-0000-4000-8000-000000000001' ), 'Network reconnect must carry the root claim' );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
$claims = json_decode( (string) ( $query['subsite_claims'] ?? '' ), true );
assert_true( is_array( $claims ) && 1 === count( $claims ), 'Only the subsite with credentials has a claim, got ' . wp_json_encode( $claims ) );
$claim = is_array( $claims ) ? (array) ( $claims[0] ?? [] ) : [];
assert_true( 'site2.example.com' === ( $claim['host'] ?? '' ) && '00000000-0000-4000-8000-000000000002' === ( $claim['site'] ?? '' ), 'The subsite claim names its host and its previous site id' );
assert_true( isset( $claim['ts'] ) && is_int( $claim['ts'] ) && abs( time() - $claim['ts'] ) < 5, 'The subsite claim carries a fresh integer timestamp' );
$expected_message = implode( "\n", [ '00000000-0000-4000-8000-000000000002', 'site2.example.com', (string) $claim['ts'] ] );
$expected_sig     = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $expected_message, hash( 'sha256', 'sub-two-token' ), true ) ), '+/', '-_' ), '=' );
assert_true( $expected_sig === ( $claim['sig'] ?? '' ), 'The subsite claim is signed with the subsite token over its address under the network scheme' );
assert_true( 1 === get_current_blog_id(), 'Building the claims must restore the primary blog' );

network_test_setup();
$GLOBALS['wp_test_options_store']['site']        = [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'root-token', 'home_url' => 'https://example.com' ];
$GLOBALS['wp_test_options_store']['blog_2_site'] = [ 'id' => '00000000-0000-4000-8000-000000000002', 'token' => 'sub-two-token', 'home_url' => 'https://old2.example.net' ];
$url = (string) ( SiteConnection::begin_network_connect()['redirect_url'] ?? '' );
assert_true( false === strpos( $url, 'reconnect_site' ) && false === strpos( $url, 'subsite_claims' ), 'A plain network connect never claims previous sites' );

// --- network reconnect: the largest network the plain connect accepts keeps its claim set under the portal's 6000-byte bound ---

network_test_setup();
$GLOBALS['wp_test_sites'] = [ [ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ] ];
for ( $i = 2; $i <= 26; $i++ ) {
	// 25 hosts of 79 characters: the longest list that still fits the 2000-byte hosts parameter.
	$long_host                           = str_pad( 'h' . $i, 67, 'x' ) . '.example.com';
	$GLOBALS['wp_test_sites'][]          = [ 'blog_id' => $i, 'blogname' => 'Sub ' . $i, 'siteurl' => 'https://' . $long_host ];
	$GLOBALS['wp_test_site_hosts'][ $i ] = $long_host;
	$GLOBALS['wp_test_options_store'][ 'blog_' . $i . '_site' ] = [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', $i ), 'token' => 'token-' . $i, 'home_url' => 'https://' . $long_host ];
}
$plain = SiteConnection::begin_network_connect();
assert_true( ! empty( $plain['ok'] ), 'Twenty-five 79-character hosts fit the plain connect link' );
$begin = SiteConnection::begin_network_connect( [], true );
assert_true( ! empty( $begin['ok'] ), 'The same network reconnects with claims' );
parse_str( (string) parse_url( (string) ( $begin['redirect_url'] ?? '' ), PHP_URL_QUERY ), $query );
$claims_length = strlen( (string) ( $query['subsite_claims'] ?? '' ) );
assert_true( 25 === count( (array) json_decode( (string) ( $query['subsite_claims'] ?? '[]' ), true ) ), 'Every subsite with credentials carries a claim' );
assert_true( $claims_length > 4000 && $claims_length <= 6000, 'The largest legal claim set stays under the portal bound, got ' . $claims_length );
unset( $GLOBALS['wp_test_site_hosts'] );

// --- subdirectory network: every subsite carries its own address key, host plus path ---

network_test_setup();
$GLOBALS['wp_test_sites'] = [
	[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
	[ 'blog_id' => 2, 'blogname' => 'Shop', 'siteurl' => 'https://example.com/shop' ],
	[ 'blog_id' => 3, 'blogname' => 'Blog', 'siteurl' => 'https://example.com/blog' ],
];
$GLOBALS['wp_test_site_hosts'] = [ 2 => 'example.com/shop', 3 => 'example.com/blog' ];
$GLOBALS['wp_test_options_store']['blog_2_site'] = [ 'id' => '00000000-0000-4000-8000-000000000002', 'token' => 'shop-token', 'home_url' => 'https://old.example/shop' ];
$subsites = SiteConnection::network_subsites();
assert_true( [ 'example.com/shop', 'example.com/blog' ] === array_column( $subsites, 'host' ), 'Subdirectory subsites are keyed by host and path, got ' . wp_json_encode( array_column( $subsites, 'host' ) ) );
$url = (string) ( SiteConnection::begin_network_connect( [], true )['redirect_url'] ?? '' );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
assert_true( 'example.com/shop,example.com/blog' === ( $query['subsites'] ?? '' ), 'The subsites parameter carries the path keys, got ' . (string) ( $query['subsites'] ?? '' ) );
$claims = (array) json_decode( (string) ( $query['subsite_claims'] ?? '[]' ), true );
assert_true( 1 === count( $claims ) && 'example.com/shop' === ( $claims[0]['host'] ?? '' ), 'The shop claim is keyed by its path address' );
$expected_message = implode( "\n", [ '00000000-0000-4000-8000-000000000002', 'example.com/shop', (string) $claims[0]['ts'] ] );
$expected_sig     = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $expected_message, hash( 'sha256', 'shop-token' ), true ) ), '+/', '-_' ), '=' );
assert_true( $expected_sig === ( $claims[0]['sig'] ?? '' ), 'The shop claim is signed over host and path under the network scheme' );
unset( $GLOBALS['wp_test_site_hosts'] );

// --- retry scope: only failed hosts ---

network_test_setup();
$begin = SiteConnection::begin_network_connect( [ 'site3.example.com' ] );
$url   = (string) ( $begin['redirect_url'] ?? '' );
assert_true( false !== strpos( $url, 'subsites=site3.example.com' ), 'Retry scope must include only the requested host' );
assert_true( false === strpos( $url, 'site2.example.com' ), 'Retry scope must exclude non-requested hosts' );

// --- non-multisite / non-super-admin guards ---

wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$begin = SiteConnection::begin_network_connect();
assert_true( empty( $begin['ok'] ), 'Network begin must fail outside multisite' );

network_test_setup();
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$begin = SiteConnection::begin_network_connect();
assert_true( empty( $begin['ok'] ) && 'permission_denied' === ( $begin['error'] ?? '' ), 'Network begin must deny a non-super-admin' );

// --- handle_network_return: per-subsite singular exchange + per-blog storage ---

$result = run_return_scenario( base_scenario() );

assert_true( 3 === count( $result['exchange_posts'] ?? [] ), 'Network return must exchange once per site (primary + 2 subsites)' );
assert_true( 1 === ( $result['exchange_posts'][0]['blog_id'] ?? 0 ), 'Primary exchange must run in the primary blog context' );
assert_true( 2 === ( $result['exchange_posts'][1]['blog_id'] ?? 0 ), 'First subsite exchange must run in its own blog context' );
assert_true( 3 === ( $result['exchange_posts'][2]['blog_id'] ?? 0 ), 'Second subsite exchange must run in its own blog context' );
assert_true( 'code-site2' === ( $result['exchange_posts'][1]['body']['code'] ?? '' ), 'Subsite exchange must use its own one-time code' );
assert_true( 'site2.example.com' === ( $result['exchange_posts'][1]['body']['host'] ?? '' ), 'Subsite exchange must send its own host' );
assert_true( 'https://site2.example.com' === ( $result['exchange_posts'][1]['body']['homeUrl'] ?? '' ), 'Subsite exchange must send its own homeUrl' );
assert_true( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' === ( $result['exchange_posts'][1]['body']['state'] ?? '' ), 'Subsite exchange must reuse the single round-trip state' );

$results = $result['site_transients']['translatewp_network_connect_outcome_1'] ?? [];
assert_true( 3 === count( $results ), 'Network results must record one entry per site' );
assert_true( ! empty( $results[0]['ok'] ) && ! empty( $results[1]['ok'] ) && ! empty( $results[2]['ok'] ), 'All sites must report success' );

$primary_option = $result['options_store']['site'] ?? [];
assert_true( 'token-example.com' === ( $primary_option['token'] ?? '' ), 'Primary token must be stored in the primary blog option' );

$subsite_option = $result['options_store']['blog_2_site'] ?? [];
assert_true( 'token-site2.example.com' === ( $subsite_option['token'] ?? '' ), 'Subsite token must be stored in its own blog option' );
assert_true( 'https://site2.example.com' === ( $subsite_option['home_url'] ?? '' ), 'Subsite option must record its own home_url' );

assert_true( 1 === ( $result['current_blog'] ?? 0 ), 'Return handler must restore the primary blog context' );
assert_true( 1 === count( $result['redirects'] ?? [] ), 'Network return must redirect once' );
assert_true( false !== strpos( (string) ( $result['redirects'][0] ?? '' ), 'page=translatewp-network-settings' ), 'Network return must redirect to the network settings page' );

// --- partial failure: every exchange fails, all recorded ---

$scenario          = base_scenario();
$scenario['exchange_fail'] = true;
$result = run_return_scenario( $scenario );

$results = $result['site_transients']['translatewp_network_connect_outcome_1'] ?? [];
assert_true( 3 === count( $results ), 'Partial failure must still record every site' );
assert_true( empty( $results[0]['ok'] ) && 'invalid_connect_code' === ( $results[0]['error'] ?? '' ), 'Primary failure must be recorded with its error code' );
assert_true( empty( $results[1]['ok'] ) && empty( $results[2]['ok'] ), 'Subsite failures must be recorded' );

// --- unknown host in codes is skipped, never exchanged ---

$scenario         = base_scenario();
$scenario['codes'] = wp_json_encode(
	[
		[ 'host' => 'evil.example.com', 'code' => 'code-evil' ],
		[ 'host' => 'site2.example.com', 'code' => 'code-site2' ],
	]
);
$result = run_return_scenario( $scenario );

assert_true( 2 === count( $result['exchange_posts'] ?? [] ), 'Unknown hosts must never be exchanged' );
$results = $result['site_transients']['translatewp_network_connect_outcome_1'] ?? [];
assert_true( 4 === count( $results ), 'Results must record the primary, both subsites, and the unknown host' );
$unknown = array_values(
	array_filter(
		$results,
		static function ( array $entry ): bool {
			return 'evil.example.com' === ( $entry['host'] ?? '' );
		}
	)
);
assert_true( 1 === count( $unknown ) && 'unknown_host' === ( $unknown[0]['error'] ?? '' ), 'Unknown host must be recorded as unknown_host' );

// --- invalid state is rejected before any exchange ---

$scenario         = base_scenario();
$scenario['state'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$scenario['state_valid'] = false;
$result = run_return_scenario( $scenario );

assert_true( 0 === count( $result['exchange_posts'] ?? [] ), 'Invalid state must block every exchange' );
assert_true( false !== strpos( (string) ( $result['redirects'][0] ?? '' ), 'translatewp_connect_notice=invalid_state' ), 'Invalid state must redirect with the invalid_state notice' );

// --- network return records which sites were reconnected ---

$scenario = base_scenario();
$scenario['exchange_reused_hosts'] = [ 'site2.example.com' ];
$result   = run_return_scenario( $scenario );
$results  = $result['site_transients']['translatewp_network_connect_outcome_1'] ?? [];
assert_true( empty( $results[0]['reconnected'] ) && ! empty( $results[1]['reconnected'] ) && empty( $results[2]['reconnected'] ), 'Only the site the service re-referenced is recorded as reconnected' );

// --- oversized network: begin fails fast, no URL built ---

network_test_setup();
$GLOBALS['wp_test_sites'] = [ [ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ] ];
for ( $i = 2; $i <= 27; $i++ ) {
	$GLOBALS['wp_test_sites'][] = [
		'blog_id' => $i,
		'blogname' => 'Sub ' . $i,
		'siteurl'  => 'https://site' . $i . '.example.com',
	];
}
$begin = SiteConnection::begin_network_connect();
assert_true( empty( $begin['ok'] ) && 'network_too_large' === ( $begin['error'] ?? '' ), '26-subsite begin must fail with network_too_large' );
assert_true( empty( $begin['redirect_url'] ), 'Oversized begin must build no portal URL' );

// --- oversized codes payload: return rejected, zero exchanges ---

$scenario         = base_scenario();
$oversized_codes  = [];
for ( $i = 1; $i <= 26; $i++ ) {
	$oversized_codes[] = [ 'host' => 'site' . $i . '.example.com', 'code' => 'code-' . $i ];
}
$scenario['codes'] = wp_json_encode( $oversized_codes );
$result = run_return_scenario( $scenario );

assert_true( 0 === count( $result['exchange_posts'] ?? [] ), 'Oversized codes payload must run zero exchanges' );
assert_true( false !== strpos( (string) ( $result['redirects'][0] ?? '' ), 'translatewp_connect_notice=invalid_request' ), 'Oversized codes payload must redirect with invalid_request' );

// --- retry: primary not re-exchanged, prior successes preserved ---

$scenario              = base_scenario();
$scenario['only_hosts'] = [ 'site3.example.com' ];
$scenario['codes']     = wp_json_encode( [ [ 'host' => 'site3.example.com', 'code' => 'code-site3' ] ] );
$result = run_return_scenario( $scenario );

assert_true( 1 === count( $result['exchange_posts'] ?? [] ), 'Retry must exchange only the failed subsite' );
assert_true( 'site3.example.com' === ( $result['exchange_posts'][0]['body']['host'] ?? '' ), 'Retry exchange must target the failed host' );
assert_true( 'example.com' !== ( $result['exchange_posts'][0]['body']['host'] ?? '' ), 'Retry must never re-exchange the primary' );

$results = $result['site_transients']['translatewp_network_connect_outcome_1'] ?? [];
assert_true( 1 === count( $results ), 'A retry reports only the subsites it retried, got ' . wp_json_encode( $results ) );
assert_true( ! isset( $result['site_options']['translatewp_network_connect_results'] ), 'a retry keeps no stored results list' );
$retried = array_values(
	array_filter(
		$results,
		static function ( array $entry ): bool {
			return 3 === ( $entry['blog_id'] ?? 0 );
		}
	)
);
assert_true( 1 === count( $retried ) && ! empty( $retried[0]['ok'] ), 'Retried subsite must now report success' );

if ( $failures > 0 ) {
	fwrite( STDERR, "NetworkConnectTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "NetworkConnectTest ok\n" );
