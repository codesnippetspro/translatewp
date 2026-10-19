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

function connected_options(): array {
	return [
		'site'      => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ],
		'languages' => [
			'list' => [
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'es' => [ 'locale' => 'es', 'slug' => 'es', 'active' => true, 'default' => false ],
			],
		],
	];
}

// --- A dead push row keeps its payload and goes back to the push stage ---
wp_test_reset( connected_options() );
$queue   = new IndexQueue();
$payload = wp_json_encode( [ [ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Hello' ] ] );
$queue->enqueue_push(
	[
		'object_type' => 'page',
		'object_id'   => 7,
		'url'         => 'https://example.com/about/',
		'page_title'  => 'About',
		'language'    => 'es',
		'priority'    => 100,
		'payload'     => $payload,
	]
);
for ( $attempt = 0; $attempt < IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$claimed = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
	assert_true( 1 === count( $claimed ), 'The push row must be claimable on attempt ' . ( $attempt + 1 ) );
	$queue->fail( (int) $claimed[0]['id'], 'rate_limited' );
}
assert_true( 1 === count( $queue->failed_items( 'es' ) ), 'Three failures must leave the row dead' );

$revived = $queue->retry_failed( 'es' );
assert_true( 1 === $revived, 'One dead row must be revived, got ' . $revived );
assert_true( [] === $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 ), 'A revived row with a payload must not wait for extraction' );
$pushable = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
assert_true( 1 === count( $pushable ), 'A revived row with a payload must be claimable for push again' );
assert_true( $payload === ( $pushable[0]['payload'] ?? '' ), 'The revived row must carry the payload it already held, got ' . wp_json_encode( $pushable[0]['payload'] ?? null ) );
assert_true( '' === (string) ( $pushable[0]['fail_reason'] ?? '' ) || ! isset( $pushable[0]['fail_reason'] ), 'The failure reason must be cleared' );

// --- A dead row that never got a payload goes back to the extract stage ---
wp_test_reset( connected_options() );
$queue = new IndexQueue();
$queue->enqueue(
	[
		[
			'object_type' => 'page',
			'object_id'   => 8,
			'url'         => 'https://example.com/team/',
			'page_title'  => 'Team',
			'language'    => 'es',
			'priority'    => 100,
		],
	]
);
for ( $attempt = 0; $attempt < IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
	assert_true( 1 === count( $claimed ), 'The extract row must be claimable on attempt ' . ( $attempt + 1 ) );
	$queue->fail( (int) $claimed[0]['id'], 'extract_failed' );
}
assert_true( 1 === $queue->retry_failed( 'es' ), 'The dead extract row must be revived' );
assert_true( [] === $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 ), 'A revived row without a payload must not be pushed' );
assert_true( 1 === count( $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 ) ), 'A revived row without a payload waits for extraction' );

if ( $failures > 0 ) {
	fwrite( STDERR, "RetryFailedTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "RetryFailedTest: all assertions passed\n";
