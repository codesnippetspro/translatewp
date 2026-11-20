<?php

declare(strict_types=1);

namespace TranslateWP\Functionality {
	function register_shutdown_function( callable $callback ): void { $GLOBALS['visitor_shutdown'][] = $callback; }
}

namespace {
	use TranslateWP\Components\IndexQueue;
	use TranslateWP\Functionality\Indexer;

	require dirname( __DIR__ ) . '/vendor/autoload.php';
	require __DIR__ . '/wp-test-stubs.php';

	function check_fast_path( bool $ok, string $message ): void {
		if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
	}
	function fastcgi_finish_request(): bool {
		$GLOBALS['finish_calls']++;
		return $GLOBALS['finish_ok'];
	}
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ], 'it' => [ 'locale' => 'it', 'active' => true ] ] ] ] );
	$GLOBALS['finish_ok'] = true; $GLOBALS['finish_calls'] = 0;
	$queue = new IndexQueue();
	$row = static fn ( int $id ): array => [ 'object_type' => 'miss', 'object_id' => $id, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10, 'payload' => wp_json_encode( [ [ 'groupId' => 'g' . $id, 'markerPayload' => '⟪item_0001⟫Our shop ' . $id ] ] ) ];
	$own = $queue->enqueue_push( $row( 1 ) ); $other = $queue->enqueue_push( $row( 2 ) );
	check_fast_path( 'inline' === Indexer::schedule_inline_drain( [ $own ] ), 'FPM retains the released visitor-owned REST fast path' );
	check_fast_path( [] === $GLOBALS['wp_test_remote_posts'], 'No model work starts before the REST response finishes' );
	$GLOBALS['inline_http_timeouts'] = [];
	wp_test_set_filter( 'translatewp_site_request_headers', static function ( array $headers ): array {
		if ( \TranslateWP\Components\QueueExecution::is_bounded() ) {
			$GLOBALS['inline_http_timeouts'][] = \TranslateWP\Components\QueueExecution::bound_http_args( [ 'timeout' => 90 ] )['timeout'];
		}
		return $headers;
	} );
	array_shift( $GLOBALS['visitor_shutdown'] )();
	check_fast_path( [] !== $GLOBALS['inline_http_timeouts'] && max( $GLOBALS['inline_http_timeouts'] ) <= 2.5, 'Every actual miss push request uses the smaller inline HTTP deadline' );
	check_fast_path( 1 === $GLOBALS['finish_calls'], 'The own REST response is flushed before its drain' );
	check_fast_path( IndexQueue::STATUS_DONE === get_post_meta( $own, '_twp_status', true ), 'The visitor own row starts immediately after its response' );
	check_fast_path( IndexQueue::STATUS_PENDING === get_post_meta( $other, '_twp_status', true ), 'Foreign visitor work never joins the own-row fast path' );
	$creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $post ): bool => '/v1/translation/jobs' === $post['path'] ) );
	check_fast_path( 1 === count( $creates ) && ! isset( $creates[0]['body']['purpose'] ), 'The preserved visitor path does not become background refresh work' );
	$GLOBALS['finish_ok'] = false;
	Indexer::schedule_inline_drain( [ $other ] ); array_shift( $GLOBALS['visitor_shutdown'] )();
	check_fast_path( IndexQueue::STATUS_PENDING === get_post_meta( $other, '_twp_status', true ), 'A failed finish cannot drain before the response' );
	check_fast_path( false === get_transient( Indexer::INLINE_SLOT_TRANSIENT ), 'Every path releases its slot' );
	$GLOBALS['finish_ok'] = true;
	Indexer::schedule_inline_drain( [ $other ], false );
	array_shift( $GLOBALS['visitor_shutdown'] )();
	check_fast_path( IndexQueue::STATUS_PENDING === get_post_meta( $other, '_twp_status', true ), 'Programmatic head misses get a shutdown wake without entering the REST-only inline push' );
	$seen_timeout = 0.0;
	\TranslateWP\Components\QueueExecution::run( static function () use ( &$seen_timeout ): void {
		$seen_timeout = apply_filters( 'http_request_args', [ 'timeout' => 90 ] )['timeout'];
	}, Indexer::INLINE_BUDGET_SECONDS );
	check_fast_path( $seen_timeout > 0 && $seen_timeout <= 2.5, 'The own miss REST HTTP budget is bounded to 2.5 seconds, not eight' );
	fwrite( STDOUT, "VisitorMissFastPathTest: all assertions passed\n" );
}
