<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\Sites;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.22' );
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
		'id'                => '00000000-0000-4000-8000-000000000abc',
		'token'             => 'secret-site-token',
		'home_url'          => 'https://example.com',
		'account_label'     => 'ops@example.com',
		'connection_status' => 'connected',
	],
];

// --- address_change_state names the old and the new address, ignoring cosmetic differences ---
wp_test_reset( $connected_options );
assert_true( null === Sites::address_change_state(), 'A matching address reports no change' );

$GLOBALS['wp_test_home_url'] = 'https://neue-adresse.example.net';
$state = Sites::address_change_state();
assert_true(
	is_array( $state ) && 'https://example.com' === $state['from'] && 'https://neue-adresse.example.net' === $state['to'],
	'A host change names the old and the new address'
);

wp_test_reset( $connected_options );
$GLOBALS['wp_test_home_url'] = 'http://www.example.com/';
assert_true( null === Sites::address_change_state(), 'A scheme, www, or trailing-slash difference is not an address change' );

// --- canonical_identity normalises scheme, www, case and trailing slash, but keeps the path ---
assert_true(
	Sites::canonical_identity( 'https://www.Example.com/' ) === Sites::canonical_identity( 'http://example.com' ),
	'canonical identity ignores scheme, www, case and trailing slash'
);
assert_true(
	Sites::canonical_identity( 'https://example.com/negozio' ) !== Sites::canonical_identity( 'https://example.com/tienda' ),
	'canonical identity keeps the path'
);

// --- canonical_claim_url keeps the port and query, drops scheme, www and trailing slash ---
assert_true( 'example.com' === Sites::canonical_claim_url( 'https://www.Example.com/' ), 'canonical claim url drops scheme, www and trailing slash' );
assert_true( 'example.com:8080/shop' === Sites::canonical_claim_url( 'http://example.com:8080/shop/' ), 'canonical claim url keeps the port and path' );
assert_true( 'example.com/path?a=1' === Sites::canonical_claim_url( 'https://example.com/path?a=1' ), 'canonical claim url keeps the query' );

// --- reconnect_claim carries the previous site id and full url, signed per the connect contract ---
wp_test_reset( $connected_options );
$names = Sites::reconnect_claim_param_names();
assert_true( 'reconnect_site' === $names['site_id'] && 'reconnect_url' === $names['url'], 'The claim uses the contract parameter names' );
assert_true( 'reconnect_ts' === $names['timestamp'] && 'reconnect_sig' === $names['signature'], 'The claim uses the contract parameter names' );
$claim = Sites::reconnect_claim();
assert_true( '00000000-0000-4000-8000-000000000abc' === ( $claim[ $names['site_id'] ] ?? '' ), 'The claim carries the previous site id' );
assert_true( 'https://example.com' === ( $claim[ $names['url'] ] ?? '' ), 'The claim carries the full new home url' );
$signing_key        = hash( 'sha256', 'secret-site-token' );
$message            = implode( "\n", [ $claim[ $names['site_id'] ], Sites::canonical_claim_url( 'https://example.com' ), $claim[ $names['timestamp'] ] ] );
$expected_signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $message, $signing_key, true ) ), '+/', '-_' ), '=' );
assert_true( $expected_signature === ( $claim[ $names['signature'] ] ?? '' ), 'The claim signature is base64url HMAC over site id, canonical url and timestamp keyed by the token hash' );

// --- Without credentials there is no previous-site claim ---
wp_test_reset( [] );
assert_true( [] === Sites::reconnect_claim(), 'Without credentials there is no previous-site claim' );

// --- Reconnect carries the claim into the portal redirect; a plain connect does not ---
wp_test_reset( $connected_options );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$reconnect               = SiteConnection::begin_connect( true );
$plain                   = SiteConnection::begin_connect( false );
$GLOBALS['wp_test_caps'] = [];
assert_true( ! empty( $reconnect['ok'] ) && ! empty( $plain['ok'] ), 'begin_connect succeeds for a manager' );
$reconnect_url = (string) ( $reconnect['redirect_url'] ?? '' );
$plain_url     = (string) ( $plain['redirect_url'] ?? '' );
assert_true( false !== strpos( $reconnect_url, $names['site_id'] . '=' ), 'Reconnect carries the previous-site claim' );
assert_true( false !== strpos( $reconnect_url, $names['signature'] . '=' ), 'Reconnect carries the claim signature' );
assert_true( false === strpos( $plain_url, $names['site_id'] . '=' ), 'A plain connect (connect as a new site) carries no previous-site claim' );

// --- The claim parameter names are configurable in one place ---
wp_test_reset( $connected_options );
add_filter( 'translatewp_reconnect_claim_params', static function ( $names ) {
	$names['site_id'] = 'prev_site';
	return $names;
} );
$claim = Sites::reconnect_claim();
assert_true( isset( $claim['prev_site'] ), 'The claim parameter names are filterable in one place' );

// --- A reused site row returns its own reconnected notice, distinct from a plain connect ---
assert_true( '' !== SiteConnection::notice_message( 'reconnected' ), 'The reconnected return has its own notice' );
assert_true(
	false !== strpos( SiteConnection::notice_message( 'address_taken' ), 'your account' ),
	'A reconnect refused because the account\'s own other site holds the address says so'
);
assert_true(
	SiteConnection::notice_message( 'address_taken' ) !== SiteConnection::notice_message( 'site_conflict' ),
	'The address-taken notice is not the other-account notice'
);
assert_true(
	SiteConnection::notice_message( 'reconnected' ) !== SiteConnection::notice_message( 'connected' ),
	'A reconnect reads differently from a plain connect'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "AddressReferenceTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AddressReferenceTest: all assertions passed\n";
