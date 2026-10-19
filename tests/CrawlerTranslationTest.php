<?php

declare(strict_types=1);

// The complete translation of a page for a crawler: every segment the site
// read from the page is fingerprinted for the language and looked up in the
// service's cache. Any segment the cache does not hold yet means no map.

use TranslateWP\Components\CrawlerTranslation;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxySettings;

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

const PAGE_ID = 7;

$items = [
	[ 'groupId' => 'g1', 'markerPayload' => "⟪item_0001⟫\n\tSkip to content\t", 'contextKind' => 'text-flow' ],
	[ 'groupId' => 'g2', 'markerPayload' => '⟪item_0001⟫Call us on ⟪item_0002⟫or write to us.', 'contextKind' => 'text-flow' ],
];

function seed_read_page( array $items ): void {
	wp_test_reset(
		[
			'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
			'languages' => [
				'list' => [
					'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
					'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				],
			],
		]
	);
	$GLOBALS['wp_test_post_type_map'] = [ PAGE_ID => 'page' ];
	$queue = new IndexQueue();
	$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => PAGE_ID, 'url' => 'https://example.com/contact/', 'priority' => 1 ] ] );
	$claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
	$queue->append_extraction( (int) $claimed[0]['id'], $items, true );
	CrawlerTranslation::forget( PAGE_ID, 'de' );
}

function hash_of( array $item ): string {
	return TranslationFingerprint::compute_hash(
		(string) $item['markerPayload'],
		TranslationProxySettings::default_locale(),
		'de',
		(string) $item['contextKind'],
		TranslationProxySettings::ai_settings( 'de' )
	);
}

// --- Every segment in the cache: the whole page's source -> translation map ---
seed_read_page( $items );
$asked  = [];
$answer = [
	hash_of( $items[0] ) => '⟪item_0001⟫Zum Inhalt springen',
	hash_of( $items[1] ) => '⟪item_0001⟫Rufen Sie uns an unter ⟪item_0002⟫oder schreiben Sie uns.',
];
$lookup = static function ( string $locale, array $hashes ) use ( &$asked, &$answer ) {
	$asked[] = [ $locale, $hashes ];
	return array_intersect_key( $answer, array_flip( $hashes ) );
};
$map = CrawlerTranslation::map( PAGE_ID, 'de', $lookup );
assert_true(
	[ 'Skip to content' => 'Zum Inhalt springen', 'Call us on' => 'Rufen Sie uns an unter', 'or write to us.' => 'oder schreiben Sie uns.' ] === $map,
	'A fully cached page gives its whole map, each text trimmed, got ' . wp_json_encode( $map )
);
assert_true( 1 === count( $asked ) && 'de' === $asked[0][0] && 2 === count( $asked[0][1] ), 'Every fingerprint of the page is looked up in one call for the language' );

// --- The answer is kept for a while, so the next crawler request asks nothing ---
$asked = [];
assert_true( $map === CrawlerTranslation::map( PAGE_ID, 'de', $lookup ) && [] === $asked, 'A complete map is reused without another lookup' );

// --- One segment the cache does not hold: no map ---
seed_read_page( $items );
unset( $answer[ hash_of( $items[1] ) ] );
assert_true( [] === CrawlerTranslation::map( PAGE_ID, 'de', $lookup ), 'A page with a segment not yet translated gives no map' );
$asked = [];
assert_true( [] === CrawlerTranslation::map( PAGE_ID, 'de', $lookup ) && [] === $asked, 'An incomplete page is not looked up again straight away' );

// --- The lookup fails: no map ---
seed_read_page( $items );
assert_true( [] === CrawlerTranslation::map( PAGE_ID, 'de', static fn() => new WP_Error( 'http', 'down' ) ), 'A failed lookup gives no map' );

// --- A page the site never read: no map and no lookup ---
seed_read_page( $items );
$asked = [];
assert_true( [] === CrawlerTranslation::map( 999, 'de', $lookup ) && [] === $asked, 'A page that was never read gives no map and asks nothing' );

// --- More segments than one call takes are asked for in batches ---
$many = [];
for ( $n = 1; $n <= 230; $n++ ) {
	$many[] = [ 'groupId' => 'g' . $n, 'markerPayload' => '⟪item_0001⟫Line number ' . $n, 'contextKind' => 'text-flow' ];
}
seed_read_page( $many );
$asked = [];
$all   = static function ( string $locale, array $hashes ) use ( &$asked ) {
	$asked[] = count( $hashes );
	return array_fill_keys( $hashes, '⟪item_0001⟫Zeile' );
};
assert_true( 230 === count( CrawlerTranslation::map( PAGE_ID, 'de', $all ) ), 'A long page gives its whole map' );
assert_true( [ 100, 100, 30 ] === $asked, 'Fingerprints are looked up at most 100 at a time, got ' . wp_json_encode( $asked ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "CrawlerTranslationTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "CrawlerTranslationTest: all assertions passed\n";
