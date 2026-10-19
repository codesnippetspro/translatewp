<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\TranslationUrl;
use TranslateWP\Functionality\Endpoints\TranslationJobs;
use TranslateWP\Functionality\Endpoints\TranslationPageMap;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\TranslationStoreSync;

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
		'site'      => [
			'id'    => '00000000-0000-4000-8000-000000000001',
			'token' => 'secret-site-token',
		],
		'languages' => [
			'list' => [
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'es' => [ 'locale' => 'es', 'slug' => 'es', 'active' => true, 'default' => false ],
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

// The live defect: a visitor miss is recorded in a REST/cron context where
// url_to_postid() resolves to 0, so the store keys the finished translation by
// canonical URL. page-map (which reads by URL) then returns the full map, yet
// the server-rendered SEO <title> reads by the queried post id and finds
// nothing. A read must resolve the entry under both keys.
$source_title       = 'Downtown Summer Music Festival 2026';
$translated_title   = 'Festival de Música de Verano del Centro 2026';
$page_url_with_lang = 'https://example.com/events/summer-festival-2026/?lang=es';
$canonical_url      = TranslationUrl::canonicalize( $page_url_with_lang );

wp_test_reset( connected_options() );

$sync = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$jobs = new TranslationJobs( 'translatewp', TRANSLATEWP_VERSION );

// The post exists and is the queried object on the front-end read, but
// url_to_postid() is deliberately NOT registered for its URL, so the drain that
// records the miss cannot resolve the URL back to the post - exactly the live
// REST/cron context that keys the write by URL.
$post_id   = (int) wp_insert_post( [ 'post_type' => 'post', 'post_title' => $source_title, 'post_status' => 'publish' ] );
$permalink = 'https://example.com/events/summer-festival-2026/';
$GLOBALS['wp_test_permalinks'][ $post_id ] = $permalink;

$accepted = $jobs->report_misses(
	miss_request(
		[
			'language' => 'es',
			'url'      => $page_url_with_lang,
			'items'    => [
				[
					'groupId'       => 'group-title',
					'markerPayload' => '⟪item_0001⟫' . $source_title,
					'contextKey'    => 'seo:title',
					'contextKind'   => TranslationFingerprint::CONTEXT_KIND_ATTRIBUTE,
				],
			],
		]
	)
);
assert_true( ! is_wp_error( $accepted ) && 1 === ( $accepted['accepted'] ?? 0 ), 'The title miss must be accepted, got ' . wp_json_encode( $accepted ) );

$miss_rows = array_values(
	array_filter(
		$GLOBALS['wp_test_inserted_posts'] ?? [],
		static fn( $post ): bool => 'miss' === ( $post['meta_input']['_twp_object_type'] ?? '' )
	)
);
assert_true( 1 === count( $miss_rows ), 'Exactly one miss row must be queued, got ' . count( $miss_rows ) );
$queued_hash = (string) ( json_decode( IndexQueue::decode_payload( (string) ( $miss_rows[0]['post_content'] ?? '' ) ), true )[0]['cache']['hash'] ?? '' );
assert_true( '' !== $queued_hash, 'The queued miss must carry a fingerprint hash' );

$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION );
$indexer->drain_tick();

// The write landed under the canonical URL key because url_to_postid() is 0 here.
assert_true( 1 === TranslationStore::pending_count( $canonical_url, 'es' ), 'The miss must be recorded pending under the canonical URL' );

$GLOBALS['wp_test_cache_lookup_items'][ $queued_hash ] = '⟪item_0001⟫' . $translated_title;
$sync->run_backfill();
assert_true( 0 === TranslationStore::pending_count( $canonical_url, 'es' ), 'The back-fill must clear the pending hash' );

// page-map (URL-keyed) sees the full map, mirroring the live GET page-map result.
$page_map     = new TranslationPageMap( 'translatewp', TRANSLATEWP_VERSION );
$map_response = $page_map->page_map( page_map_request( [ 'url' => $page_url_with_lang, 'lang' => 'es' ] ) );
assert_true(
	! is_wp_error( $map_response ) && $translated_title === ( $map_response['map'][ $source_title ] ?? '' ),
	'page-map must return the back-filled title, got ' . wp_json_encode( is_wp_error( $map_response ) ? $map_response->get_error_code() : ( $map_response['map'] ?? null ) )
);

// The server-rendered SEO read: a singular request whose queried object id is
// the post, its permalink carrying the trailing slash. The store must resolve
// the URL-keyed entry even though the read carries the post id.
$GLOBALS['wp_test_queried_object_id'] = $post_id;

$direct = TranslationStore::translated_string( $source_title, $source_title, $permalink, 'es', $post_id );
assert_true(
	$translated_title === $direct,
	'A post-keyed read must resolve the URL-keyed translation, got ' . wp_json_encode( $direct )
);

$seam = apply_filters( 'translatewp_pro_translated_string', $source_title, $source_title, 'es', $post_id );
assert_true(
	$translated_title === $seam,
	'The Pro seam must return the stored title for the queried singular page, got ' . wp_json_encode( $seam )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreReadPathTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "StoreReadPathTest: all assertions passed\n";
