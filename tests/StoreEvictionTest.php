<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\TranslationUrl;

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
	return [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ];
}

// Resolve any batch of pending hashes to one fixed translated payload.
$answer = static function ( string $payload ): callable {
	return static function ( string $locale, array $hashes ) use ( $payload ): array {
		unset( $locale );
		$items = [];
		foreach ( $hashes as $hash ) {
			$items[ $hash ] = $payload;
		}
		return $items;
	};
};

// A resolvable page reached through a ?lang switcher URL: the miss path forces
// the object id to 0, so the store must resolve the post back from the URL. The
// untranslated permalink resolves (it is a real page); the ?lang form did not
// before the fix, which stranded the page in the bounded option.
wp_test_reset( connected_options() );
$rooms_id    = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Rooms', 'post_status' => 'publish' ] );
$rooms_clean = 'https://example.com/rooms/';
$rooms_de    = $rooms_clean . '?lang=de';
$rooms_it    = $rooms_clean . '?lang=it';
$GLOBALS['wp_test_url_to_postid'][ $rooms_clean ] = $rooms_id;

// German filled early, Italian later, both via the miss path (object id 0).
TranslationStore::record_pending( $rooms_de, 0, 'de', 'en', [ [ 'hash' => 'rd', 'markerPayload' => '⟪item_0001⟫Rooms' ] ] );
TranslationStore::resolve_pending( $rooms_de, 0, 'de', $answer( '⟪item_0001⟫Zimmer' ) );
TranslationStore::record_pending( $rooms_it, 0, 'it', 'en', [ [ 'hash' => 'ri', 'markerPayload' => '⟪item_0001⟫Rooms' ] ] );
TranslationStore::resolve_pending( $rooms_it, 0, 'it', $answer( '⟪item_0001⟫Camere' ) );

// Both locales must live in post meta (unbounded per post and locale), never in
// the bounded option where flood traffic could evict them.
$post_meta = get_post_meta( $rooms_id, TranslationStore::POST_META_KEY, true );
assert_true(
	is_array( $post_meta ) && 'Zimmer' === ( $post_meta['de']['map']['Rooms'] ?? '' ) && 'Camere' === ( $post_meta['it']['map']['Rooms'] ?? '' ),
	'A resolvable page reached via a ?lang URL must store every locale in post meta'
);
$rooms_key = md5( TranslationUrl::canonicalize( $rooms_clean ) );
assert_true(
	! isset( get_option( TranslationStore::URL_OPTION, [] )[ $rooms_key ] ),
	'A resolvable page must not occupy a bounded URL-option slot'
);

// Backdate every pre-flood option entry: if the page had (wrongly) landed in the
// option, it would now be the oldest and the flood below would evict it first.
$stale = get_option( TranslationStore::URL_OPTION, [] );
foreach ( $stale as $stale_key => $stale_entry ) {
	$stale[ $stale_key ]['updated_at'] = 1;
}
if ( $stale ) {
	update_option( TranslationStore::URL_OPTION, $stale );
}

// Flood the bounded option past its cap with pages that do not resolve to a post.
$bound = (int) ( new ReflectionClassConstant( TranslationStore::class, 'MAX_URL_ENTRIES' ) )->getValue();
for ( $i = 0; $i <= $bound + 5; $i++ ) {
	$url = "https://example.com/ephemeral-{$i}/";
	TranslationStore::record_pending( $url, 0, 'it', 'en', [ [ 'hash' => "e{$i}", 'markerPayload' => '⟪item_0001⟫Ephemeral' ] ] );
	TranslationStore::resolve_pending( $url, 0, 'it', $answer( '⟪item_0001⟫Voruebergehend' ) );
}

assert_true(
	count( get_option( TranslationStore::URL_OPTION, [] ) ) <= $bound,
	'The bounded option must never exceed its per-URL cap, got ' . count( get_option( TranslationStore::URL_OPTION, [] ) )
);

// The earlier German locale on the post-backed page survives the flood because
// post meta is unbounded; the later Italian locale survives with it.
$GLOBALS['wp_test_queried_object_id'] = $rooms_id;
assert_true(
	'Zimmer' === TranslationStore::translated_string( 'Rooms', 'Rooms', $rooms_clean, 'de', $rooms_id ),
	'The earlier German locale on a post-backed page must survive flooding the option past its cap'
);
assert_true(
	'Camere' === TranslationStore::translated_string( 'Rooms', 'Rooms', $rooms_clean, 'it', $rooms_id ),
	'The later Italian locale must survive the flood too'
);

// Per-URL eviction, never a whole-locale wipe: a URL that keeps two locales and
// stays recent keeps both; dropping the cap drops whole URL entries, not a
// locale across URLs.
$multi_url = 'https://example.com/ephemeral-0/';
TranslationStore::record_pending( $multi_url, 0, 'fr', 'en', [ [ 'hash' => 'mf', 'markerPayload' => '⟪item_0001⟫Ephemeral' ] ] );
TranslationStore::resolve_pending( $multi_url, 0, 'fr', $answer( '⟪item_0001⟫Temporaire' ) );
assert_true(
	'Voruebergehend' === TranslationStore::translated_string( 'Ephemeral', 'Ephemeral', $multi_url, 'it' )
		&& 'Temporaire' === TranslationStore::translated_string( 'Ephemeral', 'Ephemeral', $multi_url, 'fr' ),
	'Adding a locale to a recent URL entry must not wipe its other locale'
);

// A miss repopulates an evicted page on the next visit.
$evicted_url = '';
for ( $i = $bound + 5; $i >= 0; $i-- ) {
	$candidate = "https://example.com/ephemeral-{$i}/";
	if ( [] === TranslationStore::for_url( $candidate, 'it' ) ) {
		$evicted_url = $candidate;
		break;
	}
}
assert_true( '' !== $evicted_url, 'Flooding past the cap must evict at least one URL entry' );

// Age the surviving entries so the repopulating write is unambiguously the
// newest. In production its fresh timestamp does this; in a fast test every
// write shares one second, so without this the cap would drop the new entry
// again on the same tick.
$aged = get_option( TranslationStore::URL_OPTION, [] );
foreach ( $aged as $aged_key => $aged_entry ) {
	$aged[ $aged_key ]['updated_at'] = 1;
}
if ( $aged ) {
	update_option( TranslationStore::URL_OPTION, $aged );
}

TranslationStore::record_pending( $evicted_url, 0, 'it', 'en', [ [ 'hash' => 'again', 'markerPayload' => '⟪item_0001⟫Ephemeral' ] ] );
TranslationStore::resolve_pending( $evicted_url, 0, 'it', $answer( '⟪item_0001⟫Voruebergehend' ) );
assert_true(
	'Voruebergehend' === TranslationStore::translated_string( 'Ephemeral', 'Ephemeral', $evicted_url, 'it' ),
	'A fresh miss must repopulate an evicted page on the next visit'
);

// clear_entry still clears both storage keys and returns the dropped pending
// hashes (PR #112 semantics), now resolving a ?lang URL to its post meta.
wp_test_reset( connected_options() );
$clear_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Suites', 'post_status' => 'publish' ] );
$clear_clean = 'https://example.com/suites/';
$GLOBALS['wp_test_url_to_postid'][ $clear_clean ] = $clear_id;
TranslationStore::record_pending( $clear_clean . '?lang=de', 0, 'de', 'en', [ [ 'hash' => 'ch', 'markerPayload' => '⟪item_0001⟫Suites' ] ] );
$dropped = TranslationStore::clear_entry( $clear_clean . '?lang=de', 0, 'de' );
assert_true( [ 'ch' ] === $dropped, 'clear_entry must return the dropped pending hashes, got ' . wp_json_encode( $dropped ) );
assert_true(
	0 === TranslationStore::pending_count( $clear_clean, 'de', $clear_id ),
	'clear_entry must clear the post-meta entry for a ?lang URL'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreEvictionTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "StoreEvictionTest: all assertions passed\n";
