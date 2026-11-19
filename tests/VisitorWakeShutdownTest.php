<?php

declare(strict_types=1);
namespace TranslateWP\Functionality {
	function register_shutdown_function( callable $callback ): void { $GLOBALS['wake_shutdown'][] = $callback; }
}
namespace {
	use TranslateWP\Components\BackgroundEngine;
	use TranslateWP\Functionality\Endpoints\TranslationJobs;
	use TranslateWP\Functionality\Indexer;
	require dirname( __DIR__ ) . '/vendor/autoload.php';
	require __DIR__ . '/wp-test-stubs.php';
	function check_wake( bool $ok, string $message ): void {
		if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
	}
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ], 'it' => [ 'locale' => 'it', 'active' => true ] ] ] ] );
	$request = new WP_REST_Request( 'POST', '/translatewp/v1/translation/miss', [ 'url' => 'https://example.com/shop/', 'language' => 'it', 'items' => [ [ 'groupId' => 'wake', 'markerPayload' => '⟪item_0001⟫Our unusual shop', 'contextKey' => 'p', 'contextKind' => 'text-flow' ] ] ] );
	$result = ( new TranslationJobs( '', '' ) )->report_misses( $request );
	check_wake( 1 === $result['accepted'], 'The miss is accepted before any wake' );
	$wakes = static fn (): array => array_values( array_filter( $GLOBALS['wp_test_remote_request_calls'], static fn ( array $call ): bool => '/v1/background/wake' === parse_url( $call['url'], PHP_URL_PATH ) ) );
	check_wake( [] === $wakes() && 1 === count( $GLOBALS['wake_shutdown'] ), 'No wake blocks the miss handler even without a finish function' );
	array_shift( $GLOBALS['wake_shutdown'] )();
	check_wake( 1 === count( $wakes() ) && 1.5 === $wakes()[0]['args']['timeout'] && true === $wakes()[0]['args']['blocking'], 'The shutdown wake reaches a transport that rejects timeouts below 50 ms' );
	check_wake( get_option( BackgroundEngine::LAST_VISITOR_WAKE_OPTION, 0 ) > 0, 'Only an accepted 204 stamps the visitor gate' );
	BackgroundEngine::visitor_wake(); check_wake( 1 === count( $wakes() ), 'The visitor gate holds for twenty seconds' );
	BackgroundEngine::wake(); check_wake( 2 === count( $wakes() ), 'A visitor wake never suppresses the independent cron wake' );
	delete_option( BackgroundEngine::LAST_VISITOR_WAKE_OPTION );
	delete_option( BackgroundEngine::LAST_VISITOR_ATTEMPT_OPTION );
	$GLOBALS['wp_test_wake_response'] = [ 'response' => [ 'code' => 503 ], 'body' => '' ];
	BackgroundEngine::visitor_wake();
	check_wake( false === get_option( BackgroundEngine::LAST_VISITOR_WAKE_OPTION ), 'A refused wake leaves its gate open for retry' );
	$before_retry = count( $wakes() );
	BackgroundEngine::visitor_wake();
	check_wake( $before_retry === count( $wakes() ), 'A failed wake still limits attempts to one request per twenty seconds' );
	unset( $GLOBALS['wp_test_wake_response'] );
	update_option( BackgroundEngine::LAST_VISITOR_ATTEMPT_OPTION, time() - 21, false );
	BackgroundEngine::visitor_wake();
	check_wake( get_option( BackgroundEngine::LAST_VISITOR_WAKE_OPTION, 0 ) > 0, 'A retry succeeds after the attempt interval' );
	echo "VisitorWakeShutdownTest: all assertions passed\n";
}
