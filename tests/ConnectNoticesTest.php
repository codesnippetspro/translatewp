<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;

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

function render_connect_notices( array $get ): string {
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
	$_GET                    = $get;
	ob_start();
	SiteConnection::render_admin_notices();
	$html = (string) ob_get_clean();
	$_GET = [];

	return $html;
}

// --- A set-up site that is not connected gets a way to connect from the notice ---
wp_test_reset( [ 'setup' => [ 'ended' => true ] ] );
$html = render_connect_notices( [ 'page' => 'translatewp' ] );
assert_true( false !== strpos( $html, 'Connect your site to enable translation.' ), 'A disconnected set-up site is told to connect, got ' . $html );
assert_true( false !== strpos( $html, 'button-primary' ) && false !== strpos( $html, 'Connect this site' ), 'The connect notice carries a primary Connect this site button, got ' . $html );
assert_true( false !== strpos( $html, 'translatewp_connect=new' ) && false !== strpos( $html, '_wpnonce=nonce-translatewp_connect' ), 'The connect button starts a fresh, nonced connect, got ' . $html );

// --- The one-time connected notice is not shown again when the page is refreshed ---
wp_test_reset( [] );
new \TranslateWP\Functionality\Connect( 'translatewp', '0.0.0-test' );
$removable = apply_filters( 'removable_query_args', [ 'message' ] );
assert_true( in_array( SiteConnection::NOTICE_QUERY_ARG, (array) $removable, true ), 'The connect notice query arg is removed from the address after display, got ' . wp_json_encode( $removable ) );
assert_true( in_array( 'message', (array) $removable, true ), 'Query args other code registered stay removable' );

// --- A portal return after the connect link expired says so and offers a fresh connect ---
wp_test_reset( [] );
$html = render_connect_notices( [ 'page' => 'translatewp', SiteConnection::NOTICE_QUERY_ARG => 'invalid_state' ] );
assert_true( false !== strpos( $html, 'The connection took too long. Connect again.' ), 'An expired connect explains what happened, got ' . $html );
assert_true( false !== strpos( $html, '>Connect this site</a>' ) && false !== strpos( $html, 'button-primary' ), 'An expired connect carries a primary Connect this site button, got ' . $html );
assert_true( false !== strpos( $html, 'translatewp_connect=new' ) && false !== strpos( $html, '_wpnonce=nonce-translatewp_connect' ), 'The Connect button starts a fresh, nonced connect, got ' . $html );

if ( $failures > 0 ) {
	fwrite( STDERR, "ConnectNoticesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ConnectNoticesTest: all assertions passed\n";
