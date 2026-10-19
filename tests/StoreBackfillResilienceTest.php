<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.15' );
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
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
		'languages' => [
			'list' => [
				'fr' => [ 'locale' => 'fr', 'slug' => 'fr', 'active' => true, 'default' => true ],
				'zh' => [ 'locale' => 'zh', 'slug' => 'zh', 'active' => true, 'default' => false ],
				'hi' => [ 'locale' => 'hi', 'slug' => 'hi', 'active' => true, 'default' => false ],
			],
		],
	];
}

// ---------------------------------------------------------------------------
// Defect 2 — a locale added after a page's first index never back-fills.
//
// The <title> is a server-only segment (the visitor path translates document
// body, never the head), so it reaches the store only through the warm-up
// back-fill. A newly added locale's translation can take several back-fill
// passes to appear in the API cache (a busy provider, a shared quota). The
// back-fill must keep the page+locale queued until every hash resolves, never
// abandon it, or the title stays in the source language forever.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$post_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Horaire des cours', 'post_status' => 'publish' ] );
$url     = 'https://example.com/horaire-des-cours';
$GLOBALS['wp_test_url_to_postid']      = [ $url => $post_id ];
$GLOBALS['wp_test_permalinks'][ $post_id ] = $url;

// Chinese was indexed first and resolved; its title reads from the store.
TranslationStore::record_pending( $url, $post_id, 'zh', 'fr', [ [ 'hash' => 'h-de-title', 'markerPayload' => '⟪item_0001⟫Horaire des cours' ] ] );
$GLOBALS['wp_test_cache_lookup_items']['h-de-title'] = '⟪item_0001⟫课程安排';
TranslationStore::run_backfill_batch( 25 );
assert_true( '课程安排' === TranslationStore::translated_string( 'Horaire des cours', 'Horaire des cours', $url, 'zh', $post_id ), 'Precondition: the Chinese title resolves' );

// Hindi is added later and recorded pending for the same page.
TranslationStore::record_pending( $url, $post_id, 'hi', 'fr', [ [ 'hash' => 'h-it-title', 'markerPayload' => '⟪item_0001⟫Horaire des cours' ] ] );

// The API is slow to cache the Hindi title. Many back-fill passes run while
// the cache stays empty (roughly ten minutes of request-time kicks).
for ( $pass = 0; $pass < 8; $pass++ ) {
	TranslationStore::run_backfill_batch( 25 );
}
assert_true( 1 === TranslationStore::pending_count( $url, 'hi', $post_id ), 'The Hindi title must stay pending while the cache is cold' );
assert_true( true === TranslationStore::has_pending_work(), 'The Hindi back-fill work item must not be abandoned after repeated cold passes' );

// The Hindi title is finally cached; the next back-fill resolves it.
$GLOBALS['wp_test_cache_lookup_items']['h-it-title'] = '⟪item_0001⟫Horaire des cours (HI)';
TranslationStore::run_backfill_batch( 25 );
assert_true(
	'Horaire des cours (HI)' === TranslationStore::translated_string( 'Horaire des cours', 'Horaire des cours', $url, 'hi', $post_id ),
	'The Hindi title must back-fill once the cache catches up, got ' . wp_json_encode( TranslationStore::translated_string( 'Horaire des cours', 'Horaire des cours', $url, 'hi', $post_id ) )
);
assert_true( '课程安排' === TranslationStore::translated_string( 'Horaire des cours', 'Horaire des cours', $url, 'zh', $post_id ), 'The Chinese title must be unaffected' );

// ---------------------------------------------------------------------------
// Defect 1 (ordering) — the retranslate overwrite must land before the store
// back-fill reads it, or the store latches the superseded translation.
//
// A forced retranslation clears the store and re-pushes. The API overwrites its
// cache asynchronously, so a back-fill that reads the cache in the same tick
// still sees the OLD text. The recorded push is held for the overwrite window,
// so the back-fill leaves the hashes pending until the new translation lands.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$sync    = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );

$room_id  = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Tarifs', 'post_status' => 'publish' ] );
$room_url = 'https://example.com/tarifs';
$GLOBALS['wp_test_url_to_postid']        = [ $room_url => $room_id ];
$GLOBALS['wp_test_permalinks'][ $room_id ] = $room_url;

// A forced retranslation re-queues the page carrying the retranslate flag.
$queue->enqueue_retranslate( [ [
	'object_type' => 'page',
	'object_id'   => $room_id,
	'url'         => $room_url,
	'page_title'  => 'Tarifs',
	'language'    => 'zh',
	'priority'    => Indexer::PRIORITY_DEFAULT,
] ] );
$extract = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( (int) $extract[0]['id'], (string) wp_json_encode( [ [
	'groupId'       => 'g-room',
	'markerPayload' => '⟪item_0001⟫Salle principale',
	'contextKey'    => 'body>h2',
	'cache'         => [ 'siteId' => '00000000-0000-4000-8000-000000000001', 'hash' => 'h-room' ],
] ] ) );

// The push leaves under the fingerprint the site computes, whatever hash the
// extracting browser attached; the cache is keyed the same way.
$room_hash = \TranslateWP\Components\TranslationFingerprint::compute_hash(
	'⟪item_0001⟫Salle principale',
	\TranslateWP\Components\TranslationProxySettings::default_locale(),
	'zh',
	\TranslateWP\Components\TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW,
	\TranslateWP\Components\TranslationProxySettings::ai_settings( 'zh' )
);

// The API cache still holds the OLD (wrong) Chinese translation at drain time;
// the retranslate overwrite has not landed yet.
$GLOBALS['wp_test_cache_lookup_items'][ $room_hash ] = '⟪item_0001⟫旧礼堂';

$indexer->drain_tick();
assert_true( 2 === TranslationStore::pending_count( $room_url, 'zh', $room_id ), 'The retranslate push must record the body and title pending' );

// A back-fill that runs before the overwrite lands must NOT latch the stale value.
$sync->run_backfill();
assert_true(
	'Salle principale' === TranslationStore::translated_string( 'Salle principale', 'Salle principale', $room_url, 'zh', $room_id ),
	'The held back-fill must not record the superseded translation, got ' . wp_json_encode( TranslationStore::translated_string( 'Salle principale', 'Salle principale', $room_url, 'zh', $room_id ) )
);
assert_true( 2 === TranslationStore::pending_count( $room_url, 'zh', $room_id ), 'The body and title must stay pending while the overwrite is in flight' );

// The overwrite lands (the cache now returns the fresh text) and the hold window
// elapses; the next back-fill resolves to the new translation.
$GLOBALS['wp_test_cache_lookup_items'][ $room_hash ] = '⟪item_0001⟫大礼堂';
TranslationStore::hold_overwrite( $room_url, $room_id, 'zh', -1 );
$sync->run_backfill();
assert_true(
	'大礼堂' === TranslationStore::translated_string( 'Salle principale', 'Salle principale', $room_url, 'zh', $room_id ),
	'After the overwrite lands the back-fill must record the fresh translation, got ' . wp_json_encode( TranslationStore::translated_string( 'Salle principale', 'Salle principale', $room_url, 'zh', $room_id ) )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreBackfillResilienceTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "StoreBackfillResilienceTest: all assertions passed\n";

// ---------------------------------------------------------------------------
// A back-fill batch rotates: rows the cache cannot answer yet move behind the
// rows not tried, so a row past the batch size still resolves, and a row that
// misses the cache for MAX_BACKFILL_ATTEMPTS passes drops its pending hashes.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$GLOBALS['wp_test_cache_lookup_items'] = [];
$cold_ids = [];
for ( $i = 1; $i <= 30; $i++ ) {
	$cold_id  = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => "Page $i", 'post_status' => 'publish' ] );
	$cold_url = "https://example.com/page-$i";
	$GLOBALS['wp_test_url_to_postid'][ $cold_url ]  = $cold_id;
	$GLOBALS['wp_test_permalinks'][ $cold_id ]       = $cold_url;
	$cold_ids[]                                       = [ $cold_id, $cold_url ];
	TranslationStore::record_pending( $cold_url, $cold_id, 'zh', 'fr', [ [ 'hash' => "h-cold-$i", 'markerPayload' => "⟪item_0001⟫Page $i" ] ] );
}
$tail_id  = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Contact', 'post_status' => 'publish' ] );
$tail_url = 'https://example.com/contact';
$GLOBALS['wp_test_url_to_postid'][ $tail_url ] = $tail_id;
$GLOBALS['wp_test_permalinks'][ $tail_id ]      = $tail_url;
TranslationStore::record_pending( $tail_url, $tail_id, 'zh', 'fr', [ [ 'hash' => 'h-tail-title', 'markerPayload' => '⟪item_0001⟫Contact' ] ] );
$GLOBALS['wp_test_cache_lookup_items']['h-tail-title'] = '⟪item_0001⟫联系';

TranslationStore::run_backfill_batch( 25 );
TranslationStore::run_backfill_batch( 25 );
assert_true( 0 === TranslationStore::pending_count( $tail_url, 'zh', $tail_id ), 'A row behind the batch size must resolve on the following pass' );
assert_true( '联系' === TranslationStore::translated_string( 'Contact', 'Contact', $tail_url, 'zh', $tail_id ), 'The tail row title must read from the store' );
assert_true( 1 === TranslationStore::pending_count( $cold_ids[0][1], 'zh', $cold_ids[0][0] ), 'A cold row stays pending while the cache does not know it' );

for ( $pass = 0; $pass < TranslationStore::MAX_BACKFILL_ATTEMPTS * 2; $pass++ ) {
	TranslationStore::run_backfill_batch( 25 );
}
assert_true( 0 === TranslationStore::pending_count( $cold_ids[0][1], 'zh', $cold_ids[0][0] ), 'A row that misses the cache for the bounded number of passes drops its pending hashes' );
assert_true( false === TranslationStore::has_pending_work(), 'No work stays queued once every row resolved or gave up' );
assert_true( 'missing' === TranslationStore::title_state( $cold_ids[0][1], 'Page 1', 'zh', $cold_ids[0][0] ), 'The dropped row reads as not translated, not as still translating' );

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreBackfillResilienceTest (rotation) failed with {$failures} assertion(s)\n" );
	exit( 1 );
}
