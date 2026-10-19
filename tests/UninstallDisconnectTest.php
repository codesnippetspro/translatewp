<?php

declare(strict_types=1);

// Deleting the plugin from a connected site counts as a disconnect: the
// service is told, so its 30-day removal of the site's data starts. Deleting
// never waits long for the service and never fails because of it.

use TranslateWP\Components\Options;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// error_log() writes here, so each failure the delete logs can be read back.
$GLOBALS['uninstall_log'] = (string) tempnam( sys_get_temp_dir(), 'twp-uninstall-log' );
ini_set( 'error_log', $GLOBALS['uninstall_log'] );
ini_set( 'display_errors', 'stderr' );

function uninstall_log(): string {
	$log = (string) file_get_contents( $GLOBALS['uninstall_log'] );
	file_put_contents( $GLOBALS['uninstall_log'], '' );
	return $log;
}

function seed( bool $connected ): void {
	wp_test_reset( [ 'site' => $connected ? [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] : [] ] );
	$GLOBALS['wp_test_remote_disconnect_posts'] = [];
	$GLOBALS['wp_test_disconnect_response']     = null;
}

// --- a connected site tells the service, once, with its own token, and does not wait long ---
seed( true );
Lyfecycle::uninstall();
$calls = $GLOBALS['wp_test_remote_disconnect_posts'];
assert_true( 1 === count( $calls ) && '/v1/site/disconnect' === $calls[0]['path'], 'deleting the plugin from a connected site must tell the service to disconnect, got ' . count( $calls ) . ' call(s)' );
assert_true( 'Bearer site-token' === ( $calls[0]['token'] ?? '' ), 'the disconnect carries the site token' );
assert_true( is_numeric( $calls[0]['timeout'] ?? null ) && (float) $calls[0]['timeout'] <= 5, 'the delete request must not wait more than five seconds, got ' . wp_json_encode( $calls[0]['timeout'] ?? null ) );
assert_true( ! SiteConnection::has_credentials(), 'the site no longer holds the credentials the service has revoked' );
assert_true( false === strpos( uninstall_log(), 'disconnect failed' ), 'a disconnect the service accepted must not be logged as failed' );

// --- a site that is not connected asks nothing ---
seed( false );
Lyfecycle::uninstall();
assert_true( [] === $GLOBALS['wp_test_remote_disconnect_posts'], 'a site with no credentials must not call the service' );

// --- the service failing does not stop the delete, and the credentials stay for a later disconnect ---
seed( true );
$GLOBALS['wp_test_disconnect_response'] = [ 'response' => [ 'code' => 503 ], 'body' => '{"error":"down"}' ];
Lyfecycle::uninstall();
assert_true( 1 === count( $GLOBALS['wp_test_remote_disconnect_posts'] ), 'a failed disconnect is tried once, not retried in the delete request' );
assert_true( [] !== array_filter( $GLOBALS['wp_test_actions_fired'] ?? [], static fn( $event ) => 'TranslateWP/cleanup' === ( $event['tag'] ?? '' ) ), 'the delete must finish when the service cannot be reached' );
assert_true( '' !== (string) Options::get( 'site.token', '' ), 'credentials the service did not revoke are kept, so the owner can still disconnect after reinstalling' );
assert_true( 1 === preg_match( '/disconnect failed.*503/', uninstall_log() ), 'a failed disconnect at uninstall must be logged with its status' );

// --- a network: the disconnects share one time budget, and the local cleanup always runs ---
seed( true );
$GLOBALS['wp_test_multisite']           = true;
$GLOBALS['wp_test_sites']               = [ [ 'blog_id' => 1 ], [ 'blog_id' => 2 ], [ 'blog_id' => 3 ], [ 'blog_id' => 4 ] ];
foreach ( [ 2, 3, 4 ] as $blog_id ) {
	$GLOBALS['wp_test_options_store'][ 'blog_' . $blog_id . '_site' ] = [ 'id' => '00000000-0000-4000-8000-00000000000' . $blog_id, 'token' => 'site-token-' . $blog_id ];
}
// A slow service: every call uses its whole five seconds and fails, so each site tries.
$GLOBALS['wp_test_disconnect_response'] = [ 'response' => [ 'code' => 503 ], 'body' => '{"error":"down"}' ];
$ticks = 0;
Lyfecycle::set_clock( static function () use ( &$ticks ): float {
	return 1000.0 + 5.0 * $ticks++;
} );
Lyfecycle::uninstall();
Lyfecycle::set_clock( null );
$calls = $GLOBALS['wp_test_remote_disconnect_posts'];
assert_true( 2 === count( $calls ), 'a network must stop disconnecting when its time budget is spent, got ' . count( $calls ) . ' of 4 sites' );
assert_true( 5 === (int) ( $calls[0]['timeout'] ?? 0 ) && 2 === (int) ( $calls[1]['timeout'] ?? 0 ), 'a later site only gets the time that is left, got ' . wp_json_encode( array_column( $calls, 'timeout' ) ) );
assert_true( [] !== array_filter( $GLOBALS['wp_test_actions_fired'] ?? [], static fn( $event ) => 'TranslateWP/cleanup' === ( $event['tag'] ?? '' ) ), 'the local cleanup runs whatever the service did' );
assert_true( 'Bearer site-token' === ( $calls[0]['token'] ?? '' ) && 'Bearer site-token-2' === ( $calls[1]['token'] ?? '' ), 'each site disconnects with its own token' );
assert_true( 1 === get_current_blog_id(), 'the delete ends on the site it started on' );
$log = uninstall_log();
assert_true( 1 === preg_match( '/blog 3 skipped/', $log ) && 1 === preg_match( '/blog 4 skipped/', $log ), 'each site the time budget skips must be logged by blog id, got: ' . $log );
assert_true( 0 === preg_match( '/blog [12] skipped/', $log ), 'a site the delete reached must not be logged as skipped' );
assert_true( 1 === preg_match( '/blog 2: .*disconnect failed/', $log ), 'a failed disconnect on a network must name its blog' );
foreach ( [ 3, 4 ] as $blog_id ) {
	assert_true( isset( $GLOBALS['wp_test_options_store'][ 'blog_' . $blog_id . '_site' ]['token'] ), "blog {$blog_id}, skipped by the time budget, must keep its credentials" );
}
$GLOBALS['wp_test_multisite'] = false;
unlink( $GLOBALS['uninstall_log'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "UninstallDisconnectTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "UninstallDisconnectTest: all assertions passed\n";
