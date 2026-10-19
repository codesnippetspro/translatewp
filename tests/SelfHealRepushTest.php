<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.12' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function connected_options(): array {
	return [
		'site'      => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ],
		'languages' => [
			'list' => [
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
			],
		],
	];
}

function seed_front_page(): void {
	wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 1 => 'page' ] );
	$GLOBALS['wp_test_inserted_posts'] = [
		[ 'ID' => 1, 'post_type' => 'page', 'post_title' => 'Front page', 'post_excerpt' => '', 'post_content' => 'Shop the collection.', 'post_status' => 'publish' ],
	];
	$GLOBALS['wp_test_posts']['page'] = [ 1 ];
}

function italian_row( IndexQueue $queue ): array {
	foreach ( $queue->list_rows() as $row ) {
		if ( 'it' === $row['language'] ) {
			return $row;
		}
	}
	return [];
}

function row_meta( array $row, string $key ): string {
	return (string) get_post_meta( (int) $row['id'], $key, true );
}

/** Read and push the front page in Italian, leaving the row done and its job id remembered. */
function index_front_page( IndexQueue $queue, Indexer $indexer ): string {
	$indexer->enqueue_read_rows();
	$read = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
	$indexer->submit_extraction( (int) $read[0]['id'], [ [ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Shop the whole collection today.', 'contextKey' => 'main > p', 'contextKind' => 'text-flow' ] ], true );
	$indexer->start_translation( [ 'it' ] );
	$GLOBALS['wp_test_remote_posts'] = [];
	$indexer->drain_tick();
	update_post_meta( (int) italian_row( $queue )['id'], '_twp_updated_at', 100 );
	return row_meta( italian_row( $queue ), '_twp_job_id' );
}

$cold_lookup = static fn(): array => [];
$now         = static fn(): int => 10_000;

// ---------------------------------------------------------------------------
// A pushed page whose translation job is still running is not cold yet.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_front_page();
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$job_id  = index_front_page( $queue, $indexer );
assert_true( '' !== $job_id, 'A pushed row remembers the job that translates it' );

$GLOBALS['wp_test_job_states'][ $job_id ] = 'processing';
$heal   = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $cold_lookup, $now, false );
$result = $heal->reconcile( 0 );
assert_true( 0 === $result['requeued'], 'A page whose job is still processing must not be healed, got ' . wp_json_encode( $result ) );
assert_true( IndexQueue::STATUS_DONE === italian_row( $queue )['status'], 'The page stays done while its job runs' );

// ---------------------------------------------------------------------------
// Once the job has finished and the page is still cold, it is sent again from
// its site reading as an ordinary push, never back to extraction and never as a
// forced retranslation.
// ---------------------------------------------------------------------------
$GLOBALS['wp_test_job_states'][ $job_id ] = 'done';
$result = $heal->reconcile( 0 );
$row    = italian_row( $queue );
assert_true( 1 === $result['requeued'], 'A finished but cold page is healed once, got ' . wp_json_encode( $result ) );
assert_true( IndexQueue::STATUS_PENDING === $row['status'], 'The healed page is pending again' );
assert_true( IndexQueue::STAGE_PUSH === row_meta( $row, '_twp_stage' ), 'The heal re-sends the page instead of waiting for extraction, got ' . row_meta( $row, '_twp_stage' ) );
assert_true( '1' !== row_meta( $row, '_twp_retranslate' ), 'The heal is not a forced retranslation, so cached pieces cost nothing' );
$claimed = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
assert_true( 1 === count( $claimed ) && false !== strpos( (string) $claimed[0]['payload'], 'Shop the whole collection' ), 'The re-sent row carries the page text for the drain' );

// ---------------------------------------------------------------------------
// A page only a visitor opened is never healed: the visitor path covers it.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$queue->enqueue( [ [ 'object_type' => 'miss', 'object_id' => 0, 'url' => home_url( '/category/news/' ), 'page_title' => '', 'language' => 'it', 'priority' => 10 ] ] );
$miss = $queue->list_rows()[0];
update_post_meta( (int) $miss['id'], '_twp_status', IndexQueue::STATUS_DONE );
update_post_meta( (int) $miss['id'], '_twp_updated_at', 100 );
TranslationReadiness::record( (int) $miss['id'], 'it', [ [ 'cache' => [ 'hash' => str_repeat( 'c', 64 ) ], 'markerPayload' => '⟪item_0001⟫News from the bakery today' ] ], false );
$heal   = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $cold_lookup, $now, false );
$result = $heal->reconcile( 0 );
assert_true( 0 === $result['requeued'] && IndexQueue::STATUS_DONE === $queue->list_rows()[0]['status'], 'A visitor-only page must not be healed, got ' . wp_json_encode( $result ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "SelfHealRepushTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "SelfHealRepushTest passed\n";
