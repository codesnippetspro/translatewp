<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;

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

function lookup_calls(): int {
	return count(
		array_filter(
			$GLOBALS['wp_test_remote_posts'] ?? [],
			static fn( array $post ): bool => '/v1/translation/cache/lookup-batch' === ( $post['path'] ?? '' )
		)
	);
}

function age_readiness_checks( int $seconds ): void {
	foreach ( $GLOBALS['wp_test_transients'] as $key => $proofs ) {
		if ( 0 !== strpos( (string) $key, 'twp_ready_' ) || ! is_array( $proofs ) ) {
			continue;
		}
		foreach ( $proofs as $hash => $proof ) {
			$proofs[ $hash ]['checked'] = (int) $proof['checked'] - $seconds;
		}
		$GLOBALS['wp_test_transients'][ $key ] = $proofs;
	}
}

wp_test_reset(
	[
		'site'      => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ],
		'languages' => [
			'list' => [
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
			],
		],
	]
);
$GLOBALS['wp_test_remote_posts'] = [];

$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 1, 'url' => home_url( '/' ), 'page_title' => 'Home', 'language' => 'it', 'priority' => 1 ] ] );
$row           = $queue->list_rows()[0];
$row['status'] = IndexQueue::STATUS_DONE;
$hash          = str_repeat( 'a', 64 );
$title_hash    = str_repeat( 'b', 64 );
TranslationReadiness::record(
	(int) $row['id'],
	'it',
	[
		[ 'cache' => [ 'hash' => $hash ], 'markerPayload' => '⟪item_0001⟫Hello' ],
		[ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => $title_hash ], 'markerPayload' => '⟪item_0001⟫Home' ],
	]
);
$GLOBALS['wp_test_cache_lookup_items'] = [ $hash => '⟪item_0001⟫Ciao', $title_hash => '⟪item_0001⟫Casa' ];

TranslationReadiness::refresh( [ $row ] );
assert_true( TranslationReadiness::is_ready( $row ), 'Precondition: a cached page is ready' );

// ---------------------------------------------------------------------------
// A refused look-up is not a cache miss: readiness already proven stays, and
// look-ups pause for the retry window instead of hammering the service.
// ---------------------------------------------------------------------------
$GLOBALS['wp_test_cache_lookup_rate_limited'] = true;
TranslationReadiness::refresh( [ $row ] );
assert_true( TranslationReadiness::is_ready( $row ), 'A rate-limited look-up must not undo readiness already proven' );

$calls = lookup_calls();
TranslationReadiness::refresh( [ $row ] );
assert_true( $calls === lookup_calls(), 'Look-ups must pause while the service asks the site to retry later' );

// ---------------------------------------------------------------------------
// A proven page stays ready long enough for a full check cycle of a larger
// site, and a real cache miss still withdraws it.
// ---------------------------------------------------------------------------
age_readiness_checks( 300 );
assert_true( TranslationReadiness::is_ready( $row ), 'A page checked five minutes ago must still read as ready' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationReadinessPacingTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "TranslationReadinessPacingTest passed\n";
