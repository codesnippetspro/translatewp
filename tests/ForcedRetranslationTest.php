<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Endpoints\Indexing;
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

function connected_options(): array {
	return [
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
		'languages' => [ 'list' => [ [ 'locale' => 'en', 'default' => true, 'active' => true ], [ 'locale' => 'de', 'active' => true ] ] ],
	];
}

function make_indexer( IndexQueue $queue ): Indexer {
	return new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
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

function retranslate_item( string $group_id, string $hash ): array {
	return [
		'groupId'       => $group_id,
		'markerPayload' => '⟪item_0001⟫Salle principale',
		'contextKey'    => 'body>p',
		'contextKind'   => 'text-flow',
		'cache'         => [ 'siteId' => '00000000-0000-4000-8000-000000000001', 'hash' => $hash ],
	];
}

// --- The retranslate flag is carried on the queue row and survives extract -> push ---
wp_test_reset( connected_options() );
$queue = new IndexQueue();
assert_true(
	1 === $queue->enqueue_retranslate( [ [
		'object_type' => 'page',
		'object_id'   => 100,
		'url'         => 'https://example.com/?p=100',
		'page_title'  => 'Room name',
		'language'    => 'de',
		'priority'    => 100,
	] ] ),
	'enqueue_retranslate must queue the page row'
);

$extract_claim = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( 1 === count( $extract_claim ), 'The retranslate row must be claimable for extraction' );
assert_true( true === $extract_claim[0]['retranslate'], 'The claimed extract row must carry the retranslate flag' );

$stored = $queue->store_payload(
	$extract_claim[0]['id'],
	(string) wp_json_encode( [ retranslate_item( 'g-100', str_repeat( 'a', 64 ) ) ] )
);
assert_true( true === $stored, 'Extraction submit must move the retranslate row to the push stage' );

$push_claim = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
assert_true( 1 === count( $push_claim ), 'The retranslate row must be claimable for push' );
assert_true( true === $push_claim[0]['retranslate'], 'The retranslate flag must survive the extract -> push transition' );

// --- complete() consumes the flag so a later normal pass does not force overwrite ---
assert_true( true === $queue->complete( $push_claim[0]['id'] ), 'Completing the pushed row must succeed' );
$listed = $queue->list_rows();
assert_true( 1 === count( $listed ), 'list_rows must return the single row' );
assert_true( false === $listed[0]['retranslate'], 'complete() must clear the retranslate flag' );

// --- A normal rewarm of a previously-retranslated row does not inherit the flag ---
wp_test_reset( connected_options() );
$queue = new IndexQueue();
$queue->enqueue_retranslate( [ [
	'object_type' => 'page',
	'object_id'   => 101,
	'url'         => 'https://example.com/?p=101',
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$queue->enqueue_rewarm( [ [
	'object_type' => 'page',
	'object_id'   => 101,
	'url'         => 'https://example.com/?p=101',
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );
// The rewarm only touches done/dead rows; the live extract row keeps its flag,
// which is the correct behaviour (the operator asked for a retranslation).
$rewarm_rows = $queue->list_rows();
assert_true( true === $rewarm_rows[0]['retranslate'], 'A live retranslate row keeps its flag through a concurrent rewarm' );

// --- The drain sends retranslate:true only on the batch built from flagged rows ---
wp_test_reset( connected_options() );
$queue   = new IndexQueue();
$indexer = make_indexer( $queue );

$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => 1,
	'url'         => 'https://example.com/?p=1',
	'page_title'  => 'Normal',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$queue->enqueue_retranslate( [ [
	'object_type' => 'page',
	'object_id'   => 2,
	'url'         => 'https://example.com/?p=2',
	'page_title'  => 'Forced',
	'language'    => 'de',
	'priority'    => 100,
] ] );

foreach ( $indexer->claim_extract_batch( 10 ) as $claimed ) {
	$group = 2 === (int) $claimed['object_id'] ? 'g-retrans' : 'g-normal';
	$hash  = 2 === (int) $claimed['object_id'] ? str_repeat( 'b', 64 ) : str_repeat( 'c', 64 );
	$indexer->submit_extraction( $claimed['id'], [ retranslate_item( $group, $hash ) ] );
}

$indexer->drain_tick();

$batch_posts = array_values(
	array_filter(
		$GLOBALS['wp_test_remote_posts'],
		static function ( array $post ): bool {
			return (bool) preg_match( '#/batches$#', $post['path'] );
		}
	)
);
assert_true( 2 === count( $batch_posts ), 'Normal and retranslate rows must push as two separate batches, got ' . count( $batch_posts ) );

$retranslate_body = null;
$normal_body      = null;
foreach ( $batch_posts as $post ) {
	$group_ids = array_column( $post['body']['items'] ?? [], 'groupId' );
	if ( in_array( 'g-retrans', $group_ids, true ) ) {
		$retranslate_body = $post['body'];
	} elseif ( in_array( 'g-normal', $group_ids, true ) ) {
		$normal_body = $post['body'];
	}
}

assert_true( is_array( $retranslate_body ), 'The retranslate batch body must be present' );
assert_true( is_array( $normal_body ), 'The normal batch body must be present' );
assert_true( true === ( $retranslate_body['retranslate'] ?? null ), 'The retranslate batch must send retranslate:true' );
assert_true( ! array_key_exists( 'retranslate', (array) $normal_body ), 'The normal batch must not carry the retranslate flag' );

// --- Clearing the store entry drops BOTH storage keys and returns the dropped hashes ---
wp_test_reset( connected_options() );
$page_7 = make_post( 'page', 'Room name' );
$url_7  = 'https://example.com/p7';
$GLOBALS['wp_test_url_to_postid'] = [];
TranslationStore::record_pending(
	$url_7,
	0,
	'de',
	'en',
	[ [ 'hash' => 'hash-url', 'markerPayload' => '⟪item_0001⟫Salle principale' ] ]
);
$GLOBALS['wp_test_url_to_postid'] = [ $url_7 => $page_7 ];
TranslationStore::record_pending(
	$url_7,
	$page_7,
	'de',
	'en',
	[ [ 'hash' => 'hash-post', 'markerPayload' => '⟪item_0001⟫Salle principale' ] ]
);

$post_meta_before = get_post_meta( $page_7, TranslationStore::POST_META_KEY, true );
assert_true( is_array( $post_meta_before ) && isset( $post_meta_before['de'] ), 'Precondition: the post-meta key holds the page entry' );
$url_option_before = get_option( TranslationStore::URL_OPTION, [] );
assert_true( is_array( $url_option_before ) && ! empty( $url_option_before ), 'Precondition: the url option holds the page entry' );

$dropped = TranslationStore::clear_entry( $url_7, $page_7, 'de' );
sort( $dropped );
assert_true( [ 'hash-post', 'hash-url' ] === $dropped, 'clear_entry must return the dropped hashes from both keys, got ' . wp_json_encode( $dropped ) );

$post_meta_after = get_post_meta( $page_7, TranslationStore::POST_META_KEY, true );
assert_true( ! is_array( $post_meta_after ) || ! isset( $post_meta_after['de'] ), 'clear_entry must drop the post-meta entry' );
assert_true( [] === get_option( TranslationStore::URL_OPTION, [] ), 'clear_entry must drop the url-option entry' );

// --- segment_count reports the segments that will be resent ---
wp_test_reset( connected_options() );
$page_9 = make_post( 'page', 'P9' );
$GLOBALS['wp_test_url_to_postid'] = [ 'https://example.com/p9' => $page_9 ];
TranslationStore::record_pending(
	'https://example.com/p9',
	$page_9,
	'de',
	'en',
	[
		[ 'hash' => 'h1', 'markerPayload' => '⟪item_0001⟫One' ],
		[ 'hash' => 'h2', 'markerPayload' => '⟪item_0001⟫Two' ],
	]
);
assert_true( 2 === TranslationStore::segment_count( 'https://example.com/p9', 'de', $page_9 ), 'segment_count must count the pending segments' );

// --- Endpoint permission gating: write routes need the capability and a nonce ---
wp_test_reset( connected_options() );
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( new IndexQueue() ) );

$GLOBALS['wp_test_caps'] = [];
$no_cap = $indexing->admin_write_permissions_check(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-page', [], [ 'X-WP-Nonce' => 'test-nonce' ], '' )
);
assert_true( $no_cap instanceof WP_Error, 'Without manage_options the write route must be forbidden' );

$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$no_nonce = $indexing->admin_write_permissions_check(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-page', [], [], '' )
);
assert_true( $no_nonce instanceof WP_Error, 'A missing nonce must be forbidden even with the capability' );

$ok = $indexing->admin_write_permissions_check(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-page', [], [ 'X-WP-Nonce' => 'test-nonce' ], '' )
);
assert_true( true === $ok, 'The capability plus a valid nonce must pass the write check' );

assert_true( true === $indexing->admin_permissions_check(), 'The pages read route must pass for manage_options' );
$GLOBALS['wp_test_caps'] = [];
assert_true( false === $indexing->admin_permissions_check(), 'The pages read route must fail without manage_options' );

// --- retranslate-all is gated behind the exact typed confirmation phrase ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$queue   = new IndexQueue();
$indexer = make_indexer( $queue );
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );

$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => 50,
	'url'         => 'https://example.com/?p=50',
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$done_claim = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( $done_claim[0]['id'], (string) wp_json_encode( [ retranslate_item( 'g-50', str_repeat( 'd', 64 ) ) ] ) );
$push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$queue->complete( $push[0]['id'] );

$wrong = $indexing->retranslate_all(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-all', [ 'confirm' => 'nope' ], [], '' )
);
assert_true( $wrong instanceof WP_Error, 'retranslate-all must reject a wrong confirmation phrase' );
$after_wrong = $queue->list_rows();
assert_true( IndexQueue::STATUS_DONE === $after_wrong[0]['status'], 'A rejected retranslate-all must leave the row untouched' );

$ok_all = $indexing->retranslate_all(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-all', [ 'confirm' => 'RETRANSLATE' ], [], '' )
);
assert_true( is_array( $ok_all ) && 1 === ( $ok_all['requeued'] ?? 0 ), 'retranslate-all with the phrase must requeue the indexed row, got ' . wp_json_encode( $ok_all ) );
$after_all = $queue->list_rows();
assert_true( IndexQueue::STATUS_PENDING === $after_all[0]['status'], 'retranslate-all must return the row to pending' );
assert_true( IndexQueue::STAGE_EXTRACT === $after_all[0]['stage'], 'retranslate-all must restart the row at the extract stage' );
assert_true( true === $after_all[0]['retranslate'], 'retranslate-all must set the retranslate flag on the row' );

// --- retranslate-page re-queues one page+language and clears its store entry ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$page_60 = make_post( 'page', 'Room' );
$url_60  = 'https://example.com/?p=60';
$GLOBALS['wp_test_url_to_postid'] = [ $url_60 => $page_60 ];
$queue   = new IndexQueue();
$indexer = make_indexer( $queue );
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );

$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => $page_60,
	'url'         => $url_60,
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$page_claim = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( $page_claim[0]['id'], (string) wp_json_encode( [ retranslate_item( 'g-60', str_repeat( 'e', 64 ) ) ] ) );
$page_push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$queue->complete( $page_push[0]['id'] );

TranslationStore::record_pending(
	$url_60,
	$page_60,
	'de',
	'en',
	[ [ 'hash' => 'hash-60', 'markerPayload' => '⟪item_0001⟫Salle principale' ] ]
);
assert_true( 1 === TranslationStore::segment_count( $url_60, 'de', $page_60 ), 'Precondition: the page has one stored segment' );

// --- a crafted request is bound to an indexed page and an active translation language ---
$crafted = static function ( array $params ) use ( $indexing ) {
	return $indexing->retranslate_page( new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-page', $params, [ 'X-WP-Nonce' => 'test-nonce' ], '' ) );
};
$rows_before = count( $queue->list_rows() );
foreach ( [
	[ 'objectType' => 'page', 'objectId' => 999, 'url' => $url_60, 'language' => 'de' ],
	[ 'objectType' => 'post', 'objectId' => $page_60, 'url' => $url_60, 'language' => 'de' ],
	[ 'objectType' => 'page', 'objectId' => $page_60, 'url' => 'https://example.com/elsewhere/', 'language' => 'de' ],
	[ 'objectType' => 'miss', 'objectId' => 4242, 'url' => 'https://example.com/never-seen/', 'language' => 'de' ],
] as $params ) {
	$answer = $crafted( $params );
	assert_true( is_wp_error( $answer ) && 404 === ( $answer->get_error_data()['status'] ?? 0 ), 'retranslate-page refuses a page that is not indexed: ' . wp_json_encode( $params ) );
}
foreach ( [ 'xx', 'en', 'fr; drop' ] as $language ) {
	$answer = $crafted( [ 'objectType' => 'page', 'objectId' => $page_60, 'url' => $url_60, 'language' => $language ] );
	assert_true( is_wp_error( $answer ) && 400 === ( $answer->get_error_data()['status'] ?? 0 ), "retranslate-page refuses a language that is not an active translation language: {$language}" );
}
assert_true( $rows_before === count( $queue->list_rows() ), 'refused requests create no queue row' );
assert_true( 1 === TranslationStore::segment_count( $url_60, 'de', $page_60 ), 'refused requests purge nothing' );

$page_response = $indexing->retranslate_page(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/indexing/retranslate-page',
		[
			'objectType' => 'page',
			'objectId'   => $page_60,
			'url'        => $url_60,
			'language'   => 'de',
		],
		[ 'X-WP-Nonce' => 'test-nonce' ],
		''
	)
);
assert_true( is_array( $page_response ) && 1 === ( $page_response['requeued'] ?? 0 ), 'retranslate-page must requeue the one row, got ' . wp_json_encode( $page_response ) );
$page_rows = $queue->list_rows();
assert_true( IndexQueue::STAGE_EXTRACT === $page_rows[0]['stage'] && true === $page_rows[0]['retranslate'], 'retranslate-page must requeue the row to extract with the flag' );
assert_true( 0 === TranslationStore::segment_count( $url_60, 'de', $page_60 ), 'retranslate-page must clear the stored translations for the page' );

// --- retranslate-page rejects an incomplete request ---
$bad = $indexing->retranslate_page(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-page', [ 'objectType' => 'page' ], [ 'X-WP-Nonce' => 'test-nonce' ], '' )
);
assert_true( $bad instanceof WP_Error, 'retranslate-page must reject a request missing objectId and url' );

// --- The pages list exposes per-row segment counts and totals for the cost guard ---
wp_test_reset( connected_options() );
$page_70 = make_post( 'page', 'Room' );
$url_70  = 'https://example.com/?p=70';
$GLOBALS['wp_test_url_to_postid'] = [ $url_70 => $page_70 ];
$queue   = new IndexQueue();
$indexer = make_indexer( $queue );
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );
$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => $page_70,
	'url'         => $url_70,
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );
TranslationStore::record_pending(
	$url_70,
	$page_70,
	'de',
	'en',
	[ [ 'hash' => 'hash-70', 'markerPayload' => '⟪item_0001⟫Salle principale' ] ]
);
$pages = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
assert_true( 1 === count( $pages['pages'] ), 'pages must list the one queued page' );
assert_true( 1 === $pages['pages'][0]['languages']['de']['segments'], 'The page language entry must report its stored segment count' );
assert_true( 'pending' === $pages['pages'][0]['languages']['de']['status'], 'A fresh row must read as pending' );
assert_true( 1 === $pages['totals']['rows'] && 1 === $pages['totals']['segments'], 'The totals must drive the site-wide cost guard, got ' . wp_json_encode( $pages['totals'] ) );

// --- An in-flight retranslate row reads as "retranslating", then "indexed" ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$page_80 = make_post( 'page', 'Room' );
$url_80  = 'https://example.com/?p=' . $page_80;
$GLOBALS['wp_test_url_to_postid'] = [ $url_80 => $page_80 ];
$queue    = new IndexQueue();
$indexer  = make_indexer( $queue );
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );

$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => $page_80,
	'url'         => $url_80,
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$claim_80 = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( $claim_80[0]['id'], (string) wp_json_encode( [ retranslate_item( 'g-80', str_repeat( 'f', 64 ) ) ] ) );
$push_80 = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$queue->complete( $push_80[0]['id'] );

$indexed = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
assert_true( 'pending' === $indexed['pages'][0]['languages']['de']['status'], 'A pushed row without delivery evidence remains pending before a retranslation' );

$indexing->retranslate_page(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/indexing/retranslate-page',
		[ 'objectType' => 'page', 'objectId' => $page_80, 'url' => $url_80, 'language' => 'de' ],
		[ 'X-WP-Nonce' => 'test-nonce' ],
		''
	)
);
$retranslating = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
assert_true(
	'retranslating' === $retranslating['pages'][0]['languages']['de']['status'],
	'A re-queued retranslate row must read as retranslating, got ' . (string) $retranslating['pages'][0]['languages']['de']['status']
);

$re_claim = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( $re_claim[0]['id'], (string) wp_json_encode( [ retranslate_item( 'g-80b', str_repeat( 'g', 64 ) ) ] ) );
$re_push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$queue->complete( $re_push[0]['id'] );
$reindexed = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
assert_true(
	'pending' === $reindexed['pages'][0]['languages']['de']['status'],
	'An accepted retranslation without cache evidence must remain pending, got ' . (string) $reindexed['pages'][0]['languages']['de']['status']
);
assert_true( (int) $reindexed['pages'][0]['languages']['de']['updated'] > 0, 'The re-indexed row must carry a fresh updated time' );

// --- Both retranslate routes bump the cache generation so the browser busts its hydration cache ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$page_90 = make_post( 'page', 'Room' );
$url_90  = 'https://example.com/?p=' . $page_90;
$GLOBALS['wp_test_url_to_postid'] = [ $url_90 => $page_90 ];
$queue    = new IndexQueue();
$indexing = new Indexing( 'translatewp', TRANSLATEWP_VERSION, make_indexer( $queue ) );
$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => $page_90,
	'url'         => $url_90,
	'page_title'  => 'Room',
	'language'    => 'de',
	'priority'    => 100,
] ] );

$generation_before = TranslationStore::cache_generation();
$indexing->retranslate_page(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/indexing/retranslate-page',
		[ 'objectType' => 'page', 'objectId' => $page_90, 'url' => $url_90, 'language' => 'de' ],
		[ 'X-WP-Nonce' => 'test-nonce' ],
		''
	)
);
assert_true(
	TranslationStore::cache_generation() > $generation_before,
	'retranslate-page must bump the cache generation, got ' . TranslationStore::cache_generation() . ' from ' . $generation_before
);

// F37: the bump is scoped to the retranslated language. German advances; French,
// never retranslated, stays on the site-wide fallback so its visitors keep the
// warm local cache.
assert_true(
	TranslationStore::cache_generation_for( 'de' ) > TranslationStore::cache_generation_fallback(),
	'retranslate-page of de must advance the de generation past the fallback'
);
assert_true(
	TranslationStore::cache_generation_for( 'fr' ) === TranslationStore::cache_generation_fallback(),
	'retranslate-page of de must leave fr on the site-wide fallback, got ' . TranslationStore::cache_generation_for( 'fr' )
);

$generation_after_page = TranslationStore::cache_generation();
$indexing->retranslate_all(
	new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retranslate-all', [ 'confirm' => 'RETRANSLATE' ], [ 'X-WP-Nonce' => 'test-nonce' ], '' )
);
assert_true(
	TranslationStore::cache_generation() > $generation_after_page,
	'retranslate-all must bump the cache generation, got ' . TranslationStore::cache_generation() . ' from ' . $generation_after_page
);

// --- A retranslate row re-enters extraction and keeps its page title ---
wp_test_reset( connected_options() );
$queue = new IndexQueue();

// A page first warmed and completed while it was known only by its URL (a
// visitor miss leaves the page-title meta empty), so the Pages list shows the
// bare path until a titled retranslate refreshes it.
$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => 200,
	'url'         => 'https://example.com/pricing',
	'page_title'  => '',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$claim_200 = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( $claim_200[0]['id'], (string) wp_json_encode( [ retranslate_item( 'g-200', str_repeat( 'h', 64 ) ) ] ) );
$push_200 = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$queue->complete( $push_200[0]['id'] );

$before_title = $queue->list_rows();
assert_true( '' === $before_title[0]['page_title'], 'Precondition: the completed row carries no page title' );

$queue->enqueue_retranslate( [ [
	'object_type' => 'page',
	'object_id'   => 200,
	'url'         => 'https://example.com/pricing',
	'page_title'  => 'Pricing',
	'language'    => 'de',
	'priority'    => 100,
] ] );

$after_title = $queue->list_rows();
assert_true( 1 === count( $after_title ), 'Retranslate must reuse the existing row, not add a second' );
assert_true( IndexQueue::STAGE_EXTRACT === $after_title[0]['stage'], 'Retranslate must return the row to the extract stage' );
assert_true( true === $after_title[0]['retranslate'], 'The re-queued row must carry the retranslate flag' );
assert_true( 'Pricing' === $after_title[0]['page_title'], 'The retranslate row must keep the page title, not revert to the bare path' );

// --- Retranslating a pending push row re-extracts instead of resending the stored payload ---
wp_test_reset( connected_options() );
$queue = new IndexQueue();
$queue->enqueue( [ [
	'object_type' => 'page',
	'object_id'   => 201,
	'url'         => 'https://example.com/rooms',
	'page_title'  => 'Rooms',
	'language'    => 'de',
	'priority'    => 100,
] ] );
$claim_201 = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
$queue->store_payload( $claim_201[0]['id'], (string) wp_json_encode( [ retranslate_item( 'g-201', str_repeat( 'i', 64 ) ) ] ) );

$pending_push = $queue->list_rows();
assert_true(
	IndexQueue::STAGE_PUSH === $pending_push[0]['stage'] && IndexQueue::STATUS_PENDING === $pending_push[0]['status'],
	'Precondition: the row waits at the push stage with a stored payload'
);

$queue->enqueue_retranslate( [ [
	'object_type' => 'page',
	'object_id'   => 201,
	'url'         => 'https://example.com/rooms',
	'page_title'  => 'Rooms',
	'language'    => 'de',
	'priority'    => 100,
] ] );

$requeued_rows = $queue->list_rows();
assert_true( IndexQueue::STAGE_EXTRACT === $requeued_rows[0]['stage'], 'Retranslate must move a pending push row back to extraction, not leave it to resend the stored payload' );
assert_true( IndexQueue::STATUS_PENDING === $requeued_rows[0]['status'], 'The re-queued row must be pending for extraction' );

$reextract_claim = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( 1 === count( $reextract_claim ), 'The re-queued row must be claimable for extraction' );
assert_true( '' === (string) $reextract_claim[0]['payload'], 'The stored payload must be dropped so the tab re-extracts fresh neighbours and context' );
assert_true( true === $reextract_claim[0]['retranslate'], 'The re-extracted row must carry the retranslate flag' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ForcedRetranslationTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ForcedRetranslationTest: all assertions passed\n";
