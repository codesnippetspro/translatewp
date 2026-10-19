<?php

declare(strict_types=1);

// A page translation is done when its job finished; cache proofs never age it
// out, a scope change is never repaired through the cold path, and a page the
// repair could not deliver stays in the total.

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Components\Migrations;
use TranslateWP\Components\Options;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

define( 'TRANSLATEWP_VERSION', '0.10.29' );

$failures = 0;
function tnw_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		++$failures;
	}
}

function tnw_done_row( IndexQueue $queue, int $object_id, string $language, string $job_id = '' ): array {
	$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => $object_id, 'url' => home_url( '/page-' . $object_id . '/' ), 'page_title' => 'Page ' . $object_id, 'language' => $language, 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
	$rows = $queue->list_rows();
	$row  = $rows[ count( $rows ) - 1 ];
	$queue->claim_batch( IndexQueue::STAGE_EXTRACT, 20 );
	$queue->store_payload( (int) $row['id'], wp_json_encode( [ [ 'groupId' => 'body', 'markerPayload' => 'Body' ] ] ) );
	$queue->claim_batch( IndexQueue::STAGE_PUSH, 20 );
	if ( '' !== $job_id ) {
		$queue->remember_job( (int) $row['id'], $job_id );
	}
	$queue->complete( (int) $row['id'] );
	update_post_meta( (int) $row['id'], '_twp_updated_at', 100 );
	return array_values( array_filter( $queue->list_rows(), static fn ( array $item ): bool => (int) $item['id'] === (int) $row['id'] ) )[0];
}

function tnw_manifest( array $row ): string {
	$hash  = str_repeat( 'a', 63 ) . (string) ( (int) $row['id'] % 10 );
	$title = str_repeat( 'b', 63 ) . (string) ( (int) $row['id'] % 10 );
	TranslationReadiness::record( (int) $row['id'], (string) $row['language'], [
		[ 'groupId' => 'body', 'cache' => [ 'hash' => $hash ], 'markerPayload' => '⟪item_0001⟫Body' ],
		[ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => $title ], 'markerPayload' => '⟪item_0001⟫Title' ],
	] );
	return $hash;
}

function tnw_present( string $language, array $hashes ): array {
	$items = [];
	foreach ( $hashes as $hash ) {
		$items[ $hash ] = '⟪item_0001⟫Tulkots';
	}
	return $items;
}

function tnw_language( Indexer $indexer, string $language ): array {
	return $indexer->status()['languages'][ $language ];
}

// --- 1. a row is translated once its job finished, and the figure is over the full total ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$open    = tnw_done_row( $queue, 1, 'de', 'job-open' );
$closed  = tnw_done_row( $queue, 2, 'de', 'job-closed' );
tnw_manifest( $open );
tnw_manifest( $closed );
$GLOBALS['wp_test_job_states'] = [ 'job-closed' => 'done', 'job-open' => 'open' ];
$before = tnw_language( $indexer, 'de' );
tnw_check( 0 === $before['ready'] && 2 === $before['readyTotal'] && false === $before['readyComplete'], 'Before any reconcile nothing is confirmed translated and the total counts every row' );
// The look-up is refused this pass, so only the job state can decide.
$refused = static fn (): WP_Error => new WP_Error( 'rate_limited', 'Too many', [ 'status' => 429, 'payload' => [ 'retryAfterSeconds' => 30 ] ] );
$heal    = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $refused, static fn (): int => 1000, false );
$heal->reconcile( 0 );
$after = tnw_language( $indexer, 'de' );
tnw_check( 1 === $after['ready'], 'The row whose job finished counts as translated: ' . wp_json_encode( $after ) );
tnw_check( 1 === $after['confirming'], 'The row whose job is still open is confirming, not translated' );
tnw_check( false === $after['readyComplete'], 'A language with an open job is not complete' );
$rows = $queue->list_rows();
tnw_check( true === $rows[1]['translated'] && false === $rows[0]['translated'], 'list_rows carries the translated flag per row' );
$calls = array_count_values( $GLOBALS['wp_test_job_status_requests'] ?? [] );
tnw_check( 1 === ( $calls['job-open'] ?? 0 ) && 1 === ( $calls['job-closed'] ?? 0 ), 'One status call per job per pass: ' . wp_json_encode( $calls ) );
$heal->reconcile( 0 );
$calls = array_count_values( $GLOBALS['wp_test_job_status_requests'] ?? [] );
tnw_check( 1 === ( $calls['job-closed'] ?? 0 ), 'A finished job is not asked again on the next pass' );

// --- 2. the figure never falls with time: proofs do not age out ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = tnw_done_row( $queue, 3, 'fr' );
$hash    = tnw_manifest( $row );
$heal    = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, 'tnw_present', static fn (): int => 1000, false );
$heal->reconcile( 0 );
tnw_check( 1 === tnw_language( $indexer, 'fr' )['ready'], 'A proven page is translated' );
$scope_ref = new ReflectionMethod( TranslationReadiness::class, 'scope' );
$scope_ref->setAccessible( true );
$key    = 'twp_ready_' . $scope_ref->invoke( null, 'fr' );
$proofs = get_transient( $key );
tnw_check( is_array( $proofs ) && isset( $proofs[ $hash ] ), 'The sweep recorded a proof for the page' );
$proofs = is_array( $proofs ) ? $proofs : [];
$proofs[ $hash ]['checked'] = 1;
set_transient( $key, $proofs, 1800 );
tnw_check( true === TranslationReadiness::is_ready( $queue->list_rows()[0] ), 'An old proof still proves the page; age does not withdraw it' );
delete_transient( $key );
tnw_check( 1 === tnw_language( $indexer, 'fr' )['ready'], 'A page stays translated after its proofs expire; the figure never falls on its own' );

// --- 3. a scope change is never repaired through the cold path ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = tnw_done_row( $queue, 4, 'it' );
tnw_manifest( $row );
$queue->mark_translated( [ (int) $row['id'] ] );
TranslationStore::bump_cache_generation( [ 'it' ] );
tnw_check( 'stale' === TranslationReadiness::manifest_state( (int) $row['id'], 'it' ), 'A manifest recorded under an older scope reads as stale' );
$heal   = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn (): array => [], static fn (): int => 1000, false );
$result = $heal->reconcile( 0 );
tnw_check( 0 === $result['requeued'], 'A row whose scope changed is not repaired: ' . wp_json_encode( $result ) );
tnw_check( IndexQueue::STATUS_DONE === $queue->list_rows()[0]['status'], 'The row stays done until an explicit retranslate' );
tnw_check( 1 === tnw_language( $indexer, 'it' )['ready'], 'It still counts as translated' );
$none = tnw_done_row( $queue, 5, 'it' );
tnw_check( 'none' === TranslationReadiness::manifest_state( (int) $none['id'], 'it' ), 'A row that never recorded a manifest reads as none' );

// --- 4. an explicit retranslate clears the flag and the figure follows the work ---
$queue->enqueue_retranslate( [ $queue->list_rows()[0] + [ 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
tnw_check( false === $queue->list_rows()[0]['translated'], 'A retranslated row loses its translated flag' );
$it = tnw_language( $indexer, 'it' );
tnw_check( 1 === $it['retranslating'] && 0 === $it['ready'], 'The language reports the retranslating row: ' . wp_json_encode( $it ) );

// --- 5. a dead page stays in the total and is reported, and does not hold the first pass open ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$good    = tnw_done_row( $queue, 6, 'es' );
$bad     = tnw_done_row( $queue, 7, 'es' );
$queue->mark_translated( [ (int) $good['id'] ] );
update_post_meta( (int) $bad['id'], '_twp_status', IndexQueue::STATUS_DEAD );
update_post_meta( (int) $bad['id'], '_twp_fail_reason', 'Translation did not reach the warm cache after automatic retries.' );
$status = $indexer->status();
$es     = $status['languages']['es'];
tnw_check( 1 === $es['ready'] && 2 === $es['readyTotal'] && 1 === $es['dead'], 'A dead page stays in the total: ' . wp_json_encode( $es ) );
tnw_check( true === $es['readyComplete'], 'Nothing is in flight, so the language is complete even with a dead page' );
tnw_check( true === $status['firstPassDone'], 'The first pass is done once every language has nothing in flight' );
tnw_check( 1 === count( $status['failed'] ), 'The dead page is listed for the Retry' );

// --- 6. rows of the site's own language or of a removed language are neither listed, retried nor repaired ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ], 'languages' => [ 'list' => [
	'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
] ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$kept    = tnw_done_row( $queue, 8, 'de' );
$own     = tnw_done_row( $queue, 9, 'lv' );
$removed = tnw_done_row( $queue, 10, 'fr' );
foreach ( [ $own, $removed ] as $gone ) {
	update_post_meta( (int) $gone['id'], '_twp_status', IndexQueue::STATUS_DEAD );
	update_post_meta( (int) $gone['id'], '_twp_fail_reason', 'Provider unavailable' );
	update_post_meta( (int) $gone['id'], '_twp_updated_at', 100 );
}
$status = $indexer->status();
tnw_check( [ 'de' ] === array_keys( $status['languages'] ), 'Only translation languages are listed: ' . wp_json_encode( array_keys( $status['languages'] ) ) );
tnw_check( 0 === count( $status['failed'] ), 'A dead row of a language that is not a target is not reported for retry' );
tnw_check( 0 === $indexer->retry_failed( null ), 'Retry all touches no row of the own or a removed language' );
tnw_check( IndexQueue::STATUS_DEAD === $queue->list_rows()[1]['status'] && IndexQueue::STATUS_DEAD === $queue->list_rows()[2]['status'], 'Those rows stay as they were' );
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn (): array => [], static fn (): int => 1000, false );
tnw_check( 0 === $heal->reconcile( 0 )['retried'], 'The automatic dead-row retry leaves them alone too' );
foreach ( [ $kept, $own, $removed ] as $stale ) {
	update_post_meta( (int) $stale['id'], '_twp_status', IndexQueue::STATUS_DONE );
	update_post_meta( (int) $stale['id'], '_twp_lexicon_version', 0 );
}
\TranslateWP\Components\CacheVersions::bump_lexicon_version();
$stale_counts = $queue->stale_counts();
tnw_check( [ 'de' => 1 ] === $stale_counts['lexicon'], 'Only translation languages wait for new terms: ' . wp_json_encode( $stale_counts ) );

// --- 8. a saved change to the brief or style retranslates every page of the translation languages, explicitly ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ], 'languages' => [ 'list' => [
	'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
] ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$page    = tnw_done_row( $queue, 11, 'de' );
$gone    = tnw_done_row( $queue, 12, 'fr' );
$queue->mark_translated( [ (int) $page['id'], (int) $gone['id'] ] );
$generation_before = TranslationStore::cache_generation_for( 'de' );
new \TranslateWP\Functionality\KeepWarm( 'translatewp', TRANSLATEWP_VERSION );
do_action( 'translatewp_translation_affecting_settings_saved' );
do_action( \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK );
$rows = $queue->list_rows();
$de   = array_values( array_filter( $rows, static fn ( array $row ): bool => 'de' === $row['language'] ) )[0];
$fr   = array_values( array_filter( $rows, static fn ( array $row ): bool => 'fr' === $row['language'] ) )[0];
tnw_check( IndexQueue::STATUS_PENDING === $de['status'] && true === $de['retranslate'], 'The page is queued for retranslation as the save said it would be: ' . wp_json_encode( $de ) );
tnw_check( IndexQueue::STATUS_DONE === $fr['status'], 'A removed language is not retranslated' );
tnw_check( TranslationStore::cache_generation_for( 'de' ) > $generation_before, 'The cache generation moves with the explicit retranslation' );
tnw_check( 1 === tnw_language( $indexer, 'de' )['retranslating'], 'The Overview sees it as a retranslation after a change' );

// --- 7. the cron pass proves several batches when the site is large ---
tnw_check( 1 === ReadinessReconcile::batches_per_pass( 3 ), 'A small site proves one batch per pass' );
tnw_check( 12 === ReadinessReconcile::batches_per_pass( 69 ), 'A sixth of a larger site per pass' );
tnw_check( 167 === ReadinessReconcile::batches_per_pass( 1000 ), 'A very large site still proves a sixth per pass, bounded by time instead' );
tnw_check( 4 === ReadinessReconcile::batches_per_pass( 20 ), 'A site in between proves a sixth of its batches per pass' );

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$hashes  = [];
for ( $n = 20; $n < 34; $n++ ) {
	$row = tnw_done_row( $queue, $n, 'de' );
	// Fourteen pages, each with fifty distinct segments: seven look-up batches of a hundred.
	$items = [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'd', 60 ) . str_pad( (string) $n, 4, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Title' ] ];
	for ( $g = 0; $g < 49; $g++ ) {
		$items[] = [ 'groupId' => 'g' . $g, 'cache' => [ 'hash' => str_repeat( 'c', 56 ) . str_pad( (string) ( $n * 100 + $g ), 8, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Body' ];
	}
	TranslationReadiness::record( (int) $row['id'], 'de', $items );
}
tnw_check( 7 === TranslationReadiness::batch_count( $queue->list_rows() ), 'Seven hundred segments are seven batches, got ' . TranslationReadiness::batch_count( $queue->list_rows() ) );
$looked_up = 0;
$counting  = static function ( string $language, array $batch ) use ( &$looked_up ): array {
	++$looked_up;
	return tnw_present( $language, $batch );
};
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $counting, static fn (): int => 1000, true );
$heal->run();
tnw_check( 2 === $looked_up, 'The scheduled pass proves a sixth of the site, two batches of seven, got ' . $looked_up );
$looked_up = 0;
$heal->reconcile( 0 );
tnw_check( 1 === $looked_up, 'A browser pass still proves one batch' );

// --- 9. only a finished job makes a row translated; a cancelled one never does; a forgotten or legacy one counts as finished ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$cancelled = tnw_done_row( $queue, 40, 'de', 'job-cancelled' );
$forgotten = tnw_done_row( $queue, 41, 'de', 'job-forgotten' );
$legacy    = tnw_done_row( $queue, 42, 'de' );
foreach ( [ $cancelled, $forgotten, $legacy ] as $row ) {
	tnw_manifest( $row );
}
$GLOBALS['wp_test_job_states']   = [ 'job-cancelled' => 'cancelled' ];
$GLOBALS['wp_test_job_missing']  = [ 'job-forgotten' ];
$refused = static fn (): WP_Error => new WP_Error( 'rate_limited', 'Too many', [ 'status' => 429, 'payload' => [ 'retryAfterSeconds' => 30 ] ] );
( new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $refused, static fn (): int => 1000, false ) )->reconcile( 0 );
$rows = $queue->list_rows();
tnw_check( false === $rows[0]['translated'], 'A cancelled job never makes its row translated' );
tnw_check( false === $rows[1]['translated'], 'A job the service no longer knows proves nothing on its own' );
tnw_check( false === $rows[2]['translated'], 'A row with no job recorded proves nothing on its own' );
// The proofs decide for them: once every segment is seen in the cache they are translated.
( new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, 'tnw_present', static fn (): int => 1000, false ) )->reconcile( 0 );
$rows = $queue->list_rows();
tnw_check( true === $rows[1]['translated'] && true === $rows[2]['translated'], 'Proven segments make a forgotten or job-less row translated' );
tnw_check( true === $rows[0]['translated'], 'and a cancelled job whose segments are nonetheless present counts too' );

// --- 10. a retranslation keeps its name until the new translation is confirmed ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$again   = tnw_done_row( $queue, 43, 'de', 'job-first' );
$queue->mark_translated( [ (int) $again['id'] ] );
$queue->enqueue_retranslate( [ $again + [ 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
$queue->claim_batch( IndexQueue::STAGE_EXTRACT, 20 );
$queue->store_payload( (int) $again['id'], wp_json_encode( [ [ 'groupId' => 'body', 'markerPayload' => 'Body' ] ] ) );
$queue->claim_batch( IndexQueue::STAGE_PUSH, 20 );
$queue->remember_job( (int) $again['id'], 'job-second' );
$queue->complete( (int) $again['id'] );
$de = tnw_language( $indexer, 'de' );
tnw_check( 1 === $de['retranslating'] && 0 === $de['ready'], 'A re-pushed row still reads as retranslating while its job runs: ' . wp_json_encode( $de ) );
$GLOBALS['wp_test_job_states'] = [ 'job-second' => 'done' ];
( new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $refused, static fn (): int => 1000, false ) )->reconcile( 0 );
$de = tnw_language( $indexer, 'de' );
tnw_check( 0 === $de['retranslating'] && 1 === $de['ready'], 'Once confirmed it is translated and the retranslation is over: ' . wp_json_encode( $de ) );

// --- 11. once setup is done, an empty language list hides every row; before setup nothing is hidden ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
tnw_done_row( $queue, 44, 'de' );
tnw_check( [ 'de' ] === array_keys( $indexer->status()['languages'] ), 'Before setup the row is visible' );
Options::update( 'setup.step', 'done' );
tnw_check( [] === array_keys( $indexer->status()['languages'] ), 'After setup, with no translation language left, nothing is listed' );
tnw_check( 0 === $indexer->retranslate_everything()['requeued'], 'and nothing is retranslated' );

// --- 12. the scheduled sweep comes back within a minute while the site is larger than one pass ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
for ( $n = 50; $n < 64; $n++ ) {
	$row   = tnw_done_row( $queue, $n, 'de' );
	$items = [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'd', 60 ) . str_pad( (string) $n, 4, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Title' ] ];
	for ( $g = 0; $g < 49; $g++ ) {
		$items[] = [ 'groupId' => 'g' . $g, 'cache' => [ 'hash' => str_repeat( 'c', 56 ) . str_pad( (string) ( $n * 100 + $g ), 8, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Body' ];
	}
	TranslationReadiness::record( (int) $row['id'], 'de', $items );
}
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, 'tnw_present', static fn (): int => 1000, false );
$heal->maybe_schedule();
$ttl = ( $GLOBALS['wp_test_transient_expirations'][ ReadinessReconcile::CHECK_TRANSIENT ] ?? 0 ) - time();
tnw_check( $ttl > 0 && $ttl <= 60, 'Seven batches at two per pass: the next pass is due within a minute, got ' . $ttl );
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
tnw_manifest( tnw_done_row( $queue, 65, 'de' ) );
( new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, 'tnw_present', static fn (): int => 1000, false ) )->maybe_schedule();
$ttl = ( $GLOBALS['wp_test_transient_expirations'][ ReadinessReconcile::CHECK_TRANSIENT ] ?? 0 ) - time();
tnw_check( 600 === $ttl, 'A site one pass covers keeps the ten-minute cadence, got ' . $ttl );

// Left alone, the scheduled passes walk the whole site: forty batches, each
// pass proving a sixth, are all proven after six passes.
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
for ( $n = 100; $n < 140; $n++ ) {
	$row   = tnw_done_row( $queue, $n, 'de' );
	$items = [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'd', 60 ) . str_pad( (string) $n, 4, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Title' ] ];
	for ( $g = 0; $g < 99; $g++ ) {
		$items[] = [ 'groupId' => 'g' . $g, 'cache' => [ 'hash' => str_repeat( 'c', 55 ) . str_pad( (string) ( $n * 1000 + $g ), 9, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Body' ];
	}
	TranslationReadiness::record( (int) $row['id'], 'de', $items );
}
tnw_check( 40 === TranslationReadiness::batch_count( $queue->list_rows() ), 'Forty batches, got ' . TranslationReadiness::batch_count( $queue->list_rows() ) );
$heal   = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, 'tnw_present', static fn (): int => 1000, true );
$passes = 0;
while ( $passes < 12 ) {
	$heal->run();
	++$passes;
	$proven = count( array_filter( $queue->list_rows(), static fn ( array $row ): bool => TranslationReadiness::is_ready( $row ) ) );
	if ( 40 === $proven ) {
		break;
	}
}
tnw_check( $passes <= 6, 'Every page is proven within six scheduled passes, took ' . $passes );

// Three hundred batches, past what a fixed per-pass cap would cover in six
// passes: a pass every minute, each proving a sixth of the site, marks every
// page translated within six passes while the service answers in time. The
// figure is what counts: proven pages are marked as they are proven.
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
for ( $n = 200; $n < 500; $n++ ) {
	$row   = tnw_done_row( $queue, $n, 'de' );
	$items = [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'e', 58 ) . str_pad( (string) $n, 6, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Title' ] ];
	for ( $g = 0; $g < 99; $g++ ) {
		$items[] = [ 'groupId' => 'g' . $g, 'cache' => [ 'hash' => str_repeat( 'f', 54 ) . str_pad( (string) ( $n * 1000 + $g ), 10, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Body' ];
	}
	TranslationReadiness::record( (int) $row['id'], 'de', $items );
}
tnw_check( 300 === TranslationReadiness::batch_count( $queue->list_rows() ), 'Three hundred batches' );
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, 'tnw_present', static fn (): int => 1000, true );
$heal->maybe_schedule();
$ttl  = ( $GLOBALS['wp_test_transient_expirations'][ ReadinessReconcile::CHECK_TRANSIENT ] ?? 0 ) - time();
tnw_check( 60 === $ttl, 'A pass every minute while the site is being walked, got ' . $ttl );
$passes = 0;
while ( $passes < 12 ) {
	$heal->run();
	++$passes;
	if ( 300 === tnw_language( $indexer, 'de' )['ready'] ) {
		break;
	}
}
tnw_check( $passes <= 7, 'Three hundred batches are marked translated within six one-minute passes and the pass that marks the last, took ' . $passes );

// The time budget: a slow service stops the pass at it instead of running long.
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue = new IndexQueue();
for ( $n = 600; $n < 650; $n++ ) {
	$row   = tnw_done_row( $queue, $n, 'de' );
	$items = [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'b', 58 ) . str_pad( (string) $n, 6, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Title' ] ];
	for ( $g = 0; $g < 99; $g++ ) {
		$items[] = [ 'groupId' => 'g' . $g, 'cache' => [ 'hash' => str_repeat( 'a', 54 ) . str_pad( (string) ( $n * 1000 + $g ), 10, '0', STR_PAD_LEFT ) ], 'markerPayload' => '⟪item_0001⟫Body' ];
	}
	TranslationReadiness::record( (int) $row['id'], 'de', $items );
}
$slow_calls = 0;
$slow       = static function ( string $language, array $batch ) use ( &$slow_calls ): array {
	++$slow_calls;
	usleep( 30000 );
	return tnw_present( $language, $batch );
};
add_filter( 'translatewp_readiness_batches_per_pass', static fn (): int => 50 );
add_filter( 'translatewp_readiness_pass_seconds', static fn (): float => 0.1 );
TranslationReadiness::refresh( $queue->list_rows(), 0, $slow );
tnw_check( $slow_calls >= 2 && $slow_calls <= 6, 'A slow service stops the pass at its time budget, made ' . $slow_calls . ' look-ups of fifty allowed' );
remove_filter( 'translatewp_readiness_batches_per_pass' );
remove_filter( 'translatewp_readiness_pass_seconds' );

// --- 13. a visitor miss row never adds to the page figure ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$page    = tnw_done_row( $queue, 66, 'de' );
$queue->mark_translated( [ (int) $page['id'] ] );
$queue->enqueue( [ [ 'object_type' => 'miss', 'object_id' => 660, 'url' => $page['url'], 'page_title' => '', 'language' => 'de', 'priority' => 10 ] ] );
foreach ( $queue->list_rows() as $row ) {
	if ( 'miss' === $row['object_type'] ) {
		update_post_meta( (int) $row['id'], '_twp_status', IndexQueue::STATUS_DONE );
	}
}
$de = tnw_language( $indexer, 'de' );
tnw_check( 1 === $de['readyTotal'] && 1 === $de['ready'], 'The page counts once however many miss rows share its address: ' . wp_json_encode( $de ) );
// A read page that a visitor reached first has only a miss row for that
// language; that row is the page's row and counts.
$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => 70, 'url' => home_url( '/page-70/' ), 'page_title' => 'Page 70', 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
foreach ( $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 20 ) as $claimed ) {
	if ( '' === (string) $claimed['language'] ) {
		$queue->store_payload( (int) $claimed['id'], wp_json_encode( [ [ 'groupId' => 'body', 'markerPayload' => '⟪item_0001⟫Body' ] ] ) );
	}
}
$queue->enqueue( [ [ 'object_type' => 'miss', 'object_id' => 700, 'url' => home_url( '/page-70/' ), 'page_title' => '', 'language' => 'de', 'priority' => 10 ] ] );
$de = tnw_language( $indexer, 'de' );
tnw_check( 2 === $de['readyTotal'], 'A visitor-first page with a reading counts once through its miss row: ' . wp_json_encode( $de ) );

// --- 14. saving the brief or style retranslates only when the translations would differ ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ], 'languages' => [ 'list' => [
	'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
] ] ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$queue->mark_translated( [ (int) tnw_done_row( $queue, 67, 'de' )['id'] ] );
Options::update( 'translations.context', [ 'text_context' => 'A dance studio in Riga.' ] );
new \TranslateWP\Functionality\KeepWarm( 'translatewp', TRANSLATEWP_VERSION );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( new WP_REST_Request( 'POST', '', [ 'option' => 'translations.context', 'data' => [ 'text_context' => 'A dance studio in Riga.' ] ] ) );
tnw_check( ! isset( $GLOBALS['wp_test_scheduled'][ \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK ] ), 'Saving the same brief again schedules no retranslation' );
$admin->update_data( new WP_REST_Request( 'POST', '', [ 'option' => 'translations.context', 'data' => [ 'text_context' => 'A contemporary dance studio in Riga, for dancers of every level.' ] ] ) );
tnw_check( isset( $GLOBALS['wp_test_scheduled'][ \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK ] ), 'A changed brief schedules the retranslation' );
do_action( \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK );
tnw_check( IndexQueue::STATUS_PENDING === $queue->list_rows()[0]['status'], 'and the site is translated again' );

// A change that touches one language (a per-language model) retranslates that language only.
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ], 'languages' => [ 'list' => [
	'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
	'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
] ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$queue->mark_translated( [ (int) tnw_done_row( $queue, 71, 'de' )['id'], (int) tnw_done_row( $queue, 72, 'it' )['id'] ] );
new \TranslateWP\Functionality\KeepWarm( 'translatewp', TRANSLATEWP_VERSION );
do_action( 'translatewp_translation_affecting_settings_saved', [ 'it' ] );
do_action( 'translatewp_translation_affecting_settings_saved', [ 'it' ] );
tnw_check( [ 'it' ] === get_option( \TranslateWP\Functionality\KeepWarm::PENDING_LANGUAGES_OPTION ), 'Changes in one window gather their languages once' );
$events = array_filter( $GLOBALS['wp_test_scheduled'], static fn ( $hook ): bool => \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK === $hook, ARRAY_FILTER_USE_KEY );
tnw_check( 1 === count( $events ), 'and leave one scheduled retranslation' );
do_action( \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK );
$by = [];
foreach ( $queue->list_rows() as $row ) {
	$by[ $row['language'] ] = $row['status'];
}
tnw_check( IndexQueue::STATUS_PENDING === $by['it'] && IndexQueue::STATUS_DONE === $by['de'], 'Only the changed language is translated again: ' . wp_json_encode( $by ) );
tnw_check( false === get_option( \TranslateWP\Functionality\KeepWarm::PENDING_LANGUAGES_OPTION, false ), 'The gathered languages are consumed' );

// Overlapping changes for [de, it] and [it] are one retranslation of both, each page once.
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ], 'languages' => [ 'list' => [
	'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
	'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
	'fr' => [ 'locale' => 'fr', 'slug' => 'fr', 'active' => true, 'default' => false ],
] ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$queue->mark_translated( [ (int) tnw_done_row( $queue, 74, 'de' )['id'], (int) tnw_done_row( $queue, 75, 'it' )['id'], (int) tnw_done_row( $queue, 76, 'fr' )['id'] ] );
new \TranslateWP\Functionality\KeepWarm( 'translatewp', TRANSLATEWP_VERSION );
do_action( 'translatewp_translation_affecting_settings_saved', [ 'de', 'it' ] );
do_action( 'translatewp_translation_affecting_settings_saved', [ 'it' ] );
$generations = [ 'de' => TranslationStore::cache_generation_for( 'de' ), 'it' => TranslationStore::cache_generation_for( 'it' ) ];
do_action( \TranslateWP\Functionality\KeepWarm::SETTINGS_DEBOUNCE_HOOK );
$by = [];
foreach ( $queue->list_rows() as $row ) {
	$by[ $row['language'] ] = $row['status'];
}
tnw_check( IndexQueue::STATUS_PENDING === $by['de'] && IndexQueue::STATUS_PENDING === $by['it'] && IndexQueue::STATUS_DONE === $by['fr'], 'Both changed languages, French untouched: ' . wp_json_encode( $by ) );
tnw_check( TranslationStore::cache_generation_for( 'it' ) === $generations['it'] + 1, 'Italian is purged once, not twice' );
// A change for every language swallows the per-language ones in its window.
do_action( 'translatewp_translation_affecting_settings_saved', [ 'it' ] );
do_action( 'translatewp_translation_affecting_settings_saved' );
do_action( 'translatewp_translation_affecting_settings_saved', [ 'de' ] );
tnw_check( [ '*' ] === get_option( \TranslateWP\Functionality\KeepWarm::PENDING_LANGUAGES_OPTION ), 'One change for every language wins the window' );

// --- 15. an upgraded site opens with its finished pages counted, not at zero ---
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
tnw_done_row( $queue, 68, 'de', 'job-old' );
tnw_done_row( $queue, 69, 'de', 'job-old' );
tnw_check( 0 === tnw_language( $indexer, 'de' )['ready'], 'Precondition: rows completed under the old plugin carry no mark' );
update_option( Migrations::VERSION_OPTION, '0.10.28' );
Migrations::maybe_run();
$de = tnw_language( $indexer, 'de' );
tnw_check( 2 === $de['ready'] && true === $de['readyComplete'], 'After the update every finished page is counted at once: ' . wp_json_encode( $de ) );
tnw_check( true === $indexer->status()['firstPassDone'], 'and the first pass is over, so the Overview does not restart it' );
wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
tnw_done_row( $queue, 73, 'de', 'job-old' );
update_option( Migrations::VERSION_OPTION, TRANSLATEWP_VERSION );
Migrations::maybe_run();
tnw_check( 1 === tnw_language( $indexer, 'de' )['ready'], 'A site that already ran this version before the mark existed is backfilled too' );

exit( $failures > 0 ? 1 : 0 );
