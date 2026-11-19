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
    unset( $GLOBALS['wp_test_loopback_response'] );
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
    $indexer->step_push( new ReadinessBudget() );
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
        \TranslateWP\Components\QueueExecution::run( static function (): void { CronFallback::run_refill_maintenance(); } );
        check_lifecycle( 1 === $started, 'The shared maintenance phase resumes a late refill without WP-Cron' );
    }
}

if ( in_array( $case, [ 'kick', 'all' ], true ) ) {
    lifecycle_fixture();
    for ( $i = 0; $i < 10; ++$i ) { Indexer::schedule_drain(); }
    check_lifecycle( false !== wp_next_scheduled( \TranslateWP\Components\BackgroundEngine::WAKE_HOOK ), 'Queue producers schedule a service wake' );
    check_lifecycle( [] === $GLOBALS['wp_test_remote_request_calls'], 'Queue producers never call their own site' );
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
    $ran = false;
    \TranslateWP\Components\QueueExecution::run( static function () use ( &$ran ): void { $ran = true; } );
    check_lifecycle( $ran && ! get_transient( \TranslateWP\Components\MutationLock::FAILURE_TRANSIENT ), 'Unsupported named locks use the option lease without a false warning' );
    $GLOBALS['wp_test_option_insert_fail'] = true;
    \TranslateWP\Components\QueueExecution::run( static function (): void {} );
    check_lifecycle( false !== strpos( CronFallback::notice_message(), 'database' ), 'A failure of both lock mechanisms is visible in the Dashboard' );
    unset( $GLOBALS['wp_test_option_insert_fail'] );
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
if ( in_array( $case, [ 'reader', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    $queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'page_title' => '', 'language' => 'it', 'priority' => 10 ] ] );
    $id = $queue->list_rows()[0]['id'];
    $GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'http_request_failed', 'cURL error 60: certificate verification failed' );
    $last = 0;
    for ( $i = 0; $i < 4; ++$i ) {
        delete_option( Indexer::NEXT_READER_OPTION );
        delete_post_meta( $id, '_twp_reader_not_before' );
        $indexer->step_read( new ReadinessBudget() );
        check_lifecycle( IndexQueue::STATUS_PENDING === $queue->list_rows()[0]['status'], 'An unreadable TLS page remains pending beyond the terminal retry limit' );
        $next = (int) get_post_meta( $id, '_twp_reader_not_before', true );
        check_lifecycle( $next > time() && $next > $last, 'TLS retries persist an increasing backoff' );
        $last = $next;
    }
}
if ( in_array( $case, [ 'mode', 'all' ], true ) ) {
    lifecycle_fixture();
    update_option( Indexer::READER_FAILURE_OPTION, 'page_tls_failed', false );
    check_lifecycle( false !== strpos( CronFallback::notice_message(), 'HTTPS certificate' ) && false !== strpos( CronFallback::notice_message(), 'Indexing' ), 'The reader notice names the cause and browser fallback' );
    check_lifecycle( ! method_exists( CronFallback::class, 'run_after_flush' ), 'No queue work remains after visitor responses' );
}

if ( in_array( $case, [ 'routing', 'all' ], true ) ) {
    foreach ( [ 'service', 'cron', 'admin' ] as $driver ) {
      foreach ( [ true, false ] as $echo ) {
        [ $queue, $indexer ] = lifecycle_fixture();
        $id = lifecycle_row( $queue, 321 );
        $queue->track_refill( $id );
        for ( $attempt = 0; $attempt < 3; ++$attempt ) { $queue->claim_rows( [ $id ] ); $queue->fail( $id, 'temporary_provider_failure' ); }
        $queue->retry_failed();
        check_lifecycle( $queue->list_rows()[0]['retranslating'], 'Retrying a settled refill restarts its completion tracking' );
        if ( ! $echo ) { $GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 201 ], 'body' => wp_json_encode( [ 'jobId' => '00000000-0000-4000-8000-000000000099' ] ) ] ]; }
        update_option( \TranslateWP\Components\BackgroundEngine::PHASE_OPTION, 1, false );
        if ( 'service' === $driver ) { \TranslateWP\Components\BackgroundEngine::service_step( [] ); }
        elseif ( 'cron' === $driver ) { \TranslateWP\Components\BackgroundEngine::cron(); }
        else { ( new \TranslateWP\Functionality\Endpoints\Background() )->admin_step( new WP_REST_Request( 'POST', '' ) ); }
        $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
        check_lifecycle( 1 === count( $creates ) && 'background_refresh' === ( $creates[0]['body']['purpose'] ?? '' ), 'Retried refill purpose survives progress settlement through the ' . $driver . ' driver' );
        if ( ! $echo ) {
            $batches = array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => false !== strpos( $r['path'], '/batches' ) );
            check_lifecycle( [] === $batches, 'The ' . $driver . ' driver pushes nothing without a purpose echo' );
        }
      }
    }
}
if ( in_array( $case, [ 'failure-log', 'all' ], true ) ) {
    lifecycle_fixture();
    for ( $i = 0; $i < 10; ++$i ) { Indexer::schedule_drain(); }
    check_lifecycle( [] === \TranslateWP\Components\ActionLog::entries(), 'Scheduling a service step cannot flood the log with self-request failures' );
}

if ( in_array( $case, [ 'rewarm-routing', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    $row = [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'page_title' => '', 'language' => 'it', 'priority' => 10, 'payload' => wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫Shop' ] ] ) ];
    $id = (int) $queue->enqueue_push( $row );
    $queue->claim_rows( [ $id ] ); $queue->complete( $id ); $queue->mark_translated( [ $id ] );
    $queue->enqueue_rewarm( [ $row ] );
    $GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => 200 ], 'body' => '<html><body><p>Our shop</p></body></html>' ];
    $indexer->step_read( new ReadinessBudget() );
    $indexer->step_push( new ReadinessBudget() );
    $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
    check_lifecycle( 1 === count( $creates ) && 'background_refresh' === ( $creates[0]['body']['purpose'] ?? '' ), 'A page rewarm keeps background routing through extraction and reduced pushing' );
}
if ( in_array( $case, [ 'mixed-routing', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    $repair = lifecycle_row( $queue, 321 ); $queue->track_refill( $repair );
    lifecycle_row( $queue, 322 );
    $GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 201 ], 'body' => wp_json_encode( [ 'jobId' => '00000000-0000-4000-8000-000000000099' ] ) ] ];
    $indexer->drain_tick();
    $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
    check_lifecycle( 2 === count( $creates ) && 'background_refresh' === ( $creates[0]['body']['purpose'] ?? '' ) && ! isset( $creates[1]['body']['purpose'] ), 'One drain separates visitor work from a deferred repair job' );
    $batches = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => false !== strpos( $r['path'], '/batches' ) ) );
    check_lifecycle( 1 === count( $batches ) && 'g322' === $batches[0]['body']['items'][0]['groupId'], 'The visitor batch proceeds while repair waits for an echoed purpose' );
}
if ( in_array( $case, [ 'crawler-routing', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    Indexer::refill_crawler_page( 2, 'https://example.com/page/', 'it' );
    $rows = $queue->list_rows();
    check_lifecycle( 1 === count( $rows ), 'The crawler queues a target-language page row' );
    $id = $rows[0]['id'];
    $queue->claim_rows( [ $id ] );
    $queue->append_extraction( $id, [ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫Our shop' ] ], true );
    $indexer->step_push( new ReadinessBudget() );
    $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
    check_lifecycle( 1 === count( $creates ) && 'background_refresh' === ( $creates[0]['body']['purpose'] ?? '' ), 'Crawler refill followed by admin extraction and reduced push keeps background purpose' );
}
function held_budget( ?ReadinessBudget $budget ): void {
    $property = new ReflectionProperty( \TranslateWP\Components\QueueExecution::class, 'budget' );
    $property->setAccessible( true ); $property->setValue( null, $budget );
}
function held_completed_rows( IndexQueue $queue, int $count = 5 ): array {
    for ( $i = 1; $i <= $count; ++$i ) {
        $id = lifecycle_row( $queue, $i );
        $queue->claim_rows( [ $id ] ); $queue->complete( $id ); $queue->mark_translated( [ $id ] );
    }
    return $queue->list_rows();
}
if ( in_array( $case, [ 'deferred-ids', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture(); $rows = held_completed_rows( $queue );
    held_budget( new ReadinessBudget( 0 ) );
    try { $queue->enqueue_retranslate( $rows, false ); } finally { held_budget( null ); }
    \TranslateWP\Components\BackgroundEngine::schedule();
    $work = get_option( IndexQueue::DEFERRED_REFILLS_OPTION );
    check_lifecycle( array_column( $rows, 'id' ) === ( $work['ids'] ?? null ) && ! isset( $work['rows'] ), 'Deferred refill state stores only exact row IDs, not row payloads' );
    check_lifecycle( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Saving an entirely deferred batch leaves its next worker scheduled' );
}
if ( in_array( $case, [ 'deferred-loop', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture(); $rows = held_completed_rows( $queue );
    update_option( IndexQueue::DEFERRED_REFILLS_OPTION, [ 'ids' => array_column( $rows, 'id' ), 'force' => false ], false );
    held_budget( new ReadinessBudget( 20 ) );
    try { $queue->resume_deferred_refills(); } finally { held_budget( null ); }
    check_lifecycle( 5 === count( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ) ), 'One deferred unit drains multiple rows while its budget remains' );
    check_lifecycle( false === get_option( IndexQueue::DEFERRED_REFILLS_OPTION, false ), 'A fully drained ID list is removed' );
}
if ( in_array( $case, [ 'deferred-race', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture(); $rows = held_completed_rows( $queue );
    update_option( IndexQueue::DEFERRED_REFILLS_OPTION, [ 'rows' => $rows, 'force' => false ], false );
    $ticks = 0; held_budget( new ReadinessBudget( 1, static function () use ( &$ticks ): float { return ++$ticks <= 2 ? 0.0 : 2.0; } ) );
    try { $queue->resume_deferred_refills(); } finally { held_budget( null ); }
    $work = get_option( IndexQueue::DEFERRED_REFILLS_OPTION, [] );
    $pending = array_column( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ), 'id' );
    $remaining = $work['ids'] ?? array_column( $work['rows'] ?? [], 'id' );
    check_lifecycle( [] === array_diff( array_column( $rows, 'id' ), array_merge( $pending, $remaining ) ), 'A budget expiring between checks never drops the current deferred row' );
    $queue->resume_deferred_refills();
    check_lifecycle( 5 === count( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ) ), 'Deferred work resumes after the budget-expiry race' );
}
if ( in_array( $case, [ 'pending-visitor', 'all' ], true ) ) {
    foreach ( [ 'pending', 'claimed', 'delivery', 'deferred' ] as $state ) {
        [ $queue, $indexer ] = lifecycle_fixture();
        $refill = lifecycle_row( $queue, 321 ); $queue->track_refill( $refill );
        if ( 'pending' !== $state ) { $queue->claim_rows( [ $refill ] ); }
        if ( in_array( $state, [ 'delivery', 'deferred' ], true ) ) { $queue->complete( $refill ); }
        if ( 'deferred' === $state ) { $queue->mark_translated( [ $refill ] ); delete_post_meta( $refill, '_twp_background_refresh' ); delete_post_meta( $refill, '_twp_retranslating' ); update_option( IndexQueue::DEFERRED_REFILLS_OPTION, [ 'ids' => [ $refill ], 'force' => false ], false ); }
        $row = [ 'object_type' => 'miss', 'object_id' => 321, 'url' => 'https://example.com/shop/', 'page_title' => '', 'language' => 'it', 'priority' => 50 ];
        $visitor = $queue->enqueue_miss_page( $row, [ [ 'groupId' => 'visitor', 'markerPayload' => '⟪item_0001⟫New visitor text', 'cache' => [ 'hash' => hash( 'sha256', 'visitor' ) ] ] ] );
        check_lifecycle( $visitor !== $refill, 'Visitor text uses a separate row while refill is ' . $state );
        $again = $queue->enqueue_miss_page( $row, [ [ 'groupId' => 'visitor-more', 'markerPayload' => '⟪item_0001⟫More visitor text', 'cache' => [ 'hash' => hash( 'sha256', 'visitor-more' ) ] ] ] );
        check_lifecycle( $again === $visitor && ! $queue->requires_background_refresh( $visitor ), 'Later visitor misses merge only into the normal visitor row' );
        $queue->collapse_stale_duplicates();
        check_lifecycle( 2 === count( $queue->list_rows() ), 'Duplicate cleanup preserves separate active refill and visitor work' );
        if ( 'deferred' === $state ) { $queue->resume_deferred_refills(); }
        else { $queue->enqueue_retranslate( array_values( array_filter( $queue->list_rows(), static fn( array $r ): bool => $refill === $r['id'] ) ), false ); }
        check_lifecycle( ! $queue->requires_background_refresh( $visitor ), 'An exact-row refill does not relabel a newer visitor row' );
        update_option( Indexer::REPAIR_RETRY_OPTION, time() + 7200, false );
        $indexer->drain_tick();
        $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
        check_lifecycle( 1 === count( $creates ) && ! isset( $creates[0]['body']['purpose'] ), 'Visitor work proceeds without the pending refill purpose or backoff' );
        $batches = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => false !== strpos( $r['path'], '/batches' ) ) );
        check_lifecycle( 2 === count( $batches[0]['body']['items'] ?? [] ), 'Only visitor items enter the normal job' );
    }
}

if ( in_array( $case, [ 'deferred-budget', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture(); $rows = held_completed_rows( $queue );
    update_option( IndexQueue::DEFERRED_REFILLS_OPTION, [ 'ids' => array_column( $rows, 'id' ), 'force' => true ], false );
    $ticks = 0; held_budget( new ReadinessBudget( 1, static function () use ( &$ticks ): float { return ++$ticks <= 3 ? 0.0 : 2.0; } ) );
    try { $queue->resume_deferred_refills(); } finally { held_budget( null ); }
    $pending = array_values( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ) );
    $remaining = get_option( IndexQueue::DEFERRED_REFILLS_OPTION );
    check_lifecycle( 1 === count( $pending ) && 4 === count( $remaining['ids'] ?? [] ), 'The deferred loop stops at its budget and persists the remaining IDs' );
    check_lifecycle( 1 === (int) get_post_meta( $pending[0]['id'], '_twp_retranslate', true ), 'A deferred forced refill keeps its force policy' );
    $queue->resume_deferred_refills();
    check_lifecycle( 5 === count( array_filter( $queue->list_rows(), static fn( array $r ): bool => IndexQueue::STATUS_PENDING === $r['status'] ) ), 'The next unit drains the saved remainder' );
}
if ( in_array( $case, [ 'deferred-policy', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture(); $rows = held_completed_rows( $queue, 1 );
    $rows[0]['self_heal'] = true; $id = $rows[0]['id'];
    update_post_meta( $id, '_twp_cold_retries', 2 );
    held_budget( new ReadinessBudget( 0 ) );
    try { $queue->enqueue_retranslate( $rows, false ); } finally { held_budget( null ); }
    $queue->resume_deferred_refills();
    check_lifecycle( 2 === (int) get_post_meta( $id, '_twp_cold_retries', true ), 'Deferring self-heal work does not reset its bounded retry counter' );
}
if ( in_array( $case, [ 'legacy-cart', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture();
    $languages = [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'default' => true, 'active' => true ] ];
    foreach ( [ 'es', 'it', 'lv' ] as $language ) { $languages[$language] = [ 'locale' => $language, 'slug' => $language, 'active' => true ]; }
    Options::update( 'languages.list', $languages );
    Options::update( 'cache.lexicon_language_versions', [ 'es' => 5, 'it' => 5, 'lv' => 5 ] );
    $page = [ 'object_type' => 'page', 'object_id' => 8, 'url' => 'https://example.com/cart/', 'page_title' => 'Cart', 'priority' => 100 ];
    $queue->enqueue_read( [ $page ] );
    $source = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 )[0]['id'];
    $old = [ [ 'groupId' => 'cart', 'markerPayload' => '⟪item_0001⟫Old cart content' ] ];
    $queue->store_payload( $source, wp_json_encode( $old ) );
    foreach ( [ 'es', 'it', 'lv' ] as $language ) {
        $queue->enqueue( [ array_merge( $page, [ 'language' => $language ] ) ] );
    }
    $legacy = $queue->list_rows();
    foreach ( $legacy as $row ) {
        $id = $row['id'];
        foreach ( [ '_twp_retranslate' => 1, '_twp_retranslating' => 1, '_twp_cold_retries' => 1, '_twp_job_outcome' => 'done', '_twp_lexicon_version' => 1, '_twp_translated_at' => 1795008977, '_twp_updated_at' => 1795008970 ] as $key => $value ) { update_post_meta( $id, $key, $value ); }
        update_post_meta( $id, '_twp_delivery_manifest', [ 'scope' => 'previous-scope', 'groups' => [], 'title' => true ] );
    }
    $queue->enqueue_read( [ $page ] ); $queue->translate_after_reading( [ $page ] );
    $queue->claim_rows( [ $source ] );
    $fresh = [ [ 'groupId' => 'cart', 'markerPayload' => '⟪item_0001⟫Your Cart' ] ];
    $indexer->submit_extraction( $source, $fresh, true );
    foreach ( $queue->list_rows() as $row ) {
        check_lifecycle( IndexQueue::STAGE_PUSH === $row['stage'] && $row['retranslate'], 'A fresh source read advances each legacy pending Cart row without losing its force flag' );
    }
    $indexer->drain_tick();
    $creates = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) );
    check_lifecycle( 3 === count( $creates ), 'All three legacy Cart languages open their background jobs after the fresh read' );
    foreach ( $creates as $job ) { check_lifecycle( 'background_refresh' === ( $job['body']['purpose'] ?? '' ) && 5 === $job['body']['cacheVersions']['lexiconVersion'], 'Legacy Cart work uses acknowledged background routing at the new revision' ); }
    $batches = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $r ): bool => false !== strpos( $r['path'], '/batches' ) ) );
    foreach ( $batches as $batch ) { check_lifecycle( true === ( $batch['body']['retranslate'] ?? false ), 'The existing forced-retranslation intent is preserved on delivery' ); }
    check_lifecycle( [] === ( $GLOBALS['wp_test_remote_get_urls'] ?? [] ), 'Language rows reuse the completed source read rather than fetching Cart again' );
}
if ( in_array( $case, [ 'lost-drain', 'all' ], true ) ) {
    [ $queue ] = lifecycle_fixture(); lifecycle_row( $queue, 321 );
    $database = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = new class() extends WP_Testwpdb { public function get_var( string $query ) { return '0'; } };
    try { $response = \TranslateWP\Components\BackgroundEngine::step(); }
    finally { $GLOBALS['wpdb'] = $database; }
    check_lifecycle( 'busy' === $response['state'], 'A contended execution lock answers busy without consuming a row' );
    check_lifecycle( false !== wp_next_scheduled( \TranslateWP\Components\BackgroundEngine::CRON_HOOK ), 'Lock contention leaves fallback recovery scheduled' );
    check_lifecycle( IndexQueue::STATUS_PENDING === $queue->list_rows()[0]['status'], 'A busy step preserves the pending row' );
}

if ( in_array( $case, [ 'deferred-recovery', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture(); $id = lifecycle_row( $queue, 321 );
    $queue->defer_until( $id, time() + 7200 );
    $indexer->drain_tick();
    check_lifecycle( [] === $GLOBALS['wp_test_remote_posts'], 'The push phase respects a future retry backoff' );
}

if ( in_array( $case, [ 'stale-claim-recovery', 'all' ], true ) ) {
    [ $queue, $indexer ] = lifecycle_fixture(); $id = lifecycle_row( $queue, 321 ); $queue->claim_rows( [ $id ] );
    $indexer->drain_tick();
    check_lifecycle( [] === $GLOBALS['wp_test_remote_posts'], 'The next phase does not steal a fresh claim' );
    update_post_meta( $id, '_twp_claimed_at', time() - IndexQueue::STALE_CLAIM_SECONDS - 1 );
    $indexer->drain_tick();
    check_lifecycle( 1 === count( array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $r ): bool => '/v1/translation/jobs' === $r['path'] ) ), 'An abandoned claim can be recovered by the next phase' );
}

echo "BackgroundRefillLifecycleTest: all assertions passed\n";
