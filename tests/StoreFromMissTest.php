<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\IndexQueue;
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
				'en' => [
					'locale'  => 'en',
					'slug'    => 'en',
					'active'  => true,
					'default' => true,
				],
				'es' => [
					'locale'  => 'es',
					'slug'    => 'es',
					'active'  => true,
					'default' => false,
				],
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

$valid_item = [
	'groupId'       => 'group-1',
	'markerPayload' => '⟪item_0001⟫Shop the collection',
	'contextKey'    => 'div:nth-of-type(1) > p:nth-of-type(1)',
	'contextKind'   => 'text-flow',
];

// The visitor is on the German-parameter URL; the store must key the page by
// its canonical form (lang stripped, trailing slash trimmed).
$page_url_with_lang = 'https://example.com/collection/?lang=es';
$canonical_url      = TranslationUrl::canonicalize( $page_url_with_lang );

// --- Steps 2 + 3: a miss carrying a URL records a pending segment for that URL ---
wp_test_reset( connected_options() );
$sync    = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$jobs    = new TranslationJobs( 'translatewp', TRANSLATEWP_VERSION );
$accepted = $jobs->report_misses( miss_request( [
	'language' => 'es',
	'url'      => $page_url_with_lang,
	'items'    => [ $valid_item ],
] ) );
assert_true( ! is_wp_error( $accepted ) && 1 === ( $accepted['accepted'] ?? 0 ), 'A miss carrying a URL must be accepted, got ' . wp_json_encode( $accepted ) );

$queued = $GLOBALS['wp_test_inserted_posts'] ?? [];
assert_true( 1 === count( $queued ), 'Exactly one miss row must be queued' );
assert_true( $canonical_url === ( $queued[0]['meta_input']['_twp_url'] ?? '' ), 'The queued miss row must carry the canonical page URL, got ' . wp_json_encode( $queued[0]['meta_input']['_twp_url'] ?? null ) );

$queued_payload = json_decode( IndexQueue::decode_payload( (string) ( $queued[0]['post_content'] ?? '' ) ), true );
$queued_hash    = (string) ( $queued_payload[0]['cache']['hash'] ?? '' );
assert_true( '' !== $queued_hash, 'The queued miss row must carry a server-recomputed hash' );

// Drain the queued miss row: the push announces it, and the store records the
// segment as pending keyed by the page URL (miss rows are no longer skipped).
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION );
$drain   = $indexer->drain_tick();
assert_true( 1 === ( $drain['processed'] ?? 0 ), 'The miss row must drain, got ' . wp_json_encode( $drain ) );

$pushed = array_values( array_filter(
	$GLOBALS['wp_test_actions_fired'] ?? [],
	static fn( array $event ): bool => 'translatewp_index_rows_pushed' === $event['tag']
) );
assert_true( 1 === count( $pushed ), 'The drain must announce the pushed miss row once' );

assert_true( 1 === TranslationStore::pending_count( $canonical_url, 'es' ), 'The miss segment must be recorded as pending for the canonical URL, got ' . TranslationStore::pending_count( $canonical_url, 'es' ) );
assert_true( true === TranslationStore::has_pending_work(), 'Recording the miss segment must enqueue back-fill work' );
assert_true( [] === TranslationStore::for_url( $canonical_url, 'es' ), 'The map must be empty until the back-fill resolves the hash' );

// --- Back-fill + page-map: the map is served after a drain completes and the
//     back-fill runs, and page-map answers the lang-parameter URL. ---
$GLOBALS['wp_test_cache_lookup_items'][ $queued_hash ] = '⟪item_0001⟫Compra la colección';
$sync->run_backfill();

assert_true( 0 === TranslationStore::pending_count( $canonical_url, 'es' ), 'The back-fill must clear the pending hash' );

$page_map = new TranslationPageMap( 'translatewp', TRANSLATEWP_VERSION );
$response = $page_map->page_map( page_map_request( [
	'url'  => $page_url_with_lang,
	'lang' => 'es',
] ) );
assert_true( ! is_wp_error( $response ), 'page-map must answer a configured language, got ' . ( is_wp_error( $response ) ? $response->get_error_code() : 'ok' ) );
assert_true( $canonical_url === ( $response['url'] ?? '' ), 'page-map must echo the canonical URL, got ' . wp_json_encode( $response['url'] ?? null ) );
assert_true(
	[ 'Shop the collection' => 'Compra la colección' ] === ( $response['map'] ?? null ),
	'page-map must return the back-filled map, got ' . wp_json_encode( $response['map'] ?? null )
);
assert_true( 0 === ( $response['pendingCount'] ?? -1 ), 'page-map must report no pending segments once the back-fill resolves' );

// --- The page-map query resolves whether or not the lang parameter is present ---
$response_bare = $page_map->page_map( page_map_request( [
	'url'  => 'https://example.com/collection',
	'lang' => 'es',
] ) );
assert_true(
	! is_wp_error( $response_bare ) && [ 'Shop the collection' => 'Compra la colección' ] === ( $response_bare['map'] ?? null ),
	'page-map must resolve the same entry without the lang parameter, got ' . wp_json_encode( is_wp_error( $response_bare ) ? $response_bare->get_error_code() : ( $response_bare['map'] ?? null ) )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreFromMissTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "StoreFromMissTest: all assertions passed\n";
