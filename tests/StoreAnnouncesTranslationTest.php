<?php

declare(strict_types=1);

// Once every pending segment of a page in a language is stored, the store
// announces that page once, with its source address and the language. The
// Pro addon listens to tell search engines about the new translation.

use TranslateWP\Components\TranslationStore;

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

function announcements(): array {
	return array_values(
		array_filter(
			$GLOBALS['wp_test_actions_fired'] ?? [],
			static fn( array $fired ): bool => 'translatewp_pro_translation_stored' === $fired['tag']
		)
	);
}

// The lookup answers only the hashes it is given a translation for.
$lookup = static function ( array $known ): callable {
	return static function ( string $locale, array $hashes ) use ( $known ): array {
		unset( $locale );
		return array_intersect_key( $known, array_flip( $hashes ) );
	};
};

wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );
\TranslateWP\Components\Options::update(
	'languages.list',
	[
		'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
		'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
	]
);
$GLOBALS['wp_test_actions_fired'] = [];
$page_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Rooms', 'post_status' => 'publish' ] );
$source  = 'https://example.com/rooms/';
$GLOBALS['wp_test_url_to_postid'][ $source ] = $page_id;

TranslationStore::record_pending(
	'https://example.com/de/rooms/',
	0,
	'de',
	'en',
	[
		[ 'hash' => 'h1', 'markerPayload' => '⟪item_0001⟫Rooms' ],
		[ 'hash' => 'h2', 'markerPayload' => '⟪item_0001⟫Breakfast' ],
	]
);

// --- Half the page stored: nothing is announced yet ---
TranslationStore::resolve_pending( 'https://example.com/de/rooms/', 0, 'de', $lookup( [ 'h1' => '⟪item_0001⟫Zimmer' ] ) );
assert_true( [] === announcements(), 'A page with segments still pending must not be announced' );

// --- The last segment stored: the page is announced once, by its source address ---
TranslationStore::resolve_pending( 'https://example.com/de/rooms/', 0, 'de', $lookup( [ 'h2' => '⟪item_0001⟫Frühstück' ] ) );
$fired = announcements();
assert_true( 1 === count( $fired ), 'A fully stored page must be announced once, got ' . count( $fired ) );
assert_true( [ $source, 'de' ] === ( $fired[0]['args'] ?? [] ), 'The announcement carries the source address and the language, got ' . wp_json_encode( $fired[0]['args'] ?? null ) );

// --- Nothing left to resolve: nothing more is announced ---
TranslationStore::resolve_pending( 'https://example.com/de/rooms/', 0, 'de', $lookup( [ 'h2' => '⟪item_0001⟫Frühstück' ] ) );
assert_true( 1 === count( announcements() ), 'A page with nothing pending is not announced again' );

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreAnnouncesTranslationTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "StoreAnnouncesTranslationTest: all assertions passed\n";
