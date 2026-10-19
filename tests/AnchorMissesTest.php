<?php

declare(strict_types=1);

use TranslateWP\Components\AnchorMisses;
use TranslateWP\Components\TranslationUrl;
use TranslateWP\Functionality\Endpoints\TranslationJobs;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.28' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );
$jobs = new TranslationJobs( 'translatewp', TRANSLATEWP_VERSION );

$c = static fn( string $path ): string => TranslationUrl::canonicalize( home_url( $path ) );
$request = static fn( string $url ): WP_REST_Request => new WP_REST_Request( 'POST', '/translatewp/v1/translation/anchor-missing', [ 'url' => $url ], [], '' );

$result = $jobs->report_anchor_missing( $request( home_url( '/it/landing/' ) ) );
assert_true( ! is_wp_error( $result ) && true === ( $result['recorded'] ?? false ), 'A page of this site is recorded' );
assert_true( [ $c( '/it/landing/' ) ] === array_column( AnchorMisses::pages(), 'url' ), 'The page is listed once, got ' . wp_json_encode( AnchorMisses::pages() ) );

$jobs->report_anchor_missing( $request( home_url( '/it/landing/' ) ) );
$jobs->report_anchor_missing( $request( home_url( '/de/other/' ) ) );
assert_true( [ $c( '/de/other/' ), $c( '/it/landing/' ) ] === array_column( AnchorMisses::pages(), 'url' ), 'A repeat moves to the front, a new page joins, nothing is doubled' );

$foreign = $jobs->report_anchor_missing( $request( 'https://elsewhere.example/page/' ) );
assert_true( is_wp_error( $foreign ) && 400 === ( $foreign->get_error_data()['status'] ?? 0 ), 'An address on another host is refused' );

for ( $i = 0; $i < AnchorMisses::MAX_PAGES + 5; $i++ ) {
	AnchorMisses::record( home_url( "/p{$i}/" ) );
}
assert_true( AnchorMisses::MAX_PAGES === count( AnchorMisses::pages() ), 'The list is bounded' );

AnchorMisses::clear();
assert_true( [] === AnchorMisses::pages(), 'Cleared' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AnchorMissesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AnchorMissesTest: all assertions passed\n";
