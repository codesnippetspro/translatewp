<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundSwitch;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_control( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function control_fixture(): array {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'default' => true, 'active' => true ], 'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true ] ] ] ] );
	$GLOBALS['wp_test_remote_request_calls'] = [];
	$GLOBALS['wp_test_is_admin'] = false;
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
	return [ new IndexQueue(), new Indexer( '', '', null, null, false ), new CronFallback() ];
}
function queue_query_count(): int {
	return count( array_filter( $GLOBALS['wp_test_get_posts_calls'] ?? [], static fn( array $q ): bool => IndexQueue::POST_TYPE === ( $q['post_type'] ?? '' ) ) );
}
function control_row( IndexQueue $queue, int $object_id ): int {
	return (int) $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => $object_id, 'url' => 'https://example.com/shop/', 'language' => 'it', 'page_title' => '', 'priority' => 50, 'payload' => wp_json_encode( [ [ 'groupId' => 'group-' . $object_id, 'markerPayload' => '⟪item_0001⟫Shop' ] ] ) ] );
}
function expire_control_probe(): void {
	$result = get_option( IndexQueue::DUE_RESULT_OPTION, [] );
	$result['checkedAt'] = time() - 61;
	update_option( IndexQueue::DUE_RESULT_OPTION, $result, false );
}

[ $queue, $indexer, $cron ] = control_fixture();
$GLOBALS['wp_test_get_posts_calls'] = [];
$cron->maybe_run_on_front_end();
check_control( 0 === queue_query_count(), 'An unprimed idle visitor performs zero queue queries' );
check_control( false !== get_transient( 'translatewp_cron_fallback_kick' ), 'An idle check reserves the minute gate too' );
delete_transient( 'translatewp_cron_fallback_kick' );
$cron->maybe_run_on_front_end();
check_control( 0 === queue_query_count(), 'An idle visitor after the minute gate expires still performs zero queue queries' );

$counter = new class() extends CronFallback {
	public int $checks = 0;
	public function is_behind(): bool { ++$this->checks; return true; }
};
$counter->maybe_run_on_front_end();
check_control( 0 === $counter->checks, 'The persisted gate is checked before is_behind' );

check_control( ! $queue->has_due_work(), 'The worker records an empty queue' );
$probes = queue_query_count();
check_control( 2 === $probes, 'The idle worker probes pending and abandoned claims once' );
$queue->has_due_work();
QueueExecution::run( static function (): void {} );
check_control( $probes === queue_query_count(), 'Other workers reuse the persisted probe within a minute' );
expire_control_probe();
$queue->has_due_work();
check_control( $probes + 2 === queue_query_count(), 'A worker refreshes the result after the minute' );
$GLOBALS['wp_test_get_posts_calls'] = [];
delete_transient( 'translatewp_cron_fallback_kick' );
$cron->maybe_run_on_front_end();
check_control( 0 === queue_query_count(), 'A primed idle visitor performs zero queue queries' );

$id = control_row( $queue, 321 );
Indexer::schedule_drain();
wp_unschedule_event( (int) wp_next_scheduled( Indexer::DRAIN_HOOK ), Indexer::DRAIN_HOOK );
$GLOBALS['wp_test_get_posts_calls'] = [];
delete_transient( 'translatewp_cron_fallback_kick' );
$cron->maybe_run_on_front_end();
check_control( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ) && 0 === queue_query_count(), 'A persisted producer hint recovers a lost event without a visitor query' );
check_control( IndexQueue::STATUS_PENDING === get_post_meta( $id, '_twp_status', true ), 'Recovery leaves processing to the worker' );

[ $queue ] = control_fixture();
Indexer::schedule_drain( 120 );
$queue->has_due_work();
check_control( false === IndexQueue::cached_due_work(), 'A future producer hint respects its backoff' );
$hint = get_option( IndexQueue::DUE_HINT_OPTION );
$hint['nextAt'] = time() - 1;
update_option( IndexQueue::DUE_HINT_OPTION, $hint, false );
check_control( true === IndexQueue::cached_due_work(), 'A future hint is not consumed by an earlier empty probe' );

[ $queue, $indexer, $cron ] = control_fixture();
$old = control_row( $queue, 1 );
$queue->track_refill( $old );
$queue->claim_rows( [ $old ] ); $queue->complete( $old ); $queue->mark_translated( [ $old ] );
$new = control_row( $queue, 2 );
$queue->claim_rows( [ $new ] ); $queue->complete( $new ); $queue->mark_translated( [ $new ] );
$active = control_row( $queue, 3 ); $queue->track_refill( $active );
$visitor = control_row( $queue, 4 );
$queue->claim_rows( [ $visitor ] );
$cron->run_maintenance();
check_control( false === get_post( $old ) || null === get_post( $old ), 'Worker maintenance removes a settled duplicate without an admin page' );
$payload = json_decode( IndexQueue::decode_payload( (string) get_post_field( 'post_content', $new ) ), true );
check_control( [ 'group-1', 'group-2' ] === array_column( $payload, 'groupId' ), 'Duplicate collapse retains both miss payloads' );
$queries = queue_query_count();
$cron->run_maintenance();
check_control( $queries === queue_query_count(), 'A second maintenance pass reuses the site-wide minute gate' );
check_control( null !== get_post( $active ) && null !== get_post( $visitor ), 'Maintenance keeps active refill and visitor work separate' );
check_control( $queue->requires_background_refresh( $active ) && ! $queue->requires_background_refresh( $visitor ), 'Compaction never marks a visitor row as background work' );

[ $queue, $indexer, $cron ] = control_fixture();
$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'plan' => 'free', 'entitled' => false, 'backgroundPaused' => true ] ) ];
ProEligibility::fetch_fresh();
check_control( BackgroundSwitch::off(), 'The authenticated eligibility response persists backgroundPaused=true' );
$urls = $GLOBALS['wp_test_remote_get_urls'];
check_control( false !== strpos( end( $urls ), '/v1/pro/eligibility' ) && 'Bearer test-token' === $GLOBALS['wp_test_remote_get_args'][0]['headers']['Authorization'], 'The switch uses the existing site-token eligibility call' );
foreach ( [ [], [ 'backgroundPaused' => 'false' ], [ 'backgroundPaused' => null ] ] as $response ) {
	$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $response ) ];
	ProEligibility::fetch_fresh();
	check_control( BackgroundSwitch::off(), 'An absent or malformed flag cannot unpause the worker' );
}
$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 503 ], 'body' => wp_json_encode( [ 'plan' => 'free', 'backgroundPaused' => false ] ) ];
ProEligibility::fetch_fresh();
check_control( BackgroundSwitch::off(), 'A failed service response cannot clear the switch' );
$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'http_request_failed', 'offline' );
ProEligibility::fetch_fresh();
check_control( BackgroundSwitch::off(), 'Transport failure keeps the last switch value' );

function check_paused_entries( Indexer $indexer, CronFallback $cron ): void {
	$work = 0;
	$GLOBALS['wp_test_get_posts_calls'] = [];
	$GLOBALS['wp_test_remote_request_calls'] = [];
	$before = $GLOBALS['wp_test_inserted_posts'];
	check_control( ! QueueExecution::run( static function () use ( &$work ): void { ++$work; } ), 'Paused run refuses its callback' );
	check_control( ! QueueExecution::run_reduced(), 'Paused reduced mode refuses its callback' );
	check_control( ! LoopbackKick::fire(), 'Paused workers send no self-request' );
	LoopbackKick::probe();
	$cron->maybe_run_on_front_end(); $cron->run_after_flush(); $cron->maybe_run_in_admin(); $cron->run_maintenance();
	$indexer->ensure_spine(); $indexer->schedule_spine_refresh();
	Indexer::refill_crawler_page( 123, 'https://example.com/shop/', 'it' );
	$indexer->drain_tick(); $indexer->drain_now();
	check_control( 0 === $work && 0 === queue_query_count() && [] === $GLOBALS['wp_test_remote_request_calls'], 'All paused entry points avoid work, queue reads and kicks' );
	check_control( false === wp_next_scheduled( Indexer::DRAIN_HOOK ) && ! get_option( Indexer::SPINE_PENDING_OPTION, false ) && ! get_option( Indexer::SPINE_CHECKED_OPTION, false ), 'Pause does not consume spine setup or reschedule a disabled drain' );
	check_control( $before === $GLOBALS['wp_test_inserted_posts'], 'Paused crawler and spine paths add no rows' );
}
check_paused_entries( $indexer, $cron );
$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'plan' => 'free', 'backgroundPaused' => false ] ) ];
ProEligibility::fetch_fresh();
check_control( ! BackgroundSwitch::off() && false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Eligibility continues while paused and false clears the option and resumes scheduling' );
$ran = false;
QueueExecution::run( static function () use ( &$ran ): void { $ran = true; } );
check_control( $ran, 'Work resumes after an acknowledged unpause' );

[ $queue, $indexer, $cron ] = control_fixture();
define( 'TRANSLATEWP_BACKGROUND_OFF', false );
check_control( BackgroundSwitch::off(), 'Defining the emergency constant stops work even with value false' );
BackgroundSwitch::observe( [ 'backgroundPaused' => false ] );
check_control( BackgroundSwitch::off(), 'The service cannot override the emergency constant' );
check_paused_entries( $indexer, $cron );
echo "BackgroundControlTest: all assertions passed\n";
