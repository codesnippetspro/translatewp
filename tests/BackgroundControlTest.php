<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundSwitch;
use TranslateWP\Components\IndexQueue;
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

[ $queue ] = control_fixture();
$page = $queue->enqueue_push( [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 50, 'payload' => '[{"groupId":"page","markerPayload":"Page body"}]' ] );
$miss = control_row( $queue, 123 );
$queue->claim_rows( [ $page, $miss ] ); $queue->complete( $page ); $queue->complete( $miss );
$queue->maintain_duplicates();
check_control( null !== get_post( $page ) && null !== get_post( $miss ), 'Automatic cleanup never treats a page and a visitor miss as duplicates' );
$other = control_row( $queue, 456 ); $queue->claim_rows( [ $other ] ); $queue->complete( $other );
$dead = control_row( $queue, 789 ); $queue->claim_rows( [ $dead ] );
for ( $i = 0; $i < 3; ++$i ) { $queue->fail( $dead, 'failed' ); $queue->claim_rows( [ $dead ] ); }
$queue->collapse_stale_duplicates( true );
check_control( null !== get_post( $other ) && null !== get_post( $dead ), 'Distinct object identities and the only copy of failed visitor work survive cleanup' );
$copy = array_values( array_filter( $GLOBALS['wp_test_inserted_posts'], static fn ( array $post ): bool => $post['ID'] === $page ) )[0]; unset( $copy['ID'] ); $duplicate = wp_insert_post( $copy );
$queue->collapse_stale_duplicates( true );
check_control( null === get_post( $page ) && null !== get_post( $duplicate ), 'Only settled true duplicates of the same object and language collapse' );

[ $queue ] = control_fixture();
$old = control_row( $queue, 123 ); $queue->claim_rows( [ $old ] ); $queue->complete( $old ); $queue->mark_translated( [ $old ] );
$copy = $GLOBALS['wp_test_inserted_posts'][0]; unset( $copy['ID'] ); $new = wp_insert_post( $copy );
$GLOBALS['wp_test_after_post_update'] = static function () use ( $old ): void {
    update_post_meta( $old, '_twp_status', IndexQueue::STATUS_PENDING );
    wp_update_post( [ 'ID' => $old, 'post_content' => '[{"groupId":"new-arrival","markerPayload":"New visitor work"}]' ] );
};
$queue->collapse_stale_duplicates( true );
check_control( null !== get_post( $old ) && IndexQueue::STATUS_PENDING === get_post_meta( $old, '_twp_status', true ), 'A row reopened between the snapshot and deletion is retained' );


[ $queue ] = control_fixture();
$miss_row = [ 'object_type' => 'miss', 'object_id' => 123, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10 ];
$first_items = [ [ 'groupId' => 'first', 'markerPayload' => '⟪item_0001⟫First visitor group' ] ];
$late_items = [ [ 'groupId' => 'late', 'markerPayload' => '⟪item_0001⟫Late visitor group' ] ];
$first = $queue->enqueue_miss_page( $miss_row, $first_items );
$queue->claim_rows( [ $first ] );
$late = $queue->enqueue_miss_page( $miss_row, $late_items );
check_control( $late !== $first && IndexQueue::STATUS_PENDING === get_post_meta( $late, '_twp_status', true ), 'A visitor arrival during an active push gets its own pending row' );
check_control( $first_items === json_decode( IndexQueue::decode_payload( (string) get_post_field( 'post_content', $first ) ), true ), 'An active push keeps its captured payload unchanged' );
$queue->complete( $first );
$queue->claim_rows( [ $late ] ); $queue->complete( $late );
$queue->collapse_stale_duplicates( true );
check_control( null !== get_post( $first ) && null !== get_post( $late ), 'Automatic compaction preserves both unconfirmed visitor deliveries' );
$third = $queue->enqueue_miss_page( $miss_row, [ [ 'groupId' => 'third', 'markerPayload' => '⟪item_0001⟫Third visitor group' ] ] );
check_control( $third !== $first && $third !== $late, 'A new visitor group cannot overwrite an unconfirmed delivery manifest' );
$queue->mark_translated( [ $first, $late ] );
check_control( 1 === $queue->collapse_stale_duplicates( true ), 'Confirmed visitor duplicates can still be compacted' );


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
	check_control( 'paused' === \TranslateWP\Components\BackgroundEngine::step()['state'], 'Paused steps refuse every driver' );
	\TranslateWP\Components\BackgroundEngine::cron();
	$indexer->ensure_spine(); $indexer->schedule_spine_refresh();
	Indexer::refill_crawler_page( 123, 'https://example.com/shop/', 'it' );
	$indexer->drain_tick();
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
