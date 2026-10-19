<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\TranslationUrl;
use TranslateWP\Functionality\Endpoints\Indexing;
use TranslateWP\Functionality\Endpoints\TranslationJobs;
use TranslateWP\Functionality\Endpoints\TranslationPageMap;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\TranslationStoreSync;

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
	return [
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
		'languages' => [
			'list' => [
				'pt' => [ 'locale' => 'pt', 'slug' => 'pt', 'active' => true, 'default' => true ],
				'ja' => [ 'locale' => 'ja', 'slug' => 'ja', 'active' => true, 'default' => false ],
				'ar' => [ 'locale' => 'ar', 'slug' => 'ar', 'active' => true, 'default' => false ],
			],
		],
	];
}

function miss_request( array $params ): WP_REST_Request {
	return new WP_REST_Request( 'POST', '/translatewp/v1/translation/miss', $params, [], '' );
}

function page_map_request( array $params ): WP_REST_Request {
	return new WP_REST_Request( 'GET', '/translatewp/v1/translation/page-map', $params, [], '' );
}

// The addon's title lookup queues this exact miss: the bare page title as a
// single segment, keyed to the page URL, with the attribute context kind the
// TranslatesStrings trait defaults to for SEO strings.
function title_miss_item( string $title ): array {
	return [
		'groupId'       => 'twp-pro-' . substr( md5( 'seo:title|' . $title ), 0, 20 ),
		'markerPayload' => '⟪twp_pro_0001⟫' . $title,
		'contextKey'    => 'seo:title',
		'contextKind'   => 'attribute',
	];
}

// The server-recomputed hash of the queued Arabic title miss row.
function queued_title_hash(): string {
	$hash = '';
	foreach ( $GLOBALS['wp_test_inserted_posts'] ?? [] as $post ) {
		if ( 'ar' !== (string) ( $post['meta_input']['_twp_language'] ?? '' )
			|| 'miss' !== (string) ( $post['meta_input']['_twp_object_type'] ?? '' ) ) {
			continue;
		}
		$payload = json_decode( IndexQueue::decode_payload( (string) ( $post['post_content'] ?? '' ) ), true );
		$hash    = (string) ( $payload[0]['cache']['hash'] ?? '' );
	}

	return $hash;
}

// ---------------------------------------------------------------------------
// Section A: the live sequence. A page is already indexed in Japanese (title
// works) and its Arabic body is indexed. The Arabic title, a server-only
// segment, reaches the store only through the addon miss path: report_misses ->
// drain -> record -> back-fill -> read. The canonical URL resolves to the page,
// so the title shares the page's post-keyed Arabic entry with the body.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$post_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Horário da loja', 'post_status' => 'publish' ] );
$perm    = 'https://example.com/horario-da-loja/';
$canon   = TranslationUrl::canonicalize( $perm );

$GLOBALS['wp_test_permalinks'][ $post_id ]    = $perm;
$GLOBALS['wp_test_post_type_map'][ $post_id ] = 'page';
$GLOBALS['wp_test_queried_object_id']         = $post_id;
$GLOBALS['wp_test_url_to_postid']             = [ $perm => $post_id, $canon => $post_id ];

$sync = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$jobs = new TranslationJobs( 'translatewp', TRANSLATEWP_VERSION );

// The Arabic body was warmed first with the real post id (object_type page).
TranslationStore::record_pending( $perm, $post_id, 'ar', 'pt', [
	[ 'hash' => 'h-it-body-1', 'markerPayload' => '⟪item_0001⟫Novidades' ],
	[ 'hash' => 'h-it-body-2', 'markerPayload' => '⟪item_0002⟫Horário semanal' ],
] );
$GLOBALS['wp_test_cache_lookup_items']['h-it-body-1'] = '⟪item_0001⟫أخبار';
$GLOBALS['wp_test_cache_lookup_items']['h-it-body-2'] = '⟪item_0002⟫جدول أسبوعي';
TranslationStore::run_backfill_batch( 25 );

// The Japanese title works today (recorded the same way the Arabic title will be).
TranslationStore::record_pending( $perm, $post_id, 'ja', 'pt', [
	[ 'hash' => 'h-de-title', 'markerPayload' => '⟪item_0001⟫Horário da loja' ],
] );
$GLOBALS['wp_test_cache_lookup_items']['h-de-title'] = '⟪item_0001⟫営業時間';
TranslationStore::run_backfill_batch( 25 );
assert_true(
	'stored' === TranslationStore::title_state( $perm, 'Horário da loja', 'ja', $post_id ),
	'Precondition: the Japanese title reads as stored'
);
assert_true(
	'missing' === TranslationStore::title_state( $perm, 'Horário da loja', 'ar', $post_id ),
	'Precondition: the Arabic title is not yet stored'
);

// The Arabic title miss: the addon read nothing, so it queued a miss carrying
// the page URL and the bare title, exactly as TranslationLookup::queue_miss does.
$accepted = $jobs->report_misses( miss_request( [
	'language' => 'ar',
	'url'      => $perm,
	'items'    => [ title_miss_item( 'Horário da loja' ) ],
] ) );
assert_true(
	! is_wp_error( $accepted ) && 1 === ( $accepted['accepted'] ?? 0 ),
	'The Arabic title miss must be accepted, got ' . wp_json_encode( is_wp_error( $accepted ) ? $accepted->get_error_code() : $accepted )
);
$title_hash = queued_title_hash();
assert_true( '' !== $title_hash, 'The queued Arabic title miss must carry a server-recomputed hash' );

// Drain the queued title row: the push announces it and the store records it as
// pending for the page and Arabic.
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION );
$drain   = $indexer->drain_tick();
assert_true( 1 === ( $drain['processed'] ?? 0 ), 'The title miss row must drain, got ' . wp_json_encode( $drain ) );

// Self-check (page-map): the title reads as pending before the back-fill lands.
$page_map = new TranslationPageMap( 'translatewp', TRANSLATEWP_VERSION );
$pending_view = $page_map->page_map( page_map_request( [ 'url' => $perm, 'lang' => 'ar' ] ) );
assert_true(
	! is_wp_error( $pending_view ) && 'pending' === ( $pending_view['titleState'] ?? '' ),
	'page-map must report the Arabic title as pending after the drain, got ' . wp_json_encode( is_wp_error( $pending_view ) ? $pending_view->get_error_code() : ( $pending_view['titleState'] ?? null ) )
);

// The title is cached; the back-fill resolves it.
$GLOBALS['wp_test_cache_lookup_items'][ $title_hash ] = '⟪twp_pro_0001⟫Horário da loja (AR)';
$sync->run_backfill();

// The read the Yoast title filter performs (the bare post title on this page and
// locale) now returns the translated title.
$read = TranslationStore::translated_string( 'Horário da loja', 'Horário da loja', $perm, 'ar', $post_id );
assert_true(
	'Horário da loja (AR)' === $read,
	'The Arabic title must resolve once the back-fill catches up, got ' . wp_json_encode( $read )
);
assert_true(
	'営業時間' === TranslationStore::translated_string( 'Horário da loja', 'Horário da loja', $perm, 'ja', $post_id ),
	'The Japanese title must be unaffected'
);
assert_true(
	'أخبار' === TranslationStore::translated_string( 'Novidades', 'Novidades', $perm, 'ar', $post_id ),
	'The Arabic body must be unaffected'
);

// Self-check (page-map): the title now reads as stored.
$stored_view = $page_map->page_map( page_map_request( [ 'url' => $perm, 'lang' => 'ar' ] ) );
assert_true(
	! is_wp_error( $stored_view ) && 'stored' === ( $stored_view['titleState'] ?? '' ),
	'page-map must report the Arabic title as stored after the back-fill, got ' . wp_json_encode( is_wp_error( $stored_view ) ? $stored_view->get_error_code() : ( $stored_view['titleState'] ?? null ) )
);

// Self-check (Pages table): the per-language entry carries the title state.
$indexing  = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );
$pages_view = $indexing->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
$ar_entry   = $pages_view['pages'][0]['languages']['ar'] ?? null;
assert_true(
	is_array( $ar_entry ) && 'stored' === ( $ar_entry['title'] ?? '' ),
	'The Pages table Arabic entry must expose the title state, got ' . wp_json_encode( $ar_entry )
);

// ---------------------------------------------------------------------------
// Section B: the failing link (pending cap). The page body saturates the
// per-locale pending list (MAX_PENDING_HASHES) in the shared entry; a later
// title-only append must still be tracked, not dropped, so its back-fill lands.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$pid   = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Horário da loja', 'post_status' => 'publish' ] );
$perm2 = 'https://example.com/horario-da-loja/';
$c2    = TranslationUrl::canonicalize( $perm2 );
$GLOBALS['wp_test_permalinks'][ $pid ] = $perm2;
$GLOBALS['wp_test_url_to_postid']      = [ $perm2 => $pid, $c2 => $pid ];

$body = [];
for ( $i = 1; $i <= 130; $i++ ) {
	$body[] = [ 'hash' => 'h-body-' . $i, 'markerPayload' => '⟪item_0001⟫Body ' . $i ];
}
TranslationStore::record_pending( $perm2, $pid, 'ar', 'pt', $body );

// The title append lands in the same post-keyed entry (canonical resolves here).
TranslationStore::record_pending( $c2, 0, 'ar', 'pt', [
	[ 'hash' => 'h-title-b', 'markerPayload' => '⟪twp_pro_0001⟫Horário da loja' ],
] );
$GLOBALS['wp_test_cache_lookup_items']['h-title-b'] = '⟪twp_pro_0001⟫Horário da loja (AR)';
TranslationStore::run_backfill_batch( 25 );
assert_true(
	'Horário da loja (AR)' === TranslationStore::translated_string( 'Horário da loja', 'Horário da loja', $perm2, 'ar', $pid ),
	'A title append must survive a body that saturates the pending list, got '
		. wp_json_encode( TranslationStore::translated_string( 'Horário da loja', 'Horário da loja', $perm2, 'ar', $pid ) )
);

// ---------------------------------------------------------------------------
// Section C: the failing link (map cap). The page body fills the per-locale
// stored map (MAX_MAP_ENTRIES); a later title resolution must still land,
// never be dropped while its pending hash is cleared as "resolved".
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$pid3  = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Horário da loja', 'post_status' => 'publish' ] );
$perm3 = 'https://example.com/horario-da-loja/';
$c3    = TranslationUrl::canonicalize( $perm3 );
$GLOBALS['wp_test_permalinks'][ $pid3 ] = $perm3;
$GLOBALS['wp_test_url_to_postid']       = [ $perm3 => $pid3, $c3 => $pid3 ];

$added = 0;
while ( $added < 400 ) {
	$segs = [];
	for ( $i = 0; $i < 100 && $added < 400; $i++ ) {
		$added++;
		$hash = 'h-map-' . $added;
		$segs[] = [ 'hash' => $hash, 'markerPayload' => '⟪item_0001⟫S' . $added ];
		$GLOBALS['wp_test_cache_lookup_items'][ $hash ] = '⟪item_0001⟫T' . $added;
	}
	TranslationStore::record_pending( $perm3, $pid3, 'ar', 'pt', $segs );
	TranslationStore::run_backfill_batch( 25 );
}
assert_true(
	400 === TranslationStore::segment_count( $perm3, 'ar', $pid3 ),
	'Precondition: the Arabic map is full at its cap, got ' . TranslationStore::segment_count( $perm3, 'ar', $pid3 )
);

TranslationStore::record_pending( $c3, 0, 'ar', 'pt', [
	[ 'hash' => 'h-title-c', 'markerPayload' => '⟪twp_pro_0001⟫Horário da loja' ],
] );
$GLOBALS['wp_test_cache_lookup_items']['h-title-c'] = '⟪twp_pro_0001⟫Horário da loja (AR)';
TranslationStore::run_backfill_batch( 25 );
assert_true(
	'Horário da loja (AR)' === TranslationStore::translated_string( 'Horário da loja', 'Horário da loja', $perm3, 'ar', $pid3 ),
	'A title resolution must land even when the body has filled the map, got '
		. wp_json_encode( TranslationStore::translated_string( 'Horário da loja', 'Horário da loja', $perm3, 'ar', $pid3 ) )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "LateLocaleTitleTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LateLocaleTitleTest: all assertions passed\n";
