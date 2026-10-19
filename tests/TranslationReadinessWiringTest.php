<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.10' );
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
		'site'      => [
			'id'    => '00000000-0000-4000-8000-0000000000aa',
			'token' => 'secret-site-token',
		],
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
		[
			'ID'           => 1,
			'post_type'    => 'page',
			'post_title'   => 'Front page',
			'post_excerpt' => '',
			'post_content' => 'Shop the collection.',
			'post_status'  => 'publish',
		],
	];
	$GLOBALS['wp_test_posts']['page'] = [ 1 ];
}

/** Translate every pushed segment by echoing its source text into the cache. */
function cache_every_pushed_segment(): array {
	$cached = [];
	foreach ( $GLOBALS['wp_test_remote_posts'] ?? [] as $post ) {
		if ( ! preg_match( '#/batches$#', (string) ( $post['path'] ?? '' ) ) ) {
			continue;
		}
		foreach ( (array) ( $post['body']['items'] ?? [] ) as $item ) {
			$cached[ (string) ( $item['cache']['hash'] ?? '' ) ] = str_replace( '⟪item_0001⟫', '⟪item_0001⟫IT ', (string) $item['markerPayload'] );
		}
	}

	return $cached;
}

function italian_row( IndexQueue $queue ): array {
	foreach ( $queue->list_rows() as $row ) {
		if ( 'it' === $row['language'] ) {
			return $row;
		}
	}

	return [];
}

function index_front_page( IndexQueue $queue, Indexer $indexer ): void {
	$indexer->enqueue_read_rows();
	$read = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
	$indexer->submit_extraction(
		(int) $read[0]['id'],
		[ [ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Shop the collection.', 'contextKey' => 'main > p', 'contextKind' => 'text-flow' ] ],
		true
	);
	$indexer->start_translation( [ 'it' ] );
	$GLOBALS['wp_test_remote_posts'] = [];
	$indexer->drain_tick();
}

// ---------------------------------------------------------------------------
// A pushed page records what it sent, so it reads as ready once the service
// cache holds every segment, the title included.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_front_page();
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
index_front_page( $queue, $indexer );

$row = italian_row( $queue );
assert_true( IndexQueue::STATUS_DONE === ( $row['status'] ?? '' ), 'Precondition: the Italian row was pushed, got ' . wp_json_encode( $row ) );
assert_true( ! TranslationReadiness::is_ready( $row ), 'A pushed page is not ready before its segments are confirmed in the cache' );
$status = $indexer->status()['languages']['it'] ?? [];
assert_true( 0 === ( $status['ready'] ?? null ) && false === ( $status['readyComplete'] ?? null ), 'The language status must report no ready pages yet, got ' . wp_json_encode( $status ) );

$cached = cache_every_pushed_segment();
assert_true( 2 === count( $cached ), 'Precondition: the body and the title were pushed, got ' . count( $cached ) );
( new \TranslateWP\Functionality\ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn() => $cached, static fn (): int => 1000, false ) )->reconcile( 0 );
assert_true( TranslationReadiness::is_ready( italian_row( $queue ) ), 'A pushed page whose segments are all cached must read as ready' );
$status = $indexer->status()['languages']['it'] ?? [];
assert_true( 1 === ( $status['ready'] ?? null ) && true === ( $status['readyComplete'] ?? null ), 'The language status must count the translated page and report the language complete, got ' . wp_json_encode( $status ) );

// ---------------------------------------------------------------------------
// A page only a visitor opened (a miss row with no index or read row) still
// translates, but never holds its language back from complete.
// ---------------------------------------------------------------------------
$queue->enqueue( [ [ 'object_type' => 'miss', 'object_id' => 0, 'url' => home_url( '/category/news/' ), 'page_title' => '', 'language' => 'it', 'priority' => 10 ] ] );
foreach ( $queue->list_rows() as $visitor_row ) {
	if ( 'miss' === $visitor_row['object_type'] ) {
		update_post_meta( (int) $visitor_row['id'], '_twp_status', IndexQueue::STATUS_DONE );
		TranslationReadiness::record( (int) $visitor_row['id'], 'it', [ [ 'cache' => [ 'hash' => str_repeat( 'c', 64 ) ], 'markerPayload' => '⟪item_0001⟫News' ] ], false );
	}
}
$status = $indexer->status()['languages']['it'] ?? [];
assert_true( 1 === ( $status['ready'] ?? null ) && 1 === ( $status['readyTotal'] ?? null ), 'Only indexed pages count toward language progress, got ' . wp_json_encode( $status ) );
assert_true( true === ( $status['readyComplete'] ?? null ), 'A visitor-only page must not hold the language back from complete, got ' . wp_json_encode( $status ) );
assert_true( 2 === ( $status['total'] ?? null ), 'The visitor-only row is still a queue row' );

// ---------------------------------------------------------------------------
// A failed page stays in the total and is reported; it does not hold the
// language open (nothing is in flight) and it is not hidden from the share.
// ---------------------------------------------------------------------------
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 2, 'url' => home_url( '/about/' ), 'page_title' => 'About', 'language' => 'it', 'priority' => 100 ] ] );
foreach ( $queue->list_rows() as $dead_row ) {
	if ( 'it' === $dead_row['language'] && 2 === (int) $dead_row['object_id'] ) {
		update_post_meta( (int) $dead_row['id'], '_twp_status', IndexQueue::STATUS_DEAD );
	}
}
$status = $indexer->status()['languages']['it'] ?? [];
assert_true( 1 === ( $status['ready'] ?? null ), 'The ready page is still counted, got ' . wp_json_encode( $status ) );
assert_true( 2 === ( $status['readyTotal'] ?? null ), 'A failed page stays in the total, got ' . wp_json_encode( $status ) );
assert_true( true === ( $status['readyComplete'] ?? null ), 'Nothing in flight reads complete even with a failed page, got ' . wp_json_encode( $status ) );
assert_true( 3 === ( $status['total'] ?? null ), 'The dead row is still a queue row' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationReadinessWiringTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "TranslationReadinessWiringTest passed\n";
