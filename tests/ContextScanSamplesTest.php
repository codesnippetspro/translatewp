<?php

declare(strict_types=1);

// What the context scan sends: the text of no more pages than its page cap,
// each text once, and on a Free site it creates no Lexicon entry.

use TranslateWP\Functionality\Endpoints\Scanning;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function seed_pages( array $contents ): void {
	$posts = [];
	foreach ( array_values( $contents ) as $index => $content ) {
		$posts[] = [
			'ID'           => $index + 1,
			'post_type'    => 'page',
			'post_title'   => '',
			'post_excerpt' => '',
			'post_content' => $content,
			'post_status'  => 'publish',
		];
	}
	$GLOBALS['wp_test_inserted_posts'] = $posts;
	$GLOBALS['wp_test_posts']['page']  = array_column( $posts, 'ID' );
}

function sent_samples( array $contents ): array {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ] ] );
	seed_pages( $contents );
	( new Scanning( 'translatewp', TRANSLATEWP_VERSION ) )->run_scan();

	$request = end( $GLOBALS['wp_test_remote_posts'] );

	return is_array( $request ) ? (array) ( $request['body']['samples'] ?? [] ) : [];
}

// --- The page cap ---
$many = [];
for ( $page = 1; $page <= 40; $page++ ) {
	$many[] = 'Page number ' . $page . ' has its own text.';
}
$samples = sent_samples( $many );
assert_true( Scanning::SCAN_PAGE_CAP === count( $samples ), 'A site with more pages than the cap must send exactly the cap, got ' . count( $samples ) );
assert_true( count( $samples ) === count( array_unique( $samples ) ), 'No page text may be sent twice' );

// --- The cap is 25 pages ---
assert_true( 25 === Scanning::SCAN_PAGE_CAP, 'The setup reading step reads at most 25 pages, got ' . Scanning::SCAN_PAGE_CAP );
$forty = [];
for ( $page = 1; $page <= 40; $page++ ) {
	$forty[] = 'Page number ' . $page . ' has its own text.';
}
assert_true( 25 === count( sent_samples( $forty ) ), 'A forty-page site must send twenty-five pages, got ' . count( sent_samples( $forty ) ) );

// --- Long pages share the request: every page within the cap is read, none is crowded out ---
$long = [];
for ( $page = 1; $page <= 25; $page++ ) {
	$long[] = 'Page ' . $page . ' ' . str_repeat( 'has a great deal to say. ', 600 );
}
$samples = sent_samples( $long );
assert_true( 25 === count( $samples ), 'Twenty-five long pages must all be sent, got ' . count( $samples ) );
assert_true( array_sum( array_map( 'strlen', $samples ) ) <= Scanning::SCAN_PAYLOAD_MAX_BYTES, 'The request stays within its byte bound' );
assert_true( max( array_map( 'strlen', $samples ) ) <= Scanning::SCAN_SAMPLE_MAX_BYTES, 'No sample is larger than the sample bound' );

// --- A few long pages still get the full sample bound each ---
$samples = sent_samples( array_slice( $long, 0, 3 ) );
assert_true( 3 === count( $samples ) && strlen( $samples[0] ) > 8000, 'Three long pages keep the full sample size, got ' . strlen( $samples[0] ?? '' ) );

// --- Fewer pages than the cap: every page once ---
$samples = sent_samples( [ 'First page.', 'Second page.', 'Third page.' ] );
assert_true( 3 === count( $samples ), 'A three-page site must send three samples, got ' . count( $samples ) );

// --- Pages with the same text are sent once ---
$samples = sent_samples( [ 'Coming soon.', 'Coming soon.', '  Coming soon.  ', 'Opening hours.' ] );
sort( $samples );
assert_true( [ 'Coming soon.', 'Opening hours.' ] === $samples, 'Identical page texts must be sent once, got ' . wp_json_encode( $samples ) );

// --- A Free site's scan creates no Lexicon entry ---
// The scan service answers with lexicon candidates; they must stay candidates.
sent_samples( [ 'We bake sourdough.' ] );
assert_true( [] === ( $GLOBALS['wp_test_lexicon_posts'] ?? [] ), 'A Free scan must create no Lexicon entry' );
$lexicon_inserts = array_filter(
	$GLOBALS['wp_test_inserted_posts'] ?? [],
	static fn( array $post ): bool => 'lexicon' === ( $post['post_type'] ?? '' )
);
assert_true( [] === $lexicon_inserts, 'A Free scan must insert no Lexicon post' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ContextScanSamplesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ContextScanSamplesTest: all assertions passed\n";
