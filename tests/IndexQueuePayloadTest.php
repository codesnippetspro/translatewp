<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;

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

wp_test_reset( [] );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 7, 'url' => 'https://example.com/probe', 'page_title' => 'Probe', 'language' => 'de', 'priority' => 100 ] ] );
$claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
$row_id  = (int) $claimed[0]['id'];

// --- a payload whose text looks like markup survives the save untouched ---
$payload = (string) wp_json_encode( [ [ 'groupId' => 'grp_1', 'markerPayload' => '⟪item_0001⟫Also this: <img src=x onerror="alert(1)"> and {{ site.name }} and "quoted" text' ] ] );
assert_true( $queue->store_payload( $row_id, $payload ), 'The payload is stored' );
$stored = (string) get_post_field( 'post_content', $row_id );
assert_true( false === strpos( $stored, '<' ) && false === strpos( $stored, '"' ), 'The stored form carries no markup or quotes for a content filter to rewrite, got ' . substr( $stored, 0, 40 ) );
$push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
assert_true( $payload === ( $push[0]['payload'] ?? '' ), 'The push stage reads back the exact payload' );
assert_true( is_array( json_decode( (string) $push[0]['payload'], true ) ), 'The read payload decodes as JSON' );

// --- buffered batches go through the same encoding ---
wp_test_reset( [] );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 8, 'url' => 'https://example.com/long', 'page_title' => 'Long', 'language' => 'de', 'priority' => 100 ] ] );
$claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
$row_id  = (int) $claimed[0]['id'];
assert_true( $queue->append_extraction( $row_id, [ [ 'groupId' => 'a', 'markerPayload' => '⟪item_0001⟫<b>one</b>' ] ], false ), 'The first batch buffers' );
assert_true( $queue->append_extraction( $row_id, [ [ 'groupId' => 'b', 'markerPayload' => '⟪item_0001⟫two "2"' ] ], true ), 'The final batch stores' );
$push  = $queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
$items = json_decode( (string) ( $push[0]['payload'] ?? '' ), true );
assert_true( is_array( $items ) && 2 === count( $items ) && '⟪item_0001⟫<b>one</b>' === $items[0]['markerPayload'], 'Both batches read back intact, got ' . wp_json_encode( $items ) );

// --- a row written before the encoding still reads as plain JSON ---
wp_test_reset( [] );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 9, 'url' => 'https://example.com/old', 'page_title' => 'Old', 'language' => 'de', 'priority' => 100 ] ] );
$claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
$row_id  = (int) $claimed[0]['id'];
$legacy  = (string) wp_json_encode( [ [ 'groupId' => 'grp_9', 'markerPayload' => '⟪item_0001⟫plain' ] ] );
$queue->store_payload( $row_id, $legacy );
wp_update_post( [ 'ID' => $row_id, 'post_content' => wp_slash( $legacy ) ] );
$push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
assert_true( $legacy === ( $push[0]['payload'] ?? '' ), 'A plain JSON row reads back unchanged' );

if ( $failures > 0 ) {
	fwrite( STDERR, "IndexQueuePayloadTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "IndexQueuePayloadTest: all assertions passed\n";
