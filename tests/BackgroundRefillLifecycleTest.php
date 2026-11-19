<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Options;
use TranslateWP\Components\ReadinessBudget;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\KeepWarm;
use TranslateWP\Functionality\LexiconChanges;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_lifecycle( bool $ok, string $message ): void {
    if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function lifecycle_fixture(): array {
    wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'default' => true, 'active' => true ], 'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true ] ] ] ] );
    $GLOBALS['wp_test_remote_request_calls'] = [];
    return [ new IndexQueue(), new Indexer( '', '', null, null, false ) ];
}
function lifecycle_row( IndexQueue $queue, int $object_id ): int {
    return (int) $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => $object_id, 'url' => 'https://example.com/shop/', 'page_title' => '', 'language' => 'it', 'priority' => 50, 'payload' => wp_json_encode( [ [ 'groupId' => 'g' . $object_id, 'markerPayload' => '⟪item_0001⟫Shop', 'cache' => [ 'hash' => hash( 'sha256', (string) $object_id ) ] ] ] ) ] );
}
$case = $argv[1] ?? 'all';
if ( in_array( $case, [ 'visitor', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    $id = lifecycle_row( $queue, 321 );
    $queue->track_refill( $id );
    $queue->claim_rows( [ $id ] ); $queue->complete( $id );
    $queue->mark_translated( [ $id ] );
    update_option( Indexer::REPAIR_RETRY_OPTION, time() + 7200, false );
    lifecycle_row( $queue, 321 );
    $indexer->reduced_push( new ReadinessBudget() );
    $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
    check_lifecycle( 1 === count( $creates ), 'A visitor miss after refill does not wait for the repair backoff' );
    check_lifecycle( ! isset( $creates[0]['body']['purpose'] ), 'A visitor miss after refill has no background purpose' );
}
if ( in_array( $case, [ 'seed', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture();
    Options::update( 'cache.lexicon_changed_at', [ 'it' => time() ] );
    for ( $i = 1; $i <= 25; ++$i ) { $id = lifecycle_row( $queue, $i ); $queue->claim_rows( [ $id ] ); $queue->complete( $id ); }
    $GLOBALS['wp_test_get_posts_calls'] = [];
    $changes = new LexiconChanges();
    $changes->seed();
    $scans = array_filter( $GLOBALS['wp_test_get_posts_calls'], static fn( array $r ): bool => IndexQueue::POST_TYPE === ( $r['post_type'] ?? '' ) );
    check_lifecycle( [] === $scans, 'Upgrade init does not scan translation rows' );
    $changes->run_seed_batch();
    $first = count( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ) );
    check_lifecycle( $first > 0 && $first < 25, 'The migration advances one bounded chunk' );
    $restarted = new LexiconChanges();
    for ( $i = 0; $i < 10; ++$i ) { $restarted->run_seed_batch(); }
    check_lifecycle( 25 === count( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ) ), 'An interrupted migration resumes and queues the remaining rows' );
}
if ( in_array( $case, [ 'late', 'all' ], true ) ) {
    foreach ( [ KeepWarm::LEXICON_DEBOUNCE_HOOK, KeepWarm::SETTINGS_DEBOUNCE_HOOK, KeepWarm::VERSIONED_REFILL_HOOK ] as $hook ) {
        lifecycle_fixture();
        $started = 0;
        add_action( $hook, static function () use ( &$started ): void { ++$started; } );
        wp_schedule_single_event( time() - 120, $hook );
        $cron = new CronFallback();
        check_lifecycle( $cron->is_behind(), 'A late refill is counted even without push rows' );
        $cron->run_maintenance();
        check_lifecycle( 1 === $started, 'Worker maintenance starts a late refill without WP-Cron' );
        wp_schedule_single_event( time() - 120, $hook );
        ( new Indexer( '', '', null, null, false ) )->reduced_read( new ReadinessBudget() );
        check_lifecycle( 2 === $started, 'The reduced read phase starts the same late refill without WP-Cron' );
    }
}
if ( in_array( $case, [ 'kick', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    wp_schedule_single_event( time() - 120, Indexer::DRAIN_HOOK );
    $indexer->kick_due_drain();
    $first = get_option( Indexer::LAST_KICK_OPTION );
    check_lifecycle( (int) $first >= time() - 1, 'An overdue kick records its persisted gate' );
    $indexer->kick_due_drain();
    check_lifecycle( $first === get_option( Indexer::LAST_KICK_OPTION ) && 1 === count( $GLOBALS['wp_test_remote_request_calls'] ), 'Repeated requests send only one kick per minute' );
}
if ( in_array( $case, [ 'reread', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture();
    $row = [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'page_title' => '', 'priority' => 10 ];
    $queue->enqueue_read( [ $row ] );
    $id = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 )[0]['id'];
    $old = [ [ 'groupId' => 'old', 'markerPayload' => '⟪item_0001⟫Shop' ] ];
    $new = [ [ 'groupId' => 'new', 'markerPayload' => '⟪item_0001⟫Store' ] ];
    $queue->store_payload( $id, wp_json_encode( $old ) );
    $queue->enqueue_read( [ $row ] );
    check_lifecycle( $old === $queue->read_items( 'page', 2 ), 'A pending reread retains the last successful crawler payload' );
    $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
    $queue->append_extraction( $id, $new, false );
    check_lifecycle( $old === $queue->read_items( 'page', 2 ), 'Partial replacement extraction does not leak to crawler reads' );
    $queue->append_extraction( $id, [], true );
    check_lifecycle( $new === $queue->read_items( 'page', 2 ), 'A successful reread replaces rather than appends to the old payload' );
}
if ( in_array( $case, [ 'lock', 'all' ], true ) ) {
    lifecycle_fixture();
    $previous = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = new class() extends WP_Testwpdb {
        public function get_var( string $query ) { return null; }
    };
    \TranslateWP\Components\QueueExecution::run( static function (): void {} );
    check_lifecycle( false !== strpos( CronFallback::notice_message(), 'database' ), 'Unsupported named locks are visible in the Dashboard' );
    $GLOBALS['wpdb'] = $previous;
}
if ( in_array( $case, [ 'metadata', 'all' ], true ) ) {
    lifecycle_fixture();
    $html = '<html><head><title>Shop | Our site</title><meta property="og:title" content="Share our shop"><meta name="twitter:title" content="Shop on social"><meta name="description" content="Read about our shop"><meta property="og:description" content="Share our products"><meta name="twitter:description" content="See our products"></head><body><p>Welcome</p></body></html>';
    $free = \TranslateWP\Components\RenderedPageExtraction::extract( $html );
    $payloads = array_column( $free, 'markerPayload' );
    check_lifecycle( in_array( '⟪item_0001⟫Share our shop', $payloads, true ) && in_array( '⟪item_0001⟫Shop on social', $payloads, true ), 'Background reads warm whole share titles with the existing marker' );
    check_lifecycle( ! in_array( '⟪item_0001⟫Read about our shop', $payloads, true ), 'Free background reads do not warm paid descriptions' );
    set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
    wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => true );
    $pro = \TranslateWP\Components\RenderedPageExtraction::extract( $html );
    $contexts = array_column( $pro, 'contextKey' );
    foreach ( [ 'seo:meta_description', 'seo:og_description', 'seo:twitter_description' ] as $context ) {
        check_lifecycle( in_array( $context, $contexts, true ), 'Entitled background reads warm each supported description' );
    }
    $titles = array_values( array_filter( $pro, static fn( array $group ): bool => '⟪item_0001⟫Shop | Our site' === $group['markerPayload'] ) );
    check_lifecycle( 1 === count( $titles ) && 'seo:title' === $titles[0]['contextKey'] && 'attribute' === $titles[0]['contextKind'], 'Whole title warming retains the visitor title context and marker contract' );
}
echo "BackgroundRefillLifecycleTest: all assertions passed\n";
