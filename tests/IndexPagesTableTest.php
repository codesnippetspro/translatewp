<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Migrations;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Endpoints\Indexing;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.19' );
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
	return [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ];
}

function make_post( string $type, string $title ): int {
	return (int) wp_insert_post(
		[
			'post_type'   => $type,
			'post_title'  => $title,
			'post_status' => 'publish',
		]
	);
}

function make_indexer( IndexQueue $queue ): Indexer {
	return new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
}

function enqueue_row( IndexQueue $queue, string $object_type, int $object_id, string $url, string $language ): void {
	$queue->enqueue(
		[
			[
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'url'         => $url,
				'page_title'  => 'Page ' . $object_id,
				'language'    => $language,
				'priority'    => 100,
			],
		]
	);
}

function complete_row( IndexQueue $queue ): void {
	$extract = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
	$queue->store_payload( (int) $extract[0]['id'], (string) wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => 'm' ] ] ) );
	$push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
	$queue->complete( (int) $push[0]['id'] );
}

function dead_letter_row( IndexQueue $queue, string $reason ): void {
	for ( $attempt = 0; $attempt < IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
		$claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
		$queue->fail( (int) $claimed[0]['id'], $reason );
	}
}

// --- the pages endpoint returns one entry per page with a per-language map ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$post_room = make_post( 'page', 'Room' );
$url_room  = 'https://example.com/?p=' . $post_room;
$GLOBALS['wp_test_url_to_postid'] = [ $url_room => $post_room ];

$queue    = new IndexQueue();
$indexer  = make_indexer( $queue );
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );

enqueue_row( $queue, 'post', $post_room, $url_room, 'de' );
complete_row( $queue );

TranslationStore::record_pending(
	$url_room,
	$post_room,
	'de',
	'en',
	[ [ 'hash' => 'h-room-1', 'markerPayload' => '⟪item_0001⟫Zimmer' ] ]
);

enqueue_row( $queue, 'post', $post_room, $url_room, 'fr' );
dead_letter_row( $queue, 'page_fetch_403' );

enqueue_row( $queue, 'post', $post_room, $url_room, 'it' );
$queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
enqueue_row( $queue, 'post', $post_room, $url_room, 'lv' );

$response = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );

assert_true( is_array( $response ) && isset( $response['pages'] ), 'pages must return a pages array' );
assert_true( 1 === count( $response['pages'] ), 'the two languages of one page must collapse to a single page entry, got ' . count( $response['pages'] ) );

$page = $response['pages'][0];
assert_true( $url_room === ( $page['url'] ?? '' ), 'the page entry must carry the page URL' );
assert_true( isset( $page['languages']['de'], $page['languages']['fr'] ), 'the page entry must carry a per-language map for de and fr' );

$de = $page['languages']['de'];
assert_true(
	isset( $de['id'], $de['status'], $de['segments'], $de['reason'], $de['updated'] ),
	'each language entry must expose id, status, segments, reason and updated'
);
assert_true( 'pending' === $de['status'], 'An accepted queue row without cache readiness must remain pending, got ' . (string) $de['status'] );
assert_true( 1 === (int) $de['segments'], 'the indexed language must report its stored segment count, got ' . (int) $de['segments'] );
assert_true( (int) $de['updated'] > 0, 'the indexed language must carry an updated timestamp' );

$fr = $page['languages']['fr'];
assert_true( 'failed' === $fr['status'], 'the dead-lettered language must read as failed, got ' . (string) $fr['status'] );
assert_true( 'page_fetch_403' === (string) $fr['reason'], 'the failed language must carry its failure reason, got ' . (string) $fr['reason'] );

assert_true( 'claimed' === ( $page['languages']['it']['queueStatus'] ?? '' ), 'Claimed queue state must be exposed' );
assert_true( 'pending' === ( $page['languages']['lv']['queueStatus'] ?? '' ), 'Pending queue state must be exposed' );
assert_true( ! isset( $page['languages']['it']['stage'] ), 'Internal stage must not be exposed' );

// A language still on its way says since when: from its claim while a worker has it,
// from its hand-over once sent and awaiting the translation; a queued or failed one has no start.
assert_true( (int) ( $page['languages']['it']['translatingSince'] ?? 0 ) > 0, 'A claimed language must carry the time it was claimed' );
assert_true( (int) ( $page['languages']['de']['translatingSince'] ?? 0 ) === (int) $de['updated'], 'A sent language awaiting its translation must carry the time it was sent' );
assert_true( 0 === (int) ( $page['languages']['lv']['translatingSince'] ?? -1 ), 'A language still waiting in the queue must carry no start time' );
assert_true( 0 === (int) ( $page['languages']['fr']['translatingSince'] ?? -1 ), 'A failed language must carry no start time' );

// --- the start time holds across extraction batches, the push stage and the hand-over ---
final class ClockIndexQueue extends IndexQueue {
	public int $clock = 1000;

	protected function now(): int {
		return $this->clock;
	}
}

$since_of = static function ( Indexing $indexing, string $url, string $language ): int {
	$response = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
	foreach ( $response['pages'] as $entry ) {
		if ( $url === $entry['url'] ) {
			return (int) ( $entry['languages'][ $language ]['translatingSince'] ?? -1 );
		}
	}
	return -1;
};

wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$post_hall  = make_post( 'page', 'Hall' );
$url_hall   = 'https://example.com/?p=' . $post_hall;
$GLOBALS['wp_test_url_to_postid'] = [ $url_hall => $post_hall ];
$clock_queue    = new ClockIndexQueue();
$clock_indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $clock_queue ) );

enqueue_row( $clock_queue, 'post', $post_hall, $url_hall, 'es' );
$hall_claim = $clock_queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
$hall_id    = (int) $hall_claim[0]['id'];
assert_true( 1000 === $since_of( $clock_indexing, $url_hall, 'es' ), 'A claimed row must carry its claim as the start, got ' . $since_of( $clock_indexing, $url_hall, 'es' ) );
$clock_queue->clock = 1300;
$clock_queue->append_extraction( $hall_id, [ [ 'groupId' => 'g1', 'markerPayload' => 'm1' ] ], false );
assert_true( 1000 === $since_of( $clock_indexing, $url_hall, 'es' ), 'A batch heartbeat must not restart the clock, got ' . $since_of( $clock_indexing, $url_hall, 'es' ) );

$clock_queue->clock = 1600;
$clock_queue->append_extraction( $hall_id, [ [ 'groupId' => 'g2', 'markerPayload' => 'm2' ] ], true );
$clock_queue->clock = 1900;
$hall_push = $clock_queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
assert_true( 1000 === $since_of( $clock_indexing, $url_hall, 'es' ), 'The push stage must keep the start time, got ' . $since_of( $clock_indexing, $url_hall, 'es' ) );

$clock_queue->clock = 2000;
$clock_queue->complete( (int) $hall_push[0]['id'] );
assert_true( 1000 === $since_of( $clock_indexing, $url_hall, 'es' ), 'The hand-over must keep the start time, got ' . $since_of( $clock_indexing, $url_hall, 'es' ) );

// A new round for the same page starts its own clock.
$clock_queue->clock = 5000;
$clock_queue->enqueue_retranslate( [ [ 'object_type' => 'post', 'object_id' => $post_hall, 'url' => $url_hall, 'page_title' => 'Hall', 'language' => 'es', 'priority' => 100 ] ] );
$clock_queue->clock = 5100;
$clock_queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
assert_true( 5100 === $since_of( $clock_indexing, $url_hall, 'es' ), 'A new round must start a new clock, got ' . $since_of( $clock_indexing, $url_hall, 'es' ) );

// A failed row that is tried again starts a new clock too.
for ( $attempt = 0; $attempt < IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$clock_queue->clock = 6000 + $attempt;
	$hall_retry = $clock_queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
	if ( $hall_retry ) {
		$clock_queue->fail( (int) $hall_retry[0]['id'], 'page_fetch_403' );
	}
}
$clock_queue->retry_failed( 'es' );
$clock_queue->clock = 7000;
$clock_queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
assert_true( 7000 === $since_of( $clock_indexing, $url_hall, 'es' ), 'A retried failed row must start a new clock, got ' . $since_of( $clock_indexing, $url_hall, 'es' ) );

assert_true(
	4 === (int) ( $response['totals']['rows'] ?? 0 ),
	'the totals must still count every page+language row, got ' . (int) ( $response['totals']['rows'] ?? 0 )
);

// --- the endpoint collapses stale duplicate rows for one page and language on read ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$url_dupe = 'https://example.com/duplicate';
$queue    = new IndexQueue();
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $queue ) );

enqueue_row( $queue, 'post', 7, $url_dupe, 'de' );
enqueue_row( $queue, 'miss', 0, $url_dupe, 'de' );

assert_true( 2 === count( $queue->list_rows() ), 'precondition: two rows exist for the same page and language' );

$dupe_response = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );

assert_true( 1 === count( $dupe_response['pages'] ), 'duplicate rows for one page and language must collapse to a single entry' );
assert_true( 1 === count( $dupe_response['pages'][0]['languages'] ), 'the collapsed entry must keep a single language entry' );
assert_true( 1 === count( $queue->list_rows() ), 'the stale duplicate row must be deleted on read, leaving one row' );
assert_true( 'miss' === ( $dupe_response['pages'][0]['objectType'] ?? '' ), 'the newest row must own the collapsed page identity' );

// --- the endpoint collapses /pricing and /pricing/ (same language) to one entry ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$queue    = new IndexQueue();
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $queue ) );

enqueue_row( $queue, 'post', 21, 'https://example.com/pricing', 'lv' );
enqueue_row( $queue, 'miss', 0, 'https://example.com/pricing/', 'lv' );

assert_true( 2 === count( $queue->list_rows() ), 'precondition: /pricing and /pricing/ start as two rows' );

$slash_response = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );

assert_true( 1 === count( $slash_response['pages'] ), '/pricing and /pricing/ must collapse to a single page entry, got ' . count( $slash_response['pages'] ) );
assert_true( 1 === count( $queue->list_rows() ), 'the older of the two slash-variant rows must be deleted on read' );

// --- the endpoint groups two languages of one page under a single canonical
// URL even when their raw stored URLs differ only by a trailing slash ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$queue    = new IndexQueue();
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $queue ) );

enqueue_row( $queue, 'post', 22, 'https://example.com/pricing', 'de' );
enqueue_row( $queue, 'post', 22, 'https://example.com/pricing/', 'fr' );

$cross_language_response = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );

assert_true(
	1 === count( $cross_language_response['pages'] ),
	'the two languages must group under one canonical page entry, got ' . count( $cross_language_response['pages'] )
);
assert_true(
	isset( $cross_language_response['pages'][0]['languages']['de'], $cross_language_response['pages'][0]['languages']['fr'] ),
	'the grouped page entry must keep both language entries'
);

// --- the 0.9.17 migration collapses rows keyed to the same canonical URL,
// deleting the older of the two ---
wp_test_reset( [] );
update_option( 'translatewp_version', '0.9.14' );

$queue = new IndexQueue();
enqueue_row( $queue, 'post', 9, 'https://example.com/migrate', 'de' );
$older_id = (int) $queue->list_rows()[0]['id'];
enqueue_row( $queue, 'miss', 0, 'https://example.com/migrate/', 'de' );

assert_true( 2 === count( $queue->list_rows() ), 'precondition: the migration starts with two slash-variant duplicate rows' );

Migrations::maybe_run();

$rows_after = $queue->list_rows();
assert_true( 1 === count( $rows_after ), 'the 0.9.17 migration must collapse the slash-variant duplicate rows to one' );
assert_true( $older_id !== (int) $rows_after[0]['id'], 'the migration must delete the older row and keep the newer one' );
assert_true( TRANSLATEWP_VERSION === get_option( 'translatewp_version', '' ), 'the migration must bump the stored version marker to the current version, got ' . get_option( 'translatewp_version', '' ) );

// --- the 0.9.19 migration deletes rows keyed to a Customizer preview or
// admin url, keeping rows for an ordinary page url ---
wp_test_reset( [] );
update_option( 'translatewp_version', '0.9.18' );

$queue = new IndexQueue();
enqueue_row( $queue, 'post', 30, 'https://example.com/about/?customize_changeset_uuid=abc-123', 'lv' );
enqueue_row( $queue, 'post', 31, 'https://example.com/wp-admin/customize.php', 'lv' );
enqueue_row( $queue, 'post', 32, 'https://example.com/about', 'lv' );

assert_true( 3 === count( $queue->list_rows() ), 'precondition: three rows exist before the 0.9.19 migration' );

Migrations::maybe_run();

$rows_after_url_guard = $queue->list_rows();
$kept_urls            = array_column( $rows_after_url_guard, 'url' );
assert_true( 1 === count( $rows_after_url_guard ), 'the 0.9.19 migration must drop the preview and admin url rows, got ' . wp_json_encode( $kept_urls ) );
assert_true( [ 'https://example.com/about' ] === $kept_urls, 'the surviving row must be the ordinary page url, got ' . wp_json_encode( $kept_urls ) );
assert_true( TRANSLATEWP_VERSION === get_option( 'translatewp_version', '' ), 'the 0.9.19 migration must bump the stored version marker to the current version, got ' . get_option( 'translatewp_version', '' ) );

// --- a visitor miss row never renames the page: its title is the visitor's, possibly translated, document title ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$queue    = new IndexQueue();
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $queue ) );
$title_post = make_post( 'page', 'Opening hours' );
$title_url  = 'https://example.com/opening-hours';
$GLOBALS['wp_test_url_to_postid'][ $title_url ] = $title_post;
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => $title_post, 'url' => $title_url, 'page_title' => 'Opening hours', 'language' => 'fr', 'priority' => 100 ] ] );
$queue->enqueue( [ [ 'object_type' => 'miss', 'object_id' => 0, 'url' => $title_url, 'page_title' => 'Öffnungszeiten – Example Site', 'language' => 'de', 'priority' => 10 ] ] );
TranslationStore::record_pending( $title_url, $title_post, 'fr', 'en', [ [ 'hash' => 'h-title-fr', 'markerPayload' => '⟪item_0001⟫Opening hours' ] ] );
TranslationStore::resolve_pending( $title_url, $title_post, 'fr', static fn() => [ 'h-title-fr' => '⟪item_0001⟫Horaires' ] );

$title_response = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
$title_page     = $title_response['pages'][0] ?? [];
assert_true( 'Opening hours' === ( $title_page['title'] ?? '' ), 'a visitor miss row must not rename the page, got ' . wp_json_encode( $title_page['title'] ?? null ) );
assert_true( 'stored' === ( $title_page['languages']['fr']['title'] ?? '' ), 'the French title state must be read for the page\'s own title, got ' . wp_json_encode( $title_page['languages']['fr'] ?? null ) );

// --- each page says whether indexing covers it or only a visitor opened it ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$queue    = new IndexQueue();
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $queue ) );
enqueue_row( $queue, 'page', 11, 'https://example.com/spine', 'fr' );
enqueue_row( $queue, 'miss', 0, 'https://example.com/spine', 'de' );
enqueue_row( $queue, 'miss', 0, 'https://example.com/category/news', 'fr' );
$scope_pages = array_column( $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) )['pages'], null, 'url' );
assert_true( true === ( $scope_pages['https://example.com/spine']['indexed'] ?? null ), 'a page with an indexed row is indexed even when a visitor miss is newer, got ' . wp_json_encode( $scope_pages['https://example.com/spine'] ?? null ) );
assert_true( false === ( $scope_pages['https://example.com/category/news']['indexed'] ?? null ), 'a page only a visitor opened is not indexed, got ' . wp_json_encode( $scope_pages['https://example.com/category/news'] ?? null ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "IndexPagesTableTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "IndexPagesTableTest: all assertions passed\n";
