<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxyDedupe;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.2' );
}
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
}

$failures = 0;

function assert_backfill( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function backfill_fixture( array $visitor_owns ): array {
	$site_id = '00000000-0000-4000-8000-000000000001';
	wp_test_reset(
		[
			'site'      => [ 'id' => $site_id, 'token' => 'fixture-token' ],
			'languages' => [
				'list' => [
					'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
					'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				],
			],
		]
	);

	$post_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'About us', 'post_status' => 'publish' ] );
	$url     = 'https://example.com/about/';
	$GLOBALS['wp_test_url_to_postid'][ $url ] = $post_id;
	$items = [
		[ 'groupId' => 'body-1', 'markerPayload' => '⟪item_0001⟫Welcome', 'contextKind' => 'text-flow' ],
		[ 'groupId' => 'body-2', 'markerPayload' => '⟪item_0001⟫Contact us', 'contextKind' => 'text-flow' ],
	];
	$settings = TranslationProxySettings::ai_settings( 'de' );
	$hashes   = [];
	foreach ( [ $items[0], $items[1], [ 'markerPayload' => '⟪item_0001⟫About us', 'contextKind' => 'attribute' ] ] as $item ) {
		$hashes[] = TranslationFingerprint::compute_hash( $item['markerPayload'], 'en', 'de', $item['contextKind'], $settings );
	}
	foreach ( $visitor_owns as $index ) {
		assert_backfill( true === TranslationProxyDedupe::claim_segment( $site_id, 'de', $hashes[ $index ] ), 'Visitor must claim its hash first' );
	}

	new TranslationStoreSync();
	$queue = new IndexQueue();
	$queue->enqueue_push(
		[
			'object_type' => 'page',
			'object_id'   => $post_id,
			'url'         => $url,
			'page_title'  => 'About us',
			'language'    => 'de',
			'priority'    => 10,
			'payload'     => wp_json_encode( $items ),
		]
	);

	return [ $site_id, $post_id, $url, $hashes, new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue ) ];
}

function backfill_arrival( string $url, int $post_id, array $hashes ): void {
	foreach ( [ 'Willkommen', 'Kontaktieren Sie uns', 'Über uns' ] as $index => $translated ) {
		$GLOBALS['wp_test_cache_lookup_items'][ $hashes[ $index ] ] = '⟪item_0001⟫' . $translated;
	}
	assert_backfill( 3 === TranslationStore::resolve_pending( $url, $post_id, 'de' ), 'Visitor cache arrival must resolve every pending body and title hash' );
	foreach ( [ [ 'Welcome', 'Willkommen' ], [ 'Contact us', 'Kontaktieren Sie uns' ], [ 'About us', 'Über uns' ] ] as [ $source, $expected ] ) {
		assert_backfill( $expected === TranslationStore::translated_string( $source, $source, $url, 'de', $post_id ), 'Server store must serve the translated ' . $source );
	}
}

// Every group is already in the visitor job: register pending work without
// opening an index job that would translate nothing.
[ $site_id, $post_id, $url, $hashes, $indexer ] = backfill_fixture( [ 0, 1, 2 ] );
$indexer->drain_tick();
$job_posts = array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => str_contains( $post['path'], '/v1/translation/jobs' ) );
assert_backfill( [] === $job_posts, 'All visitor-owned groups must create no empty index job or batch' );
assert_backfill( 3 === TranslationStore::pending_count( $url, 'de', $post_id ), 'All visitor-owned body and title hashes must enter the server store' );
backfill_arrival( $url, $post_id, $hashes );

// Only the indexer's owned group is sent to the model; all page groups still
// reach the store and resolve after the two visitor-owned groups arrive.
[ $site_id, $post_id, $url, $hashes, $indexer ] = backfill_fixture( [ 1, 2 ] );
$indexer->drain_tick();
$job_posts   = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => '/v1/translation/jobs' === $post['path'] ) );
$batch_posts = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => str_ends_with( $post['path'], '/batches' ) ) );
$pushed     = array_column( $batch_posts[0]['body']['items'] ?? [], 'cache' );
assert_backfill( 1 === count( $job_posts ) && 1 === count( $batch_posts ), 'Mixed ownership must send one index job and one batch' );
assert_backfill( [ [ 'siteId' => $site_id, 'hash' => $hashes[0] ] ] === $pushed, 'The index batch must contain only its owned body group' );
assert_backfill( 3 === TranslationStore::pending_count( $url, 'de', $post_id ), 'Mixed ownership must register all body and title hashes' );
backfill_arrival( $url, $post_id, $hashes );

// A failed index batch releases only its own hash. The visitor's claims remain
// in flight and a rejected batch records no unaccepted index result.
[ $site_id, $post_id, $url, $hashes, $indexer ] = backfill_fixture( [ 1, 2 ] );
$GLOBALS['wp_test_batch_post_failures'] = 1;
$indexer->drain_tick();
assert_backfill( 0 === TranslationStore::pending_count( $url, 'de', $post_id ), 'Rejected batch must not announce unaccepted rows to the store' );
assert_backfill( true === TranslationProxyDedupe::claim_segment( $site_id, 'de', $hashes[0] ), 'Rejected index batch must release the index-owned claim' );
assert_backfill( is_wp_error( TranslationProxyDedupe::claim_segment( $site_id, 'de', $hashes[1] ) ), 'Rejected index batch must retain the visitor-owned claim' );
assert_backfill( is_wp_error( TranslationProxyDedupe::claim_segment( $site_id, 'de', $hashes[2] ) ), 'Rejected index batch must retain the visitor-owned title claim' );

// Job-opening failure observes the same ownership boundary before any batch.
[ $site_id, $post_id, $url, $hashes, $indexer ] = backfill_fixture( [ 1, 2 ] );
$GLOBALS['wp_test_translation_job_responses'] = [
	[ 'response' => [ 'code' => 503 ], 'body' => wp_json_encode( [ 'error' => 'unavailable', 'code' => 'service_unavailable' ] ) ],
];
$indexer->drain_tick();
assert_backfill( true === TranslationProxyDedupe::claim_segment( $site_id, 'de', $hashes[0] ), 'Failed job creation must release the index-owned claim' );
assert_backfill( is_wp_error( TranslationProxyDedupe::claim_segment( $site_id, 'de', $hashes[1] ) ), 'Failed job creation must retain the visitor-owned claim' );

if ( $failures > 0 ) {
	fwrite( STDERR, "IndexerStoreBackfillTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "IndexerStoreBackfillTest: all assertions passed\n";
