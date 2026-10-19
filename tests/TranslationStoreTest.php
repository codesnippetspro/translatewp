<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationMarkerPayload;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
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

function make_post( string $post_type = 'page', string $title = 'Untitled' ): int {
	return (int) wp_insert_post(
		[
			'post_type'   => $post_type,
			'post_title'  => $title,
			'post_status' => 'publish',
			'meta_input'  => [],
		]
	);
}

$en_de = static function ( string $de ): callable {
	return static function ( string $locale, array $hashes ) use ( $de ): array {
		unset( $locale );
		$items = [];
		foreach ( $hashes as $hash ) {
			$items[ $hash ] = $de;
		}
		return $items;
	};
};

// --- Marker payload parses into id => text pairs ---
$parsed = TranslationMarkerPayload::parse( '⟪item_0001⟫About us⟪item_0002⟫Welcome home' );
assert_true(
	[ 'item_0001' => 'About us', 'item_0002' => 'Welcome home' ] === $parsed,
	'Marker payload must split into id => text, got ' . wp_json_encode( $parsed )
);
assert_true( [] === TranslationMarkerPayload::parse( '' ), 'Empty marker payload parses to nothing' );

// --- Post-meta path: record pending, resolve from cache, read the map ---
wp_test_reset( connected_options() );
$post_id = make_post( 'page', 'About' );
$about_url = 'https://example.com/about/';
$GLOBALS['wp_test_url_to_postid'][ $about_url ] = $post_id;

assert_true(
	TranslationStore::record_pending(
		$about_url,
		$post_id,
		'de_DE',
		'en_US',
		[ [ 'hash' => 'h1', 'markerPayload' => '⟪item_0001⟫About us⟪item_0002⟫Welcome' ] ]
	),
	'record_pending must accept a valid segment'
);
assert_true( 1 === TranslationStore::pending_count( $about_url, 'de_DE' ), 'One hash must be pending after record' );
assert_true( [] === TranslationStore::for_url( $about_url, 'de_DE' ), 'Map must be empty before resolve' );

// The hash is held in flight (as the indexer holds it while its job runs).
assert_true( true === \TranslateWP\Components\TranslationProxyDedupe::claim_segment( \TranslateWP\Components\Sites::registered_id(), 'de_DE', 'h1' ), 'The pending hash can be claimed before it resolves' );

$resolved = TranslationStore::resolve_pending(
	$about_url,
	$post_id,
	'de_DE',
	static function ( string $locale, array $hashes ): array {
		unset( $locale, $hashes );
		return [ 'h1' => '⟪item_0001⟫Über uns⟪item_0002⟫Willkommen' ];
	}
);
assert_true( 1 === $resolved, 'resolve_pending must report one hash resolved, got ' . $resolved );
assert_true( true === \TranslateWP\Components\TranslationProxyDedupe::claim_segment( \TranslateWP\Components\Sites::registered_id(), 'de_DE', 'h1' ), 'A resolved hash releases its in-flight claim' );
\TranslateWP\Components\TranslationProxyDedupe::release_segment( \TranslateWP\Components\Sites::registered_id(), 'de_DE', 'h1' );
assert_true( 0 === TranslationStore::pending_count( $about_url, 'de_DE' ), 'Resolved hash must leave the pending set' );

$map = TranslationStore::for_url( $about_url, 'de_DE' );
assert_true(
	[ 'About us' => 'Über uns', 'Welcome' => 'Willkommen' ] === $map,
	'Map must pair source to translated per segment, got ' . wp_json_encode( $map )
);

// --- Seam resolver: exact, normalised, and miss ---
assert_true(
	'Über uns' === TranslationStore::translated_string( 'About us', 'About us', $about_url, 'de_DE', $post_id ),
	'Exact source must return its translation'
);
assert_true(
	'Über uns' === TranslationStore::translated_string( "  About   us\n", "  About   us\n", $about_url, 'de_DE', $post_id ),
	'Whitespace-different source must still match after normalisation'
);
assert_true(
	'Missing' === TranslationStore::translated_string( 'Missing', 'Missing', $about_url, 'de_DE', $post_id ),
	'An unknown source must return the passed value unchanged'
);
assert_true(
	'Welcome' === TranslationStore::translated_string( 'Welcome', 'Welcome', $about_url, 'en_US', $post_id ),
	'A locale with no entry must pass the value through unchanged'
);

// --- Per-locale isolation on the same post ---
TranslationStore::record_pending( $about_url, $post_id, 'fr_FR', 'en_US', [ [ 'hash' => 'h1', 'markerPayload' => '⟪item_0001⟫About us' ] ] );
TranslationStore::resolve_pending( $about_url, $post_id, 'fr_FR', $en_de( '⟪item_0001⟫À propos' ) );
assert_true( 'À propos' === TranslationStore::translated_string( 'About us', 'About us', $about_url, 'fr_FR', $post_id ), 'French entry must resolve independently' );
assert_true( 'Über uns' === TranslationStore::translated_string( 'About us', 'About us', $about_url, 'de_DE', $post_id ), 'German entry must be untouched by the French write' );

// --- Non-post URL path uses the bounded option, not post meta ---
wp_test_reset( connected_options() );
$blog_url = 'https://example.com/blog/';
TranslationStore::record_pending( $blog_url, 0, 'fr_FR', 'en_US', [ [ 'hash' => 'h2', 'markerPayload' => '⟪item_0001⟫Latest posts' ] ] );
TranslationStore::resolve_pending( $blog_url, 0, 'fr_FR', $en_de( '⟪item_0001⟫Derniers articles' ) );
$blog_map = TranslationStore::for_url( $blog_url, 'fr_FR' );
assert_true( [ 'Latest posts' => 'Derniers articles' ] === $blog_map, 'Non-post URL map must persist in the option, got ' . wp_json_encode( $blog_map ) );
assert_true( is_array( get_option( TranslationStore::URL_OPTION ) ), 'URL option must hold the non-post entry' );

// --- Static front page: home URL and object id resolve to the same entry ---
wp_test_reset( connected_options() );
$front_id = make_post( 'page', 'Home' );
update_option( 'page_on_front', $front_id );
TranslationStore::record_pending( 'https://example.com/', 0, 'de_DE', 'en_US', [ [ 'hash' => 'h3', 'markerPayload' => '⟪item_0001⟫Home' ] ] );
TranslationStore::resolve_pending( 'https://example.com/', 0, 'de_DE', $en_de( '⟪item_0001⟫Startseite' ) );
$by_url    = TranslationStore::for_url( 'https://example.com/', 'de_DE' );
$by_object = TranslationStore::for_url( 'https://irrelevant.example/', 'de_DE', $front_id );
assert_true( [ 'Home' => 'Startseite' ] === $by_url, 'Front page read by home URL must find the entry, got ' . wp_json_encode( $by_url ) );
assert_true( $by_url === $by_object, 'Front page read by queried object id must find the same entry' );

// --- remote_lookup + run_backfill_batch through the API cache stub ---
wp_test_reset( connected_options() );
$contact_id  = make_post( 'page', 'Contact' );
$contact_url = 'https://example.com/contact/';
$GLOBALS['wp_test_url_to_postid'][ $contact_url ] = $contact_id;
TranslationStore::record_pending( $contact_url, $contact_id, 'de_DE', 'en_US', [ [ 'hash' => 'h4', 'markerPayload' => '⟪item_0001⟫Contact' ] ] );
$GLOBALS['wp_test_cache_lookup_items']['h4'] = '⟪item_0001⟫Kontakt';

$backfill = TranslationStore::run_backfill_batch( 10 );
assert_true( 1 === $backfill['resolved'] && 0 === $backfill['remaining'], 'Back-fill must resolve the work item, got ' . wp_json_encode( $backfill ) );
assert_true( [ 'Contact' => 'Kontakt' ] === TranslationStore::for_url( $contact_url, 'de_DE' ), 'Back-fill must write the map from the API cache' );

$lookup_calls = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => '/v1/translation/cache/lookup-batch' === $post['path'] ) );
assert_true( 1 === count( $lookup_calls ), 'Back-fill must call the cache lookup once' );
assert_true( true === ( $lookup_calls[0]['body']['hydration'] ?? false ), 'Back-fill lookup must set hydration so demand is not inflated' );
assert_true( '00000000-0000-4000-8000-000000000001' === ( $lookup_calls[0]['body']['siteId'] ?? '' ), 'Back-fill lookup must carry the site id' );
assert_true( false === TranslationStore::has_pending_work(), 'A fully resolved queue must report no pending work' );

// --- A cache miss keeps the hash pending for a later pass ---
wp_test_reset( connected_options() );
$miss_id  = make_post( 'page', 'Pricing' );
$miss_url = 'https://example.com/pricing/';
$GLOBALS['wp_test_url_to_postid'][ $miss_url ] = $miss_id;
TranslationStore::record_pending( $miss_url, $miss_id, 'de_DE', 'en_US', [ [ 'hash' => 'h5', 'markerPayload' => '⟪item_0001⟫Pricing' ] ] );
$first = TranslationStore::run_backfill_batch( 10 );
assert_true( 0 === $first['resolved'], 'A cache miss must resolve nothing' );
assert_true( 1 === TranslationStore::pending_count( $miss_url, 'de_DE' ), 'A missed hash must stay pending' );
$GLOBALS['wp_test_cache_lookup_items']['h5'] = '⟪item_0001⟫Preise';
$second = TranslationStore::run_backfill_batch( 10 );
assert_true( 1 === $second['resolved'], 'A later pass must resolve once the cache is warm' );
assert_true( 'Preise' === TranslationStore::translated_string( 'Pricing', 'Pricing', $miss_url, 'de_DE', $miss_id ), 'The later pass must fill the map' );

// --- Sync records pushed rows and skips miss-proxy rows; the seam filter reads it ---
wp_test_reset( connected_options() );
$news_id  = make_post( 'post', 'News' );
$news_url = 'https://example.com/news/';
$GLOBALS['wp_test_url_to_postid'][ $news_url ] = $news_id;
$sync = new TranslationStoreSync( TRANSLATEWP_NAME, TRANSLATEWP_VERSION );
$sync->record_pushed_rows(
	'de_DE',
	'en_US',
	[
		[
			'url'         => $news_url,
			'object_type' => 'post',
			'object_id'   => $news_id,
			'items'       => [
				[ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Read more', 'cache' => [ 'hash' => 'h6', 'siteId' => '00000000-0000-4000-8000-000000000001' ] ],
			],
		],
		[
			'url'         => '',
			'object_type' => 'miss',
			'object_id'   => 0,
			'items'       => [
				[ 'groupId' => 'gm', 'markerPayload' => '⟪item_0001⟫Ignored', 'cache' => [ 'hash' => 'hm', 'siteId' => '00000000-0000-4000-8000-000000000001' ] ],
			],
		],
	]
);
assert_true( 1 === TranslationStore::pending_count( $news_url, 'de_DE' ), 'Sync must record the real page row as pending' );
assert_true( true === TranslationStore::has_pending_work(), 'Recording must enqueue back-fill work' );

$GLOBALS['wp_test_cache_lookup_items']['h6'] = '⟪item_0001⟫Weiterlesen';
$GLOBALS['wp_test_cache_lookup_items']['hm'] = '⟪item_0001⟫Should not appear';
$sync->run_backfill();

$GLOBALS['wp_test_queried_object_id'] = $news_id;
$GLOBALS['wp_test_permalinks']        = [ $news_id => $news_url ];
$seam = apply_filters( 'translatewp_pro_translated_string', 'Read more', 'Read more', 'de_DE', 'meta' );
assert_true( 'Weiterlesen' === $seam, 'Seam filter must return the stored translation for the queried page, got ' . wp_json_encode( $seam ) );
$seam_miss = apply_filters( 'translatewp_pro_translated_string', 'Ignored', 'Ignored', 'de_DE', 'meta' );
assert_true( 'Ignored' === $seam_miss, 'Miss-proxy content must not leak into a page map' );
unset( $GLOBALS['wp_test_permalinks'] );

// --- Segments with no translatable text are dropped, valid ones kept ---
wp_test_reset( connected_options() );
$mix_id  = make_post( 'page', 'Mixed' );
$mix_url = 'https://example.com/mixed/';
$GLOBALS['wp_test_url_to_postid'][ $mix_url ] = $mix_id;
TranslationStore::record_pending( $mix_url, $mix_id, 'de_DE', 'en_US', [ [ 'hash' => 'h7', 'markerPayload' => '⟪item_0001⟫Save⟪item_0002⟫   ' ] ] );
TranslationStore::resolve_pending( $mix_url, $mix_id, 'de_DE', $en_de( '⟪item_0001⟫Speichern⟪item_0002⟫   ' ) );
$mix_map = TranslationStore::for_url( $mix_url, 'de_DE' );
assert_true( [ 'Save' => 'Speichern' ] === $mix_map, 'Whitespace-only segments must be dropped, got ' . wp_json_encode( $mix_map ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationStoreTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslationStoreTest: all assertions passed\n";
