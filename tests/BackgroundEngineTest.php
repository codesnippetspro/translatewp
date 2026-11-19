<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\BackgroundSwitch;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Functionality\BackgroundWork;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;
use TranslateWP\Functionality\Endpoints\Background;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_engine( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function engine_fixture(): IndexQueue {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'default' => true, 'active' => true ], 'it' => [ 'locale' => 'it', 'active' => true ] ] ] ] );
	update_option( LexiconChanges::SEEDED_OPTION, true, false );
	update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
	update_option( BackgroundEngine::PHASE_OPTION, 1, false );
	return new IndexQueue();
}
$queue = engine_fixture();
check_engine( [ 'state' => 'done', 'pending' => [ 'read' => 0, 'push' => 0, 'refill' => 0, 'visitor' => 0 ], 'v' => 1 ] === BackgroundEngine::step(), 'An empty step answers the exact done contract' );
check_engine( 0 === get_option( BackgroundEngine::PHASE_OPTION ), 'A step advances one phase only' );
BackgroundEngine::service_step( [ 'pause' => true ] );
check_engine( BackgroundSwitch::off(), 'Signed pause persists' );
$phase = get_option( BackgroundEngine::PHASE_OPTION );
check_engine( 'paused' === BackgroundEngine::step()['state'], 'An admin step cannot work while paused' );
BackgroundEngine::cron();
check_engine( $phase === get_option( BackgroundEngine::PHASE_OPTION ), 'A paused cron cannot advance a phase' );
BackgroundEngine::service_step( [] );
check_engine( ! BackgroundSwitch::off(), 'An absent pause bit on a valid service call clears pause' );
$phase = get_option( BackgroundEngine::PHASE_OPTION );
BackgroundEngine::cron();
check_engine( $phase === get_option( BackgroundEngine::PHASE_OPTION ), 'Recent service activity suppresses cron work' );
update_option( BackgroundEngine::LAST_SERVICE_OPTION, time() - 301, false );
BackgroundEngine::cron();
check_engine( $phase !== get_option( BackgroundEngine::PHASE_OPTION ), 'Service silence enables the cron fallback' );
check_engine( 1 === count( array_filter( array_keys( $GLOBALS['wp_test_scheduled'] ), static fn ( string $hook ): bool => BackgroundEngine::CRON_HOOK === $hook ) ), 'Fallback scheduling does not accumulate duplicate cron events' );

$queue = engine_fixture();
$id = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 123, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 50, 'payload' => wp_json_encode( [ [ 'groupId' => 'shop', 'markerPayload' => '⟪item_0001⟫Shop' ] ] ) ] );
update_option( BackgroundEngine::PHASE_OPTION, 0, false );
$result = BackgroundEngine::step();
check_engine( 0 === $result['pending']['visitor'], 'The visitor phase pushes due misses immediately' );
check_engine( IndexQueue::STATUS_DONE === $queue->list_rows()[0]['status'], 'Visitor work takes priority over the maintenance cursor' );
check_engine( null === QueueExecution::budget(), 'The shared deadline is cleared after each step' );

$queue = engine_fixture();
$deadline_seen = false;
QueueExecution::run( static function () use ( &$deadline_seen ): void {
	$args = apply_filters( 'http_request_args', [ 'timeout' => 90 ] );
	check_engine( $args['timeout'] > 0 && $args['timeout'] <= 8, 'All HTTP calls use the shared eight-second deadline' );
	$property = new ReflectionProperty( QueueExecution::budget(), 'deadline' );
	$property->setAccessible( true );
	$property->setValue( QueueExecution::budget(), microtime( true ) - 1 );
	check_engine( is_wp_error( apply_filters( 'pre_http_request', false ) ), 'Expired steps cannot start another HTTP call' );
	check_engine( ! QueueExecution::run( static function (): void { check_engine( false, 'Expired nested work must not run' ); } ), 'Nested work observes the same deadline' );
	$deadline_seen = true;
} );
check_engine( $deadline_seen, 'The budget probe ran' );

$queue = engine_fixture();
new BackgroundWork(); new CronFallback(); new Indexer(); new LexiconChanges();
$GLOBALS['wp_test_get_posts_calls'] = [];
$GLOBALS['wp_test_remote_request_calls'] = [];
do_action( 'template_redirect' ); do_action( 'shutdown' );
check_engine( [] === $GLOBALS['wp_test_get_posts_calls'] && [] === $GLOBALS['wp_test_remote_request_calls'], 'Visitor hooks do no queue queries or remote work' );
check_engine( ! class_exists( 'TranslateWP\\Components\\LoopbackKick' ), 'The self-calling worker is removed' );
check_engine( ! method_exists( QueueExecution::class, 'run_reduced' ), 'After-response queue execution is removed' );
$endpoints = ( new Background() )->define_endpoints();
check_engine( count( $endpoints ) === 2, 'Only signed and administrator step routes are registered' );

$queue = engine_fixture();
$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'page_title' => '', 'priority' => 10 ] ] );
wp_test_set_filter( 'translatewp_engine_service_reads', static fn (): bool => true );
$indexer = new Indexer( '', '', $queue, null, false );
check_engine( [ [ 'url' => 'https://example.com/page/' ] ] === $indexer->wanted_pages(), 'Service reads request only already-queued known public URLs' );
update_option( BackgroundEngine::PHASE_OPTION, 0, false );
BackgroundEngine::step( [
    [ 'url' => 'https://other.example/private/', 'status' => 200, 'html' => '<p>Never queue me</p>' ],
    [ 'url' => 'https://example.com/page/', 'status' => 200, 'html' => '<html><body><p>Welcome to our shop</p><script>Do not translate this code</script></body></html>' ],
] );
$read = $queue->read_rows();
check_engine( 1 === count( $read ) && 'https://example.com/page/' === $read[0]['url'], 'Supplied pages satisfy existing reads without creating arbitrary work' );
check_engine( [] === ( $GLOBALS['wp_test_remote_get_urls'] ?? [] ), 'A supplied known page needs no local reader request' );
$payloads = wp_json_encode( $read[0]['items'] );
check_engine( str_contains( $payloads, 'Welcome to our shop' ) && ! str_contains( $payloads, 'translate this code' ), 'Supplied HTML uses the same extraction and script exclusion as local reads' );

$queue = engine_fixture();
$payload = static fn ( int $id ): string => wp_json_encode( [ [ 'groupId' => 'g' . $id, 'markerPayload' => '⟪item_0001⟫Text for page ' . $id ] ] );
$background = $queue->enqueue_push( [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'language' => 'it', 'priority' => 0, 'payload' => $payload( 2 ) ] );
$queue->track_refill( $background );
for ( $i = 1; $i <= 3; $i++ ) {
    $visitor = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 100 + $i, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10, 'payload' => $payload( 100 + $i ) ] );
    check_engine( 1 === BackgroundEngine::pending()['visitor'], 'Visitor count does not include pending background work' );
    BackgroundEngine::step();
    check_engine( IndexQueue::STATUS_DONE === get_post_meta( $visitor, '_twp_status', true ), 'Due visitor pushes win over background priority pins' );
    check_engine( IndexQueue::STATUS_PENDING === get_post_meta( $background, '_twp_status', true ), 'A visitor phase does not push background rows as a second phase' );
}
$waiting = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 104, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10, 'payload' => $payload( 104 ) ] );
$result = BackgroundEngine::step();
check_engine( IndexQueue::STATUS_DONE === get_post_meta( $background, '_twp_status', true ) && IndexQueue::STATUS_PENDING === get_post_meta( $waiting, '_twp_status', true ), 'After three visitor phases one background phase prevents starvation' );
check_engine( 1 === $result['pending']['visitor'], 'The response keeps the fast service pace while visitors wait' );

engine_fixture();
$GLOBALS['wp_test_remote_request_calls'] = [];
BackgroundEngine::visitor_wake(); BackgroundEngine::visitor_wake(); BackgroundEngine::wake();
$requests = $GLOBALS['wp_test_remote_request_calls'];
check_engine( 1 === count( $requests ) && false === $requests[0]['args']['blocking'] && 0.01 === $requests[0]['args']['timeout'], 'An accepted visitor miss wakes the service without waiting, at most once per twenty seconds' );
check_engine( '{}' === $requests[0]['args']['body'] && '1' === $requests[0]['args']['headers']['X-TranslateWP-Engine'], 'The visitor wake uses the authenticated engine marker and empty-object contract' );
update_option( BackgroundEngine::LAST_WAKE_OPTION, time() - 21, false );
update_option( BackgroundEngine::LAST_STEP_OPTION, time(), false );
BackgroundEngine::visitor_wake();
check_engine( 2 === count( $GLOBALS['wp_test_remote_request_calls'] ), 'New visitor work may wake the service immediately after a done step' );

$queue = engine_fixture();
$GLOBALS['wp_test_after_option_write'][ BackgroundEngine::STATE_OPTION ] = static function () use ( $queue ): void {
    $queue->enqueue_miss_page( [ 'object_type' => 'miss', 'object_id' => 902, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10 ], [ [ 'groupId' => 'arriving-late', 'markerPayload' => '⟪item_0001⟫Another arrival' ] ] );
};
$result = BackgroundEngine::step();
check_engine( 'more' === $result['state'] && $result['pending']['visitor'] > 0, 'A visitor arrival during a step is included in its own answer' );


$queue = engine_fixture();
$miss_row = [ 'object_type' => 'miss', 'object_id' => 903, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10 ];
$first = $queue->enqueue_miss_page( $miss_row, [ [ 'groupId' => 'before-push', 'markerPayload' => '⟪item_0001⟫Before push' ] ] );
$late = null;
wp_test_set_filter( 'translatewp_site_request_headers', static function ( array $headers ) use ( $queue, $miss_row, $first, &$late ): array {
    if ( null === $late ) {
        check_engine( IndexQueue::STATUS_CLAIMED === get_post_meta( $first, '_twp_status', true ), 'The late group arrives after the push has captured its claimed payload' );
        $late = $queue->enqueue_miss_page( $miss_row, [ [ 'groupId' => 'during-push', 'markerPayload' => '⟪item_0001⟫During push' ] ] );
    }
    return $headers;
} );
$result = BackgroundEngine::step();
check_engine( 'more' === $result['state'] && $result['pending']['visitor'] > 0, 'The active push reports visitor work that arrived during its own request' );
check_engine( $late !== $first && IndexQueue::STATUS_PENDING === get_post_meta( $late, '_twp_status', true ), 'Completing the captured push leaves the late group pending' );
BackgroundEngine::step();
check_engine( IndexQueue::STATUS_DONE === get_post_meta( $late, '_twp_status', true ), 'The next step actually pushes the late group instead of merely counting a hint' );

$queue = engine_fixture();
delete_option( LexiconChanges::SEEDED_OPTION ); delete_option( Indexer::SPINE_CHECKED_OPTION );
update_option( 'page_on_front', 1 ); update_option( 'show_on_front', 'page' );
$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => 'Welcome to our shop' ];
$GLOBALS['wp_test_posts']['page'] = [ 1 ];
wp_test_set_filter( 'translatewp/indexer_objects', static fn (): array => [ 1 => 'page' ] );
$GLOBALS['wp_test_front_page_html'] = '<html><body><p>Welcome to our shop</p></body></html>';
update_option( BackgroundEngine::PHASE_OPTION, 1, false );
for ( $i = 0; $i < 4; $i++ ) { BackgroundEngine::service_step( [] ); }
$creates = array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $post ): bool => '/v1/translation/jobs' === $post['path'] );
check_engine( [] !== $creates, 'A connected site bootstraps reading and pushing from service steps without an Indexing screen or cron callback' );
foreach ( $creates as $job ) { check_engine( 'background_refresh' === ( $job['body']['purpose'] ?? '' ), 'Automatic bootstrap uses the acknowledged background purpose' ); }
fwrite( STDOUT, "BackgroundEngineTest: all assertions passed\n" );
