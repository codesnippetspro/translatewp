<?php
/**
 * The pacing of a first pass: how long the queue waits between runs, how much
 * readiness proves per pass, whether it re-asks for segments already proven,
 * how often an unattended site re-checks, and how quickly the store back-fill
 * empties. Every one of these is filterable so a site running its first pass
 * can go faster than a settled site needs to.
 */

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;

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

/** One page row of `$count` segments, already accepted by the service. */
function pacing_row( IndexQueue $queue, string $language, int $count, string $url ): array {
	$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => crc32( $url . $language ), 'url' => $url, 'page_title' => 'Page', 'language' => $language, 'priority' => 1 ] ] );
	$rows = $queue->list_rows();
	$row  = end( $rows );
	$items = [];
	for ( $index = 0; $index < $count; $index++ ) {
		$items[] = [
			'groupId'       => 'g' . $index,
			'cache'         => [ 'hash' => str_pad( dechex( crc32( $url . $language . $index ) ), 64, '0' ) ],
			'markerPayload' => '⟪item_0001⟫Text ' . $index,
		];
	}
	// A page with a title only counts once its title is recorded with it.
	$items[] = [
		'groupId'       => 'seo:seo:title',
		'cache'         => [ 'hash' => str_pad( dechex( crc32( $url . $language . 'title' ) ), 64, '0' ) ],
		'markerPayload' => '⟪item_0001⟫Page',
	];
	TranslationReadiness::record( (int) $row['id'], $language, $items );
	$row['status'] = IndexQueue::STATUS_DONE;

	return [ 'row' => $row, 'items' => $items ];
}

/* -------------------------------------------------------------------------
 * The queue's own pacing
 * ---------------------------------------------------------------------- */

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );

assert_true( Indexer::BATCH_SIZE === Indexer::drain_batch_size(), 'Without a filter the queue claims its usual number of rows' );
assert_true( Indexer::RESCHEDULE_DELAY_SECONDS === Indexer::drain_reschedule_delay(), 'Without a filter the queue waits its usual time before the next run' );

add_filter( 'translatewp_drain_batch_size', static fn(): int => 40 );
add_filter( 'translatewp_drain_reschedule_delay', static fn(): int => 0 );
add_filter( 'translatewp_drain_tick_budget', static fn(): int => 45 );

assert_true( 40 === Indexer::drain_batch_size(), 'A site can claim more rows in one run' );
assert_true( 0 === Indexer::drain_reschedule_delay(), 'A site can run the queue again immediately while work is left' );
assert_true( 45.0 === Indexer::drain_tick_budget(), 'A site can give one run longer before it releases what it has not started' );

remove_filter( 'translatewp_drain_batch_size' );
remove_filter( 'translatewp_drain_reschedule_delay' );
remove_filter( 'translatewp_drain_tick_budget' );

assert_true( 0 < Indexer::drain_batch_size(), 'A nonsense filter cannot claim fewer than one row' );
add_filter( 'translatewp_drain_batch_size', static fn(): int => -5 );
assert_true( 1 === Indexer::drain_batch_size(), 'A negative row count falls back to one row' );
remove_filter( 'translatewp_drain_batch_size' );

/* -------------------------------------------------------------------------
 * Proving readiness: how much per pass, and what is asked for twice
 * ---------------------------------------------------------------------- */

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue = new IndexQueue();
$first = pacing_row( $queue, 'fr', 150, 'https://example.com/one' );

$asked = [];
$answer_everything = static function ( string $language, array $hashes ) use ( &$asked ): array {
	$asked[] = $hashes;
	$items = [];
	foreach ( $hashes as $hash ) {
		$items[ $hash ] = '⟪item_0001⟫Texte';
	}

	return $items;
};

// One pass covers one batch by default; 150 segments therefore need two.
$asked = [];
TranslationReadiness::refresh( [ $first['row'] ], 0, $answer_everything );
assert_true( 1 === count( $asked ), 'One pass asks for one batch by default, got ' . count( $asked ) );
assert_true( ! TranslationReadiness::is_ready( $first['row'] ), 'A page is not ready while a batch of its segments is unproven' );

add_filter( 'translatewp_readiness_batches_per_pass', static fn(): int => 8 );
$asked = [];
TranslationReadiness::refresh( [ $first['row'] ], 0, $answer_everything );
assert_true( count( $asked ) > 1, 'A pass can prove several batches, got ' . count( $asked ) );
assert_true( TranslationReadiness::is_ready( $first['row'] ), 'Proving every batch in one pass makes the page ready' );

// With every segment proven, a further pass asks for them all over again
// unless the site opts out of re-asking.
$asked = [];
TranslationReadiness::refresh( [ $first['row'] ], 0, $answer_everything );
$repeated = array_sum( array_map( 'count', $asked ) );
assert_true( $repeated > 0, 'By default a proven segment is asked for again, keeping an evicted entry noticeable' );

add_filter( 'translatewp_readiness_skip_proven', static fn(): bool => true );
$asked = [];
TranslationReadiness::refresh( [ $first['row'] ], 0, $answer_everything );
assert_true( 0 === array_sum( array_map( 'count', $asked ) ), 'A site can leave proven segments out of the next look-up' );
assert_true( TranslationReadiness::is_ready( $first['row'] ), 'Skipping the look-up keeps the page ready on its existing proof' );

remove_filter( 'translatewp_readiness_batches_per_pass' );
remove_filter( 'translatewp_readiness_skip_proven' );

/* -------------------------------------------------------------------------
 * How often an unattended site re-checks
 * ---------------------------------------------------------------------- */

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$reconcile = new ReadinessReconcile( 'translate', '1.0' );
$reconcile->maybe_schedule();
$first_check = get_transient( ReadinessReconcile::CHECK_TRANSIENT );
assert_true( false !== $first_check, 'A check is recorded so the next one waits' );

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
add_filter( 'translatewp_readiness_reconcile_interval', static fn(): int => 30 );
$reconcile = new ReadinessReconcile( 'translate', '1.0' );
$reconcile->maybe_schedule();
assert_true(
	30 === ( ( $GLOBALS['wp_test_transient_expirations'][ ReadinessReconcile::CHECK_TRANSIENT ] ?? 0 ) - time() ),
	'A site with work outstanding can re-check far sooner than the settled interval'
);
remove_filter( 'translatewp_readiness_reconcile_interval' );

/* -------------------------------------------------------------------------
 * Skipping the look-up must not make a settled page flicker not-ready
 * ---------------------------------------------------------------------- */

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue = new IndexQueue();
$steady = pacing_row( $queue, 'de', 150, 'https://example.com/steady' );

/** The one stored proof map for this test's language (excludes the backoff key). */
function pacing_proof_key(): string {
	foreach ( array_keys( $GLOBALS['wp_test_transients'] ) as $key ) {
		if ( 0 === strpos( $key, 'twp_ready_' ) && 'twp_ready_backoff' !== $key ) {
			return $key;
		}
	}
	return '';
}
function pacing_age_proofs( int $seconds ): void {
	$key = pacing_proof_key();
	$map = $GLOBALS['wp_test_transients'][ $key ] ?? [];
	foreach ( $map as $hash => $proof ) {
		$map[ $hash ]['checked'] = time() - $seconds;
	}
	$GLOBALS['wp_test_transients'][ $key ] = $map;
}

add_filter( 'translatewp_readiness_batches_per_pass', static fn(): int => 8 );
$asked = [];
TranslationReadiness::refresh( [ $steady['row'] ], 0, $answer_everything );
assert_true( TranslationReadiness::is_ready( $steady['row'] ), 'A fully proven page is ready' );

add_filter( 'translatewp_readiness_skip_proven', static fn(): bool => true );

// A pass that never touches this page's hashes (a different page's batch)
// must not erase its proofs early.
$other = pacing_row( $queue, 'de', 1, 'https://example.com/other' );
$asked = [];
TranslationReadiness::refresh( [ $other['row'] ], 0, $answer_everything );
assert_true( TranslationReadiness::is_ready( $steady['row'] ), 'An untouched page keeps its proof when another page is reconciled' );

// Aging the proof past the base window alone must not flip the page not-ready
// while skip_proven is on: reconcile has not yet had its next turn to re-verify it.
pacing_age_proofs( 605 );
assert_true( TranslationReadiness::is_ready( $steady['row'] ), 'A page stays ready past the base window while awaiting re-verification' );

// Age alone never withdraws a proof: only a look-up that reports the segment
// missing, or a scope change, does.
pacing_age_proofs( 1805 );
assert_true( TranslationReadiness::is_ready( $steady['row'] ), 'A page stays ready however old its proof is' );

// A pass that actually re-verifies the page's segments restores readiness
// with a fresh timestamp.
$asked = [];
TranslationReadiness::refresh( [ $steady['row'] ], 0, $answer_everything );
assert_true( TranslationReadiness::is_ready( $steady['row'] ), 'Re-verifying the segments makes the page ready again' );

// The stored proof map must outlive the base window too, or a language whose
// turn comes late in the round-robin loses its whole map to transient expiry.
$expires_in = ( $GLOBALS['wp_test_transient_expirations'][ pacing_proof_key() ] ?? 0 ) - time();
assert_true( 1800 === $expires_in, 'The proof map is stored for the grace window, got ' . $expires_in . 's' );
$GLOBALS['wp_test_transient_expirations'][ pacing_proof_key() ] -= 601;
assert_true( TranslationReadiness::is_ready( $steady['row'] ), 'A page stays ready when its language was last rewritten over 600s ago' );

remove_filter( 'translatewp_readiness_skip_proven' );
remove_filter( 'translatewp_readiness_batches_per_pass' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FirstPassPacingTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "FirstPassPacingTest: all assertions passed\n";
