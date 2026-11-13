<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Migrations;
use TranslateWP\Components\ReadinessBudget;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';
define( 'TRANSLATEWP_VERSION', '1.0.3' );

$failures = 0;
function polling_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		++$failures;
	}
}
function polling_reset(): array {
	wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'fixture-token' ] ] );
	$queue = new IndexQueue();
	return [ $queue, new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue ) ];
}
function polling_row( IndexQueue $queue, int $object, string $job ): int {
	$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => $object, 'language' => 'it', 'url' => 'https://example.com/page-' . $object, 'priority' => 1 ] ] );
	$rows = $queue->list_rows();
	$id = (int) end( $rows )['id'];
	update_post_meta( $id, '_twp_status', IndexQueue::STATUS_DONE );
	$queue->remember_job( $id, $job );
	return $id;
}

foreach ( [ 'done', 'failed', 'cancelled', 'expired', 'unknown', 'finished', 'completed', 'canceled', 'missing' ] as $state ) {
	[ $queue, $indexer ] = polling_reset();
	$first = polling_row( $queue, 1, 'terminal' );
	$second = polling_row( $queue, 2, 'terminal' );
	$GLOBALS['wp_test_job_states']['terminal'] = $state;
	if ( 'missing' === $state ) {
		$GLOBALS['wp_test_job_missing'] = [ 'terminal' ];
	}
	$indexer->poll_remembered_jobs( new ReadinessBudget() );
	polling_check( [] === $queue->remembered_jobs(), "$state clears every row's polling reference" );
	$expected = in_array( $state, [ 'done', 'finished', 'completed' ], true ) ? 'done' : ( 'missing' === $state ? 'unknown' : ( 'canceled' === $state ? 'cancelled' : $state ) );
	polling_check( $expected === $queue->job_outcome( $first ) && $expected === $queue->job_outcome( $second ), "$state retains the outcome for every row" );
	$known = [];
	polling_check( $expected === $indexer->job_outcome_for_row( $second, $known ), "$state stays settled without another service call" );
	polling_check( 1 === count( $GLOBALS['wp_test_job_status_requests'] ), "$state polls the shared job only once" );
	$queue->remember_job( $first, 'replacement' );
	polling_check( 'unknown' === $queue->job_outcome( $first ), 'A replacement job clears the previous completion evidence' );
}

[ $queue, $indexer ] = polling_reset();
for ( $i = 1; $i <= 5; ++$i ) {
	polling_row( $queue, $i, 'open-' . $i );
}
$indexer->poll_remembered_jobs( new ReadinessBudget() );
polling_check( [ 'open-1', 'open-2', 'open-3' ] === $GLOBALS['wp_test_job_status_requests'], 'A request makes at most three status calls' );
$indexer->poll_remembered_jobs( new ReadinessBudget() );
polling_check( [ 'open-4', 'open-5', 'open-1' ] === array_slice( $GLOBALS['wp_test_job_status_requests'], 3 ), 'Open jobs cannot starve later job IDs' );
polling_check( 5 === count( $queue->remembered_jobs() ), 'Open jobs keep all their references' );

[ $queue, $indexer ] = polling_reset();
for ( $i = 1; $i <= 4; ++$i ) {
	polling_row( $queue, $i, 'slow-' . $i );
}
$now = 100.0;
$budget = new ReadinessBudget( 3.0, static function () use ( &$now ): float { return $now; } );
wp_test_set_filter( 'translatewp_site_request_headers', static function ( $headers ) use ( &$now ) { $now += 1.6; return $headers; } );
$indexer->poll_remembered_jobs( $budget );
polling_check( 2 === count( $GLOBALS['wp_test_job_status_requests'] ), 'Elapsed time stops the request before a third slow call' );
$timeouts = array_column( $GLOBALS['wp_test_remote_get_args'], 'timeout' );
polling_check( 3.0 === $timeouts[0] && abs( 1.4 - $timeouts[1] ) < 0.00001, 'Each HTTP timeout is bounded by the remaining shared budget' );
polling_check( 0.0 === $budget->remaining_seconds(), 'The exhausted budget cannot admit further remote work' );

[ $queue, $indexer ] = polling_reset();
$id = polling_row( $queue, 1, 'retry' );
$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 503 ], 'body' => '{}' ];
update_option( Migrations::JOB_CLEANUP_OPTION, [ 'retry' ] );
$indexer->poll_remembered_jobs( new ReadinessBudget() );
polling_check( 'retry' === $queue->job_id( $id ), 'A service failure keeps its polling reference' );
polling_check( [ 'retry' ] === get_option( Migrations::JOB_CLEANUP_OPTION ), 'An inconclusive cleanup retries on a later request' );
$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
$indexer->poll_remembered_jobs( new ReadinessBudget() );
polling_check( 'retry' === $queue->job_id( $id ) && [ 'retry' ] === get_option( Migrations::JOB_CLEANUP_OPTION ), 'A malformed status response is inconclusive, not a completed cleanup' );

[ $queue, $indexer ] = polling_reset();
for ( $i = 1; $i <= 8; ++$i ) {
	polling_row( $queue, $i, 'old-' . $i );
	$GLOBALS['wp_test_job_states'][ 'old-' . $i ] = 'done';
}
update_option( Migrations::VERSION_OPTION, TRANSLATEWP_VERSION );
Migrations::maybe_run();
polling_check( 8 === count( get_option( Migrations::JOB_CLEANUP_OPTION ) ), 'The one-time cleanup records old references even on a prior release candidate' );
polling_check( [] === $GLOBALS['wp_test_job_status_requests'], 'Migration never polls the service during page rendering' );
for ( $pass = 0; $pass < 3; ++$pass ) {
	$before = count( $GLOBALS['wp_test_job_status_requests'] );
	$indexer->poll_remembered_jobs( new ReadinessBudget() );
	polling_check( count( $GLOBALS['wp_test_job_status_requests'] ) - $before <= 3, 'Cleanup shares the per-request call cap' );
}
polling_check( [] === $queue->remembered_jobs() && [] === get_option( Migrations::JOB_CLEANUP_OPTION ), 'Bounded passes empty the historical done-job list' );
polling_row( $queue, 9, 'new-open' );
Migrations::maybe_run();
polling_check( [] === get_option( Migrations::JOB_CLEANUP_OPTION ), 'Completed migration does not resnapshot later jobs' );
$indexer->poll_remembered_jobs( new ReadinessBudget() );
polling_check( [ 'new-open' ] === array_keys( $queue->remembered_jobs() ), 'Later active work survives migration and polling' );

[ $queue ] = polling_reset();
$id = polling_row( $queue, 1, 'unused' );
TranslationReadiness::record( $id, 'it', [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'a', 64 ) ], 'markerPayload' => '⟪item_0001⟫Title' ] ] );
$rows = $queue->list_rows();
TranslationReadiness::refresh( $rows, 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Titolo' ] );
$result = TranslationReadiness::refresh( $rows, 0, static function () { throw new RuntimeException( 'No lookup fits the budget' ); }, new ReadinessBudget( 0 ) );
polling_check( [] === $result['rowIds'] && TranslationReadiness::is_ready( $rows[0] ), 'Budget exhaustion neither withdraws proof nor triggers self-heal' );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "ReadinessJobPollingTest: all assertions passed\n";
