<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\ReadinessBudget;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_repair( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function repair_options(): array {
	return [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ],
		'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'default' => true, 'active' => true ], 'it' => [ 'locale' => 'it', 'slug' => 'it', 'default' => false, 'active' => true ] ] ] ];
}
function repair_row( bool $repair ): array {
	wp_test_reset( repair_options() );
	$queue = new IndexQueue();
	$id = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 321, 'url' => 'https://example.com/shop/',
		'page_title' => '', 'language' => 'it', 'priority' => 50,
		'payload' => wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫Shop', 'cache' => [ 'siteId' => '00000000-0000-4000-8000-000000000001', 'hash' => str_repeat( 'a', 64 ) ] ] ] ) ] );
	if ( $repair ) { $queue->track_refill( (int) $id ); }
	return [ new Indexer( '', '', $queue ), $queue, (int) $id ];
}
function creates(): array {
	return array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $row ): bool => '/v1/translation/jobs' === $row['path'] ) );
}
function batches(): array {
	return array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $row ): bool => false !== strpos( $row['path'], '/batches' ) ) );
}

wp_test_reset( repair_options() );
update_option( 'page_on_front', 1 );
update_option( 'show_on_front', 'page' );
$objects = [];
for ( $i = 1; $i <= 60; ++$i ) {
	$objects[ $i ] = 'page';
	$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => $i, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page ' . $i, 'post_content' => 'Shop' ];
}
$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => 999, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Other', 'post_content' => 'Other' ];
$GLOBALS['wp_test_posts']['page'] = array_keys( $objects );
wp_test_set_filter( 'translatewp/indexer_objects', static fn() => array_reverse( $objects, true ) );
$GLOBALS['wp_test_front_page_html'] = '<!doctype html><html><body><main><p>Shop</p></main></body></html>';
$queue = new IndexQueue();
$unrelated = $queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => 999, 'url' => 'https://example.com/other/', 'page_title' => 'Other', 'language' => IndexQueue::READ_LANGUAGE, 'priority' => 100 ] ] );
$indexer = new Indexer( '', '', $queue );
$indexer->ensure_spine();
check_repair( true === get_option( Indexer::SPINE_PENDING_OPTION ), 'The first connected request schedules the one-time repair without an admin screen' );
delete_option( Indexer::SPINE_PENDING_OPTION );
$indexer->ensure_spine();
check_repair( false === get_option( Indexer::SPINE_PENDING_OPTION, false ), 'The one-time repair is not restarted on every request' );
$count = $indexer->enqueue_read_rows( true );
check_repair( 50 === $count, 'Automatic repair queues at most fifty pages' );
$marked = 0;
foreach ( $GLOBALS['wp_test_inserted_posts'] as $post ) {
	if ( 'twp_index_item' === ( $post['post_type'] ?? '' ) && get_post_meta( (int) $post['ID'], '_twp_translate_on_read', true ) ) { ++$marked; }
}
check_repair( 50 === $marked, 'Only the capped repair pages are marked for automatic translation' );
$indexer->step_read( new ReadinessBudget() );
$read = $queue->read_rows();
check_repair( 1 === count( $read ), 'One reader unit reads one page' );
check_repair( 1 === $read[0]['object_id'], 'The front page stays first even when an addon enumerates pages in reverse order' );
check_repair( (int) get_option( Indexer::NEXT_READER_OPTION ) >= time() + 59, 'The next page cannot be read until a minute has passed' );
$indexer->step_read( new ReadinessBudget() );
check_repair( 1 === count( $queue->read_rows() ), 'A second reader unit in the same minute reads no extra page' );
$indexer->step_push( new ReadinessBudget() );
$created = creates();
check_repair( 1 === count( $created ) && 'background_refresh' === ( $created[0]['body']['purpose'] ?? '' ), 'Automatic main-page repair creates a dedicated background refresh job' );
foreach ( batches() as $batch ) { check_repair( ! isset( $batch['body']['purpose'] ), 'The job marker is never sent on a batch' ); }

[ $indexer, $queue, $id ] = repair_row( false );
$indexer->step_push( new ReadinessBudget() );
check_repair( ! isset( creates()[0]['body']['purpose'] ), 'A normal visitor miss keeps the normal model routing' );

[ $indexer, $queue, $id ] = repair_row( true );
wp_test_set_filter( 'translatewp_translation_job_request_body', static fn( array $body ): array => array_merge( $body, [ 'byok' => [ [ 'provider' => 'openai', 'modelId' => 'test', 'apiKey' => 'test-only' ] ] ] ) );
wp_test_set_filter( 'translatewp_translation_batch_request_body', static fn( array $body ): array => array_merge( $body, [ 'byok' => [ [ 'provider' => 'openai', 'modelId' => 'test', 'apiKey' => 'test-only' ] ] ] ) );
$indexer->step_push( new ReadinessBudget() );
check_repair( 'background_refresh' === ( creates()[0]['body']['purpose'] ?? '' ), 'Lexicon or settings refills carry the dedicated job marker' );
check_repair( isset( creates()[0]['body']['byok'] ) && isset( batches()[0]['body']['byok'] ), 'Lexicon or settings refills keep the site\'s own providers' );
check_repair( 1 === count( batches() ) && 1 === count( batches()[0]['body']['items'] ), 'An acknowledged repair job receives its translation batch' );


[ $indexer, $queue, $id ] = repair_row( true );
\TranslateWP\Components\QuotaHandoff::set_enabled( true );
\TranslateWP\Components\QuotaHandoff::activate( null );
wp_test_set_filter( 'translatewp_quota_handoff_providers', static fn() => [ [ 'provider' => 'openai', 'modelId' => 'test', 'apiKey' => 'test-only' ] ] );
$GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 429 ], 'body' => wp_json_encode( [ 'code' => 'limit_exceeded', 'error' => 'Usage limit reached' ] ) ] ];
$indexer->step_push( new ReadinessBudget() );
check_repair( 1 === count( creates() ) && isset( creates()[0]['body']['byok'] ), 'Repair uses the active own-provider handoff like any job' );

[ $indexer, $queue, $id ] = repair_row( true );
$GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 201 ], 'body' => wp_json_encode( [ 'jobId' => '00000000-0000-4000-8000-000000000099' ] ) ] ];
$indexer->step_push( new ReadinessBudget() );
$sent = batches();
check_repair( [] === $sent, 'An old API receives no background text or batch' );
$cancels = array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $row ): bool => false !== strpos( $row['path'], '/cancel' ) );
check_repair( 1 === count( $cancels ), 'An unacknowledged empty job is cancelled' );
check_repair( (int) get_option( Indexer::REPAIR_RETRY_OPTION ) >= time() + 7199, 'An unacknowledged repair waits hours before trying again' );
check_repair( '' === $indexer->status()['readerError'] && ! $indexer->status()['scheduledTasksLate'], 'An older API does not cause an alarming Dashboard message' );
$indexer->step_push( new ReadinessBudget() );
check_repair( 1 === count( creates() ), 'The next queue run does not retry an unacknowledged job before its backoff expires' );

$row = array_values( array_filter( $queue->list_rows(), static fn( array $row ): bool => $row['id'] === $id ) )[0];
check_repair( IndexQueue::STATUS_PENDING === $row['status'], 'Unacknowledged background routing leaves the row queued for retry' );


wp_test_reset( repair_options() );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'page_title' => 'Page', 'language' => 'it', 'priority' => 50 ] ] );
$indexer = new Indexer( '', '', $queue );
$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'http_request_failed', 'cURL error 60: certificate verification failed' );
$indexer->step_read( new ReadinessBudget() );
check_repair( 'page_tls_failed' === get_option( Indexer::READER_FAILURE_OPTION ), 'A reader certificate failure remains recorded after the request ends' );
check_repair( false !== strpos( \TranslateWP\Functionality\CronFallback::notice_message(), 'certificate' ), 'The Dashboard explains the persistent reader certificate failure' );


wp_test_reset( repair_options() );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/page/', 'page_title' => 'Page', 'language' => 'it', 'priority' => 50 ] ] );
$GLOBALS['wp_test_post_statuses'][2] = 'draft';
check_repair( [] === $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 ) && [] === $queue->list_rows(), 'A page unpublished after it was queued is retired before background reading' );

echo "MainPageRepairTest: all assertions passed\n";
