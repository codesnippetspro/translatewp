<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationUrl;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Endpoints\TranslationJobs;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

/** The literal key CronFallback throttles with; the test elapses a minute by clearing it. */
const CRON_FALLBACK_KICK_TRANSIENT = 'translatewp_cron_fallback_kick';

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
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'es' => [ 'locale' => 'es', 'slug' => 'es', 'active' => true, 'default' => false ],
			],
		],
	];
}

function miss_request( array $params ): WP_REST_Request {
	return new WP_REST_Request( 'POST', '/translatewp/v1/translation/miss', $params, [], '' );
}

/** Queue a miss carrying $payload for $url and return its server-recomputed hash. */
function queue_miss_hash( TranslationJobs $jobs, string $url, string $group_id, string $payload ): string {
	$accepted = $jobs->report_misses(
		miss_request(
			[
				'language' => 'es',
				'url'      => $url,
				'items'    => [
					[
						'groupId'       => $group_id,
						'markerPayload' => $payload,
						'contextKey'    => 'div:nth-of-type(1) > p:nth-of-type(1)',
						'contextKind'   => 'text-flow',
					],
				],
			]
		)
	);
	assert_true( ! is_wp_error( $accepted ) && 1 === ( $accepted['accepted'] ?? 0 ), 'The miss must be accepted, got ' . wp_json_encode( $accepted ) );
	// A visitor waiting on a page makes no further request, so the report
	// itself must start the work even while a scheduled run holds cron.
	assert_true(
		[] !== array_filter( $GLOBALS['wp_test_actions_fired'] ?? [], static fn( $event ) => 'translatewp_loopback_kick' === ( $event['tag'] ?? '' ) ),
		'A miss on a host without the inline path must ask the site to drain now, not only spawn cron'
	);

	// One page and language own exactly one push row: a later miss on the same
	// page lands in that row instead of adding another.
	$rows = array_values( $GLOBALS['wp_test_inserted_posts'] ?? [] );
	assert_true( 1 === count( $rows ), 'The page must own exactly one push row, got ' . count( $rows ) );
	$decoded = json_decode( IndexQueue::decode_payload( (string) ( $rows[0]['post_content'] ?? '' ) ), true );
	$hash    = '';
	foreach ( is_array( $decoded ) ? $decoded : [] as $item ) {
		if ( ( $item['groupId'] ?? '' ) === $group_id ) {
			$hash = (string) ( $item['cache']['hash'] ?? '' );
		}
	}
	assert_true( '' !== $hash, 'The row payload must carry the missed group ' . $group_id );

	return $hash;
}

$page_url  = 'https://example.com/collection/?lang=es';
$canonical = TranslationUrl::canonicalize( $page_url );

// ---------------------------------------------------------------------------
// A front-end request on a site whose WP-Cron never fires drains one batch and
// back-fills the store, so the server-rendered title stops missing. This is the
// exact live sequence: a miss is recorded pending, cron never resolves it, and
// the title stays in the source language until a request kicks the pipeline.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );

$sync    = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION );
$jobs    = new TranslationJobs( 'translatewp', TRANSLATEWP_VERSION );
$cron    = new CronFallback( 'translatewp', TRANSLATEWP_VERSION );

$hash = queue_miss_hash( $jobs, $page_url, 'group-1', '⟪item_0001⟫Shop the collection' );
assert_true( '' !== $hash, 'The queued miss must carry a server-recomputed hash' );
$GLOBALS['wp_test_cache_lookup_items'][ $hash ] = '⟪item_0001⟫Compra la colección';

// The drain event was scheduled but WP-Cron never ran it: it is now overdue.
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() - 120;

assert_true( [] === TranslationStore::for_url( $canonical, 'es' ), 'Nothing is stored before any request runs' );
assert_true( true === $cron->is_behind(), 'An overdue drain with a broken cron must read as behind' );

// One ordinary front-end request.
$cron->maybe_run_on_front_end();

assert_true(
	'Compra la colección' === ( TranslationStore::for_url( $canonical, 'es' )['Shop the collection'] ?? '' ),
	'A front-end request must drain and back-fill so the title resolves, got ' . wp_json_encode( TranslationStore::for_url( $canonical, 'es' ) )
);
assert_true( 0 === TranslationStore::pending_count( $canonical, 'es' ), 'The back-fill must clear the pending title' );
assert_true( false !== get_transient( CRON_FALLBACK_KICK_TRANSIENT ), 'The request must arm the once-per-minute throttle' );

// ---------------------------------------------------------------------------
// At most one batch per minute: a second string missed and made ready is NOT
// drained by another request inside the same throttle window; the next request
// after the window drains it.
// ---------------------------------------------------------------------------
$hash2 = queue_miss_hash( $jobs, $page_url, 'group-2', '⟪item_0001⟫Read more' );
assert_true( '' !== $hash2 && $hash2 !== $hash, 'The second miss must queue a distinct hash' );
$GLOBALS['wp_test_cache_lookup_items'][ $hash2 ] = '⟪item_0001⟫Leer más';
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() - 120;

// Second request, still inside the minute: throttled, so the second string stays missing.
$cron->maybe_run_on_front_end();
assert_true(
	! isset( TranslationStore::for_url( $canonical, 'es' )['Read more'] ),
	'A second front-end request within the minute must not drain another batch'
);

// The minute elapses (the throttle transient expires); the next request drains again.
delete_transient( CRON_FALLBACK_KICK_TRANSIENT );
$cron->maybe_run_on_front_end();
assert_true(
	'Leer más' === ( TranslationStore::for_url( $canonical, 'es' )['Read more'] ?? '' ),
	'After the throttle window the next request drains and back-fills the second batch, got ' . wp_json_encode( TranslationStore::for_url( $canonical, 'es' ) )
);

// ---------------------------------------------------------------------------
// In wp-admin the same late-cron backlog drains on an ordinary page load, and
// the Indexing notice explains that scheduled tasks are late.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );

$sync    = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION );
$jobs    = new TranslationJobs( 'translatewp', TRANSLATEWP_VERSION );
$cron    = new CronFallback( 'translatewp', TRANSLATEWP_VERSION );

$admin_hash = queue_miss_hash( $jobs, $page_url, 'group-3', '⟪item_0001⟫Buy now' );
$GLOBALS['wp_test_cache_lookup_items'][ $admin_hash ]  = '⟪item_0001⟫Compra ahora';
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ]    = time() - 120;

// The notice renders while the site is behind, on a TranslateWP screen for a manager.
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$_GET                    = [ 'page' => 'translatewp' ];
ob_start();
$cron->render_cron_late_notice();
$notice = (string) ob_get_clean();
assert_true(
	false !== strpos( $notice, 'indexed as pages are viewed' ),
	'The Indexing notice must explain that indexing is happening on page views, got ' . $notice
);

// An admin page load drains and back-fills a batch.
$cron->maybe_run_in_admin();
assert_true(
	'Compra ahora' === ( TranslationStore::for_url( $canonical, 'es' )['Buy now'] ?? '' ),
	'An admin page load must drain and back-fill a batch, got ' . wp_json_encode( TranslationStore::for_url( $canonical, 'es' ) )
);

// The notice still renders after the same request drained the backlog away.
ob_start();
$cron->render_cron_late_notice();
$notice_after = (string) ob_get_clean();
assert_true(
	false !== strpos( $notice_after, 'indexed as pages are viewed' ),
	'The notice must still explain the late cron after the admin request drains the backlog'
);

// ---------------------------------------------------------------------------
// An overdue drain event with nothing left to push is not behind: the stale
// event is cleared and no notice renders.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$sync = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$cron = new CronFallback( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() - 120;
assert_true( false === $cron->is_behind(), 'An overdue drain with an empty queue is not behind' );
assert_true( false === wp_next_scheduled( Indexer::DRAIN_HOOK ), 'The stale drain event is cleared' );
ob_start();
$cron->render_cron_late_notice();
assert_true( '' === (string) ob_get_clean(), 'No late notice renders when nothing waits' );

// ---------------------------------------------------------------------------
// A healthy site (events scheduled for the near future) is never kicked.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$sync = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$cron = new CronFallback( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() + 45;
assert_true( false === $cron->is_behind(), 'A drain scheduled in the near future is not behind' );

if ( $failures > 0 ) {
	fwrite( STDERR, "CronFallbackTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "CronFallbackTest: all assertions passed\n";
