<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

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

// --- Re-read changed only queues the pages saved after they were read ---
wp_test_reset( [] );
$queue = new IndexQueue();
read_page( $queue, seed_page( -86400 ) );
read_page( $queue, seed_page( 86400 ) );
read_page( $queue, seed_page( 7200 ) );

assert_true( 2 === $queue->changed_since_read_count(), 'Two pages changed since read, got ' . $queue->changed_since_read_count() );

$requeued = $queue->reread_changed();
assert_true( 2 === $requeued, 'Re-read changed must queue only the changed pages, got ' . $requeued );

// The re-queued pages are waiting to be read again, so they no longer count as
// finished-but-changed, and nothing was read again for the unchanged page.
assert_true( 0 === $queue->changed_since_read_count(), 'After re-reading, no page is left finished-but-changed, got ' . $queue->changed_since_read_count() );
assert_true( 2 === $queue->read_counts()['pending'], 'The two changed pages are queued to be read again, got ' . $queue->read_counts()['pending'] );
assert_true( 1 === $queue->read_counts()['pages'], 'The unchanged page stays read, got ' . $queue->read_counts()['pages'] );

// A second call with nothing newly changed queues nothing.
assert_true( 0 === $queue->reread_changed(), 'Re-read changed queues nothing when nothing changed since the last read' );

if ( $failures > 0 ) {
	fwrite( STDERR, "IndexRereadChangedTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "IndexRereadChangedTest: all assertions passed\n";
