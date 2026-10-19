<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Functionality\Endpoints\Indexing;
use TranslateWP\Functionality\Endpoints\Scanning;
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
		'site'      => [
			'id'    => '00000000-0000-4000-8000-0000000000aa',
			'token' => 'secret-site-token',
		],
		'languages' => [
			'list' => [
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
			],
		],
	];
}

function seed_front_page(): void {
	wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 1 => 'page' ] );
	$GLOBALS['wp_test_inserted_posts'] = [
		[
			'ID'           => 1,
			'post_type'    => 'page',
			'post_title'   => 'Front page',
			'post_excerpt' => '',
			'post_content' => 'Shop the collection.',
			'post_status'  => 'publish',
		],
	];
	$GLOBALS['wp_test_posts']['page'] = [ 1 ];
}

function rows_by_language(): array {
	$by = [];
	foreach ( $GLOBALS['wp_test_inserted_posts'] ?? [] as $post ) {
		if ( ( $post['post_type'] ?? '' ) !== 'twp_index_item' ) {
			continue;
		}
		$language = (string) ( $post['meta_input']['_twp_language'] ?? '' );
		$by[ $language ] = ( $by[ $language ] ?? 0 ) + 1;
	}
	return $by;
}

// ---------------------------------------------------------------------------
// Reading the site queues one language-less row per page and, once the tab
// submits the extraction, the row rests in the read stage with its payload:
// nothing is pushed, no language row exists.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_front_page();
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );

$queued = $indexer->enqueue_read_rows();
assert_true( $queued >= 1, 'Reading the site must queue at least the front page, got ' . $queued );
$rows = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( 1 === count( $rows ) && IndexQueue::READ_LANGUAGE === $rows[0]['language'], 'The read row carries no language, got ' . wp_json_encode( array_column( $rows, 'language' ) ) );

$items = [
	[ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Shop the collection.', 'contextKey' => 'main > p', 'contextKind' => 'text-flow', 'cache' => [ 'siteId' => 'x', 'hash' => 'browser-hash' ] ],
	[ 'groupId' => 'g2', 'markerPayload' => '⟪item_0001⟫Read more', 'contextKey' => 'main > a', 'contextKind' => 'text-flow', 'cache' => [ 'siteId' => 'x', 'hash' => 'browser-hash-2' ] ],
];
assert_true( $indexer->submit_extraction( (int) $rows[0]['id'], $items, true ), 'The read extraction must be stored' );
$read = $queue->read_rows();
assert_true( 1 === count( $read ) && 2 === count( $read[0]['items'] ), 'The read row must hold the two extracted items, got ' . wp_json_encode( $read ) );
assert_true( [] === $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 ), 'Nothing is pushed before languages are chosen' );
$counts = $queue->read_counts();
assert_true( 1 === $counts['pages'] && 2 === $counts['segments'] && 0 === $counts['pending'], 'Read counts must report one page and two segments, got ' . wp_json_encode( $counts ) );
assert_true( [] === $queue->counts(), 'The read row must not appear as a language, got ' . wp_json_encode( $queue->counts() ) );
$inventory = ( new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer ) )->pages( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/pages', [], [], '' ) );
assert_true( 1 === count( $inventory['pages'] ) && [] === $inventory['pages'][0]['languages'], 'The scanned inventory must be visible before language synchronization creates rows' );
$status = $indexer->status();
assert_true( isset( $status['read']['pages'] ) && 1 === $status['read']['pages'], 'The status must carry the read counts' );

// ---------------------------------------------------------------------------
// Starting translation fans the read page out into one push row per target
// language, each item carrying the fingerprint for that language, kicks the
// drain, and leaves the source language alone.
// ---------------------------------------------------------------------------
$GLOBALS['wp_test_remote_posts'] = [];
$queued = $indexer->start_translation( [ 'en', 'de', 'it' ] );
assert_true( 2 === $queued, 'Two languages must be fanned out, got ' . $queued );
$push_rows = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$languages = array_column( $push_rows, 'language' );
sort( $languages );
assert_true( [ 'de', 'it' ] === $languages, 'Push rows must exist for German and Italian only, got ' . wp_json_encode( $languages ) );

$de_row  = null;
foreach ( $push_rows as $row ) {
	if ( 'de' === $row['language'] ) {
		$de_row = $row;
	}
}
$de_items = json_decode( (string) $de_row['payload'], true );
$expected = TranslationFingerprint::compute_hash( '⟪item_0001⟫Shop the collection.', 'en', 'de', 'text-flow', TranslationProxySettings::ai_settings( 'de' ) );
assert_true( is_array( $de_items ) && 2 === count( $de_items ), 'The German row carries both items, got ' . wp_json_encode( $de_items ) );
assert_true( $expected === ( $de_items[0]['cache']['hash'] ?? '' ), 'The German item hash must be recomputed for German, got ' . wp_json_encode( $de_items[0]['cache'] ?? null ) );
assert_true( '00000000-0000-4000-8000-0000000000aa' === ( $de_items[0]['cache']['siteId'] ?? '' ), 'The cache reference names this site' );
assert_true( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Starting translation must schedule the drain' );
$kicks = array_filter( $GLOBALS['wp_test_actions_fired'] ?? [], static fn( $fired ) => 'translatewp_loopback_kick' === ( $fired['tag'] ?? '' ) );
assert_true( [] !== $kicks, 'Starting translation must fire the loopback kick' );

// Releasing the claims and starting again adds nothing: live rows are kept.
foreach ( $push_rows as $row ) {
	$queue->release( (int) $row['id'] );
}
assert_true( 0 === $indexer->start_translation( [ 'de', 'it' ] ), 'A second start must not duplicate live rows' );
// A new language fans out just that language.
assert_true( 1 === $indexer->start_translation( [ 'fr' ] ), 'Adding a language fans out that language only' );

// ---------------------------------------------------------------------------
// The loopback kick verifies only a fresh signature from this site.
// ---------------------------------------------------------------------------
$timestamp = (string) time();
$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', "drain\n" . $timestamp, hash( 'sha256', 'secret-site-token' ), true ) ), '+/', '-_' ), '=' );
assert_true( LoopbackKick::verify( $timestamp, $signature ), 'A fresh kick signed with the site key must verify' );
$stale_timestamp = (string) ( time() - 600 );
$stale_signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', "drain\n" . $stale_timestamp, hash( 'sha256', 'secret-site-token' ), true ) ), '+/', '-_' ), '=' );
assert_true( ! LoopbackKick::verify( $stale_timestamp, $stale_signature ), 'A correctly signed but stale kick must not verify' );
assert_true( ! LoopbackKick::verify( $timestamp, 'wrong' ), 'A wrong signature must not verify' );
$endpoint = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );
assert_true( true === $endpoint->drain_now_permissions_check( new WP_REST_Request( 'POST', '/translatewp/v1/indexing/drain-now', [ 'ts' => $timestamp, 'sig' => $signature ], [], '' ) ), 'The drain-now route accepts the site\'s own kick' );
assert_true( false === $endpoint->drain_now_permissions_check( new WP_REST_Request( 'POST', '/translatewp/v1/indexing/drain-now', [ 'ts' => $timestamp, 'sig' => 'nope' ], [], '' ) ), 'The drain-now route refuses a foreign kick' );

// ---------------------------------------------------------------------------
// A site with nothing read falls back to per-language extraction.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_front_page();
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$queued  = $indexer->start_translation( [ 'de' ] );
assert_true( $queued >= 1, 'With nothing read, starting translation queues the per-language extraction, got ' . $queued );
$rows = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( 1 === count( $rows ) && 'de' === $rows[0]['language'], 'The fallback row carries the language, got ' . wp_json_encode( array_column( $rows, 'language' ) ) );

// ---------------------------------------------------------------------------
// The scan reports its progress and proposes lexicon terms to a Pro site;
// the terms land only through apply-lexicon.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_front_page();
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true, 'shape' => 2 ], 3600 );
update_option( \TranslateWP\Components\TranslationStore::URL_OPTION, [ 'abc' => [ 'url' => 'https://example.com/', 'post_id' => 1 ] ] );
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->scan_context( new WP_REST_Request( 'POST', '/translatewp/v1/scanning/context', [], [], '' ) );
$status = $scanning->scan_status( new WP_REST_Request( 'GET', '/translatewp/v1/scanning/context/status', [], [], '' ) );
assert_true( in_array( $status['status'], [ 'pending', 'done' ], true ), 'The status route answers pending or done' );
if ( 'pending' === $status['status'] ) {
	assert_true( isset( $status['phase'], $status['sampled'], $status['total'], $status['elapsedSeconds'] ), 'A pending scan reports its progress, got ' . wp_json_encode( $status ) );
	$scanning->run_scan();
	$status = $scanning->scan_status( new WP_REST_Request( 'GET', '/translatewp/v1/scanning/context/status', [], [], '' ) );
}
assert_true( 'done' === $status['status'] && false === $status['applied'], 'A scan on a translated site is a proposal, got ' . wp_json_encode( $status ) );
$suggested = $status['lexiconSuggestions'] ?? [];
assert_true( count( $suggested ) >= 2, 'The proposal must carry the scan\'s lexicon candidates, got ' . wp_json_encode( $suggested ) );
$lexicon_before = count( array_filter( $GLOBALS['wp_test_inserted_posts'] ?? [], static fn( $post ) => \TranslateWP\Entities\CPTs\Lexicon::POST_TYPE === ( $post['post_type'] ?? '' ) ) );
assert_true( 0 === $lexicon_before, 'No lexicon term lands from a proposal scan, got ' . $lexicon_before );
$applied = $scanning->apply_scan_lexicon( new WP_REST_Request( 'POST', '/translatewp/v1/scanning/context/apply-lexicon', [], [], '' ) );
$lexicon_after = count( array_filter( $GLOBALS['wp_test_inserted_posts'] ?? [], static fn( $post ) => \TranslateWP\Entities\CPTs\Lexicon::POST_TYPE === ( $post['post_type'] ?? '' ) ) );
assert_true( ( $applied['added'] ?? -1 ) === count( $suggested ) && $lexicon_after === count( $suggested ), 'Applying adds exactly the suggested terms, got added=' . wp_json_encode( $applied ) . ' terms=' . $lexicon_after . ' suggested=' . count( $suggested ) );
$status = $scanning->scan_status( new WP_REST_Request( 'GET', '/translatewp/v1/scanning/context/status', [], [], '' ) );
assert_true( [] === ( $status['lexiconSuggestions'] ?? null ), 'Once applied the suggestions are no longer offered' );

wp_test_reset( connected_options() );
update_option( 'show_on_front', 'posts' );
update_option( 'page_on_front', 0 );
$queue = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$indexer->enqueue_read_rows();
$home_rows = array_values( array_filter( $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 ), static fn( $row ) => home_url( '/' ) === $row['url'] ) );
assert_true( 1 === count( $home_rows ), 'A latest-posts homepage must be read even without a front-page post ID' );
if ( $home_rows ) {
	$indexer->submit_extraction( (int) $home_rows[0]['id'], $items, true );
	$indexer->start_translation( [ 'it' ] );
	$home_push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
	assert_true( 1 === count( $home_push ) && home_url( '/' ) === $home_push[0]['url'], 'The latest-posts homepage must warm when languages are saved' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "SpineFirstTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SpineFirstTest: all assertions passed\n";
