<?php

declare(strict_types=1);

use TranslateWP\Components\Sites;
use TranslateWP\Functionality\Endpoints\Connect;

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

function challenge_request( array $params ): WP_REST_Request {
	return new WP_REST_Request( 'GET', '/translatewp/v1/connect/challenge', $params, [], '' );
}

const SITE_ID = '00000000-0000-4000-8000-0000000000aa';
const TOKEN   = 'site-token-value-0123456789';
const NONCE   = 'n0nce_ABCDEFGHIJKLMNOPQRSTUV';

// A connected site answers with the nonce and its id signed by its credential key.
wp_test_reset( [ 'site' => [ 'id' => SITE_ID, 'token' => TOKEN ] ] );
$connect = new Connect( 'translatewp', TRANSLATEWP_VERSION );

$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', "challenge\n" . SITE_ID . "\n" . NONCE, hash( 'sha256', TOKEN ), true ) ), '+/', '-_' ), '=' );
$answer   = $connect->challenge( challenge_request( [ 'nonce' => NONCE, 'site' => SITE_ID ] ) );
assert_true( is_array( $answer ) && SITE_ID === ( $answer['siteId'] ?? '' ), 'The challenge must name the site, got ' . wp_json_encode( $answer ) );
assert_true( is_array( $answer ) && $expected === ( $answer['answer'] ?? '' ), 'The answer must be the nonce and site id signed with the credential key' );
assert_true( $expected === Sites::challenge_answer( SITE_ID, NONCE ), 'The component answer must match the route' );

// Another site's id, a missing connection, or a malformed nonce is refused.
$foreign = $connect->challenge( challenge_request( [ 'nonce' => NONCE, 'site' => '00000000-0000-4000-8000-0000000000bb' ] ) );
assert_true( is_wp_error( $foreign ) && 404 === ( $foreign->get_error_data()['status'] ?? 0 ), 'A challenge for another site id must answer 404' );

$short = $connect->challenge( challenge_request( [ 'nonce' => 'short', 'site' => SITE_ID ] ) );
assert_true( is_wp_error( $short ) && 400 === ( $short->get_error_data()['status'] ?? 0 ), 'A malformed nonce must answer 400' );

$odd = $connect->challenge( challenge_request( [ 'nonce' => 'has spaces and <tags>', 'site' => SITE_ID ] ) );
assert_true( is_wp_error( $odd ) && 400 === ( $odd->get_error_data()['status'] ?? 0 ), 'A nonce outside the base64url alphabet must answer 400' );

wp_test_reset( [] );
$connect      = new Connect( 'translatewp', TRANSLATEWP_VERSION );
$disconnected = $connect->challenge( challenge_request( [ 'nonce' => NONCE, 'site' => SITE_ID ] ) );
assert_true( is_wp_error( $disconnected ) && 404 === ( $disconnected->get_error_data()['status'] ?? 0 ), 'An unconnected site must answer 404' );
assert_true( null === Sites::challenge_answer( SITE_ID, NONCE ), 'No credentials, no answer' );

// The route is public: the service fetches it without a WordPress session.
$routes = $connect->define_endpoints();
$public = null;
foreach ( $routes as $route ) {
	if ( method_exists( $route, 'getPath' ) && false !== strpos( (string) $route->getPath(), 'challenge' ) ) {
		$public = $route;
	}
}
assert_true( null !== $public, 'The challenge route must be registered' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ConnectChallengeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ConnectChallengeTest: all assertions passed\n";
