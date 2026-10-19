<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.19' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function connected_site(): array {
	return [ 'site' => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ] ];
}

/** A done page row of `$count` segment groups, its job remembered and finished. */
function sweep_done_row( IndexQueue $queue, int $object_id, string $language, int $count, string $job_id ): array {
	$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => $object_id, 'url' => home_url( '/page-' . $object_id . '/' ), 'page_title' => 'Page ' . $object_id, 'language' => $language, 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
	$rows = $queue->list_rows();
	$row  = end( $rows );
	$id   = (int) $row['id'];

	$items = [];
	for ( $index = 0; $index < $count; $index++ ) {
		$items[] = [
			'groupId'       => 'g' . $index,
			'cache'         => [ 'hash' => str_pad( dechex( crc32( $object_id . $language . $index ) ), 64, '0' ) ],
			'markerPayload' => '⟪item_0001⟫Text ' . $index,
		];
	}
	$items[] = [
		'groupId'       => 'seo:seo:title',
		'cache'         => [ 'hash' => str_pad( dechex( crc32( $object_id . $language . 'title' ) ), 64, '0' ) ],
		'markerPayload' => '⟪item_0001⟫Page ' . $object_id,
	];
	TranslationReadiness::record( $id, $language, $items );

	update_post_meta( $id, '_twp_status', IndexQueue::STATUS_DONE );
	update_post_meta( $id, '_twp_updated_at', 100 );
	if ( '' !== $job_id ) {
		$queue->remember_job( $id, $job_id );
		$GLOBALS['wp_test_job_states'][ $job_id ] = 'done';
	}

	return array_values( array_filter( $queue->list_rows(), static fn ( array $item ): bool => (int) $item['id'] === $id ) )[0];
}

function sweep_row_status( IndexQueue $queue, int $id ): string {
	foreach ( $queue->list_rows() as $row ) {
		if ( (int) $row['id'] === $id ) {
			return (string) $row['status'];
		}
	}
	return '';
}

// ---------------------------------------------------------------------------
// Cause 3: a completed page whose readiness sweep still has batches to cover is
// not declared cold. Only once every one of its hashes has been checked and is
// still missing does the row take a cold repair, keeping the retry cap.
// ---------------------------------------------------------------------------
wp_test_reset( connected_site() );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$clock   = static fn (): int => 10_000;

// 150 groups span two look-up batches (BATCH_SIZE is 100), so the default
// one-batch-per-pass sweep needs two passes to cover the whole page.
$row    = sweep_done_row( $queue, 1, 'fr', 150, 'job-large' );
$id     = (int) $row['id'];
$cold   = static fn (): array => [];
$heal   = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $cold, $clock, false );

$first = $heal->reconcile( 0 );
assert_true( 0 === $first['requeued'], 'A page whose sweep has only covered one of its batches is not charged a cold retry, got ' . wp_json_encode( $first ) );
assert_true( IndexQueue::STATUS_DONE === sweep_row_status( $queue, $id ), 'The page stays done while its sweep is incomplete' );
assert_true( 0 === (int) get_post_meta( $id, '_twp_cold_retries', true ), 'No cold retry is charged mid-sweep' );

$second = $heal->reconcile( (int) $first['cursor'] );
assert_true( 1 === $second['requeued'], 'Once every batch is checked and still missing, the page is healed as cold, got ' . wp_json_encode( $second ) );
assert_true( IndexQueue::STATUS_PENDING === sweep_row_status( $queue, $id ), 'A fully swept, still-missing page is repaired' );

// ---------------------------------------------------------------------------
// A small page whose single batch covers all its hashes is charged on the first
// pass exactly as before: nothing about the sweep gate delays a genuine miss.
// ---------------------------------------------------------------------------
wp_test_reset( connected_site() );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = sweep_done_row( $queue, 2, 'de', 3, 'job-small' );
$id      = (int) $row['id'];
$heal    = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn (): array => [], $clock, false );
$result  = $heal->reconcile( 0 );
assert_true( 1 === $result['requeued'], 'A single-batch page whose hashes are all missing is charged at once, got ' . wp_json_encode( $result ) );
assert_true( IndexQueue::STATUS_PENDING === sweep_row_status( $queue, $id ), 'The small cold page is repaired' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ReadinessSweepColdTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "ReadinessSweepColdTest passed\n";
