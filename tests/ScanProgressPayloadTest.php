<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;

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
		'site' => [
			'id'    => '00000000-0000-4000-8000-0000000000aa',
			'token' => 'secret-site-token',
		],
	];
}

function seed_two_pages(): void {
	wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 1 => 'page', 2 => 'page' ] );
	$GLOBALS['wp_test_inserted_posts'] = [
		[
			'ID'           => 1,
			'post_type'    => 'page',
			'post_title'   => 'Front page',
			'post_excerpt' => '',
			'post_content' => 'Welcome.',
			'post_status'  => 'publish',
		],
		[
			'ID'           => 2,
			'post_type'    => 'page',
			'post_title'   => 'About us',
			'post_excerpt' => '',
			'post_content' => 'Our story.',
			'post_status'  => 'publish',
		],
	];
	$GLOBALS['wp_test_posts']['page'] = [ 1, 2 ];
}

// ---------------------------------------------------------------------------
// The status payload carries the page-level scan progress: pages_total,
// pages_done and the page being read right now.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_two_pages();
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );

// Before anything is queued the scan block is empty but present.
$status = $indexer->status();
assert_true( isset( $status['scan'] ), 'The status payload must carry the scan block' );
assert_true( 0 === $status['scan']['pages_total'] && 0 === $status['scan']['pages_done'], 'An empty scan reads zero of zero, got ' . wp_json_encode( $status['scan'] ) );
assert_true( null === $status['scan']['current_page'], 'An empty scan names no current page' );

// Queued rows: two pages to read, none done.
$queued = $indexer->enqueue_read_rows();
assert_true( 2 === $queued, 'Both pages must be queued for reading, got ' . $queued );
$status = $indexer->status();
assert_true( 2 === $status['scan']['pages_total'] && 0 === $status['scan']['pages_done'], 'A queued scan must count two pages of total, got ' . wp_json_encode( $status['scan'] ) );

// The rows are claimed (the harness returns both): the first claimed read
// row is the current page.
$rows = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( 1 <= count( $rows ), 'A read row must be claimable' );
$status = $indexer->status();
assert_true( 2 === $status['scan']['pages_total'] && 0 === $status['scan']['pages_done'], 'A claimed row is not done, got ' . wp_json_encode( $status['scan'] ) );
assert_true( is_array( $status['scan']['current_page'] ), 'A claimed read row must be the current page' );
assert_true( '' !== $status['scan']['current_page']['title'] || '' !== $status['scan']['current_page']['path'], 'The current page must carry a title or a path, got ' . wp_json_encode( $status['scan']['current_page'] ) );
assert_true( is_string( $status['scan']['current_page']['title'] ) && is_string( $status['scan']['current_page']['path'] ), 'The current page fields must be strings' );

// The first claimed row completes: one page done, the still-claimed row
// stays current.
assert_true( $indexer->submit_extraction( (int) $rows[0]['id'], [], true ), 'The read extraction must be stored' );
$status = $indexer->status();
assert_true( 2 === $status['scan']['pages_total'] && 1 === $status['scan']['pages_done'], 'One finished row must count one page done, got ' . wp_json_encode( $status['scan'] ) );
assert_true( is_array( $status['scan']['current_page'] ), 'With a claim still live the claimed page stays current' );

// Both done: the scan reports two of two, and the newest finished page is
// current because no claim is live.
assert_true( $indexer->submit_extraction( (int) $rows[1]['id'], [], true ), 'The second read extraction must be stored' );
$status = $indexer->status();
assert_true( 2 === $status['scan']['pages_total'] && 2 === $status['scan']['pages_done'], 'A finished scan reads two of two, got ' . wp_json_encode( $status['scan'] ) );
assert_true( is_array( $status['scan']['current_page'] ), 'With no claim the newest finished page stays current' );

// Nothing is left to claim.
$third = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( [] === $third, 'No third row exists to claim' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ScanProgressPayloadTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}
echo "ScanProgressPayloadTest: all assertions passed\n";