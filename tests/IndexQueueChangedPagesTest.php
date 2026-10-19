<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function seed_page( int $modified_offset ): int {
	return (int) wp_insert_post(
		[
			'post_type'         => 'page',
			'post_title'        => 'Page',
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + $modified_offset ),
		]
	);
}

function read_page( IndexQueue $queue, int $page_id ): void {
	$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => $page_id, 'url' => 'https://example.com/page-' . $page_id, 'priority' => 1 ] ] );
	foreach ( $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 50 ) as $row ) {
		$queue->store_payload( (int) ( is_array( $row ) ? $row['id'] : $row ), (string) wp_json_encode( [ [ 't' => 'x' ] ] ) );
	}
}

// --- A read site whose pages are unchanged reports no changed pages ---
wp_test_reset( [] );
$queue = new IndexQueue();
read_page( $queue, seed_page( -86400 ) );
read_page( $queue, seed_page( -3600 ) );
assert_true( 0 === $queue->changed_since_read_count(), 'Pages read after their last change count as unchanged, got ' . $queue->changed_since_read_count() );

// --- A page saved after it was read counts as changed ---
$changed_page = seed_page( 86400 );
read_page( $queue, $changed_page );
assert_true( 1 === $queue->changed_since_read_count(), 'A page modified after its read must count as changed, got ' . $queue->changed_since_read_count() );

// --- A second changed page raises the count; the indexing status exposes it ---
read_page( $queue, seed_page( 7200 ) );
assert_true( 2 === $queue->changed_since_read_count(), 'Every page changed since its read must be counted, got ' . $queue->changed_since_read_count() );

$status = ( new Indexer( '', TRANSLATEWP_VERSION, $queue ) )->status();
assert_true( array_key_exists( 'changedPages', $status ), 'The indexing status must expose a changedPages count' );
assert_true( 2 === $status['changedPages'], 'The indexing status changedPages must match the queue, got ' . var_export( $status['changedPages'] ?? null, true ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "IndexQueueChangedPagesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "IndexQueueChangedPagesTest: all assertions passed\n";
