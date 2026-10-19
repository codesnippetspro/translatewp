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

$options = [
	'site'      => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ],
	'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ], 'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ] ] ],
];
$row = [
	'object_type' => 'miss',
	'object_id'   => 42,
	'url'         => 'https://example.com/?s=',
	'page_title'  => 'Search',
	'language'    => 'de',
	'priority'    => 10,
];
$bad_item  = [ 'groupId' => 'g-bad', 'markerPayload' => "⟪item_0001⟫\xB1\xFF broken", 'contextKind' => 'text-flow' ];
$good_item = [ 'groupId' => 'g-good', 'markerPayload' => '⟪item_0001⟫Search results', 'contextKind' => 'text-flow' ];

// --- A push row never stores an empty payload ---
wp_test_reset( $options );
$queue = new IndexQueue();
assert_true( false === $queue->enqueue_push( array_merge( $row, [ 'payload' => '' ] ) ), 'An empty payload must not be queued' );
assert_true( [] === $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 ), 'No row exists after a refused empty payload' );

// --- A miss whose only item cannot be encoded is refused, not stored empty ---
wp_test_reset( $options );
$queue = new IndexQueue();
assert_true( false === $queue->enqueue_miss_page( $row, [ $bad_item ] ), 'A miss with no encodable item must be refused' );
assert_true( [] === $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 ), 'No row exists for a miss with no encodable item' );

// --- A miss with one bad and one good item keeps the good one ---
wp_test_reset( $options );
$queue = new IndexQueue();
assert_true( false !== $queue->enqueue_miss_page( $row, [ $bad_item, $good_item ] ), 'A miss with an encodable item is queued' );
$claimed = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$items   = is_array( $claimed ) && $claimed ? json_decode( (string) $claimed[0]['payload'], true ) : null;
assert_true( is_array( $items ) && 1 === count( $items ) && 'g-good' === ( $items[0]['groupId'] ?? '' ), 'The stored payload holds the encodable item only, got ' . wp_json_encode( $items ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "MissPayloadTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "MissPayloadTest: all assertions passed\n";
