<?php

declare(strict_types=1);

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

function run_site_return( array $scenario ): array {
	$scenario_path = tempnam( sys_get_temp_dir(), 'twp-sr-scenario-' );
	$result_path   = tempnam( sys_get_temp_dir(), 'twp-sr-result-' );
	file_put_contents( $scenario_path, wp_json_encode( $scenario ) );
	exec(
		sprintf(
			'%s %s %s %s 2>/dev/null',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( __DIR__ . '/fixtures/site-return-runner.php' ),
			escapeshellarg( $scenario_path ),
			escapeshellarg( $result_path )
		)
	);
	$result = json_decode( (string) file_get_contents( $result_path ), true );
	unlink( $scenario_path );
	unlink( $result_path );

	return is_array( $result ) ? $result : [];
}

function notice_of( array $result ): string {
	$redirect = (string) ( $result['redirects'][0] ?? '' );
	$query    = (string) wp_parse_url( $redirect, PHP_URL_QUERY );
	parse_str( $query, $args );

	return (string) ( $args['translatewp_connect_notice'] ?? '' );
}

// A WordPress Playground site: one shared host, a per-instance scope in the address.
const PLAYGROUND_HOME = 'https://playground.wordpress.net/scope:0.4815162342';
const STATE           = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

$connect = [
	'home_url'            => PLAYGROUND_HOME,
	'state'               => STATE,
	'seed_state_for_user' => 1,
	'code'                => 'one-use-code',
];

// --- The return completes the connection of the instance that started it ---
$first = run_site_return( $connect );
assert_true( 'connected' === notice_of( $first ), 'A valid return must connect, got ' . wp_json_encode( $first['redirects'] ?? [] ) );
assert_true( 1 === count( $first['exchange_posts'] ?? [] ), 'A valid return must exchange exactly once' );
assert_true( 'playground.wordpress.net' === ( $first['exchange_posts'][0]['body']['host'] ?? '' ), 'The exchange carries the site host' );
assert_true( PLAYGROUND_HOME === ( $first['exchange_posts'][0]['body']['homeUrl'] ?? '' ), 'The exchange carries the per-instance address that identifies the site' );
assert_true( PLAYGROUND_HOME === ( $first['site']['home_url'] ?? '' ) && '' !== ( $first['site']['token'] ?? '' ), 'The credentials are stored with the instance address' );

// --- Replaying the same return URL after it was used does nothing ---
$replay = run_site_return( [ 'home_url' => PLAYGROUND_HOME, 'state' => STATE, 'code' => 'one-use-code', 'transients' => $first['transients'] ?? [] ] );
assert_true( 'invalid_state' === notice_of( $replay ), 'A replayed return must be refused, got ' . notice_of( $replay ) );
assert_true( 0 === count( $replay['exchange_posts'] ?? [] ), 'A replayed return must not exchange again' );

// --- A return that reaches another instance (no matching state there) does nothing ---
$elsewhere = run_site_return( [ 'home_url' => 'https://playground.wordpress.net/scope:0.999', 'state' => STATE, 'code' => 'one-use-code' ] );
assert_true( 'invalid_state' === notice_of( $elsewhere ), 'Another instance must refuse a return it did not start' );
assert_true( 0 === count( $elsewhere['exchange_posts'] ?? [] ) && '' === ( $elsewhere['site']['token'] ?? '' ), 'Another instance must not exchange or store credentials' );

// --- A state started by another user is refused ---
$other_user = run_site_return( array_merge( $connect, [ 'seed_state_for_user' => 2 ] ) );
assert_true( 'invalid_state' === notice_of( $other_user ) && 0 === count( $other_user['exchange_posts'] ?? [] ), 'A state of another user must be refused' );

// --- Cancelling sign-in is reported, never exchanged, and the state cannot be reused ---
$cancel = run_site_return( array_merge( $connect, [ 'code' => '', 'error' => 'access_denied' ] ) );
assert_true( 'access_denied' === notice_of( $cancel ) && 0 === count( $cancel['exchange_posts'] ?? [] ), 'A cancelled sign-in must report access_denied without an exchange' );
$after_cancel = run_site_return( [ 'home_url' => PLAYGROUND_HOME, 'state' => STATE, 'code' => 'late-code', 'transients' => $cancel['transients'] ?? [] ] );
assert_true( 'invalid_state' === notice_of( $after_cancel ), 'The state of a cancelled attempt cannot be reused' );

// --- An expired code at the service stores nothing ---
$expired = run_site_return( array_merge( $connect, [ 'exchange_fail' => true ] ) );
assert_true( 'invalid_connect_code' === notice_of( $expired ), 'An expired code must be reported, got ' . notice_of( $expired ) );
assert_true( '' === ( $expired['site']['token'] ?? '' ), 'An expired code must not store credentials' );

// --- A tab that is not signed in to the site is refused before the state is used ---
$signed_out = run_site_return( array_merge( $connect, [ 'caps' => [] ] ) );
assert_true( 'permission_denied' === notice_of( $signed_out ) && 0 === count( $signed_out['exchange_posts'] ?? [] ), 'A return without admin rights must be refused without an exchange' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SiteConnectReturnTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "SiteConnectReturnTest: all assertions passed\n";
