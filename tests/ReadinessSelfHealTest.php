<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Endpoints\Indexing;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

define( 'TRANSLATEWP_VERSION', '0.10.11' );

$failures = 0;
function self_heal_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		++$failures;
	}
}

function self_heal_done_row( IndexQueue $queue, int $object_id, string $language ): array {
	$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => $object_id, 'url' => home_url( '/page-' . $object_id . '/' ), 'page_title' => 'Page ' . $object_id, 'language' => $language, 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
	$rows = $queue->list_rows();
	$row  = $rows[ count( $rows ) - 1 ];
	$queue->claim_batch( IndexQueue::STAGE_EXTRACT, 20 );
	$queue->store_payload( (int) $row['id'], wp_json_encode( [ [ 'groupId' => 'body', 'markerPayload' => 'Body' ] ] ) );
	$queue->claim_batch( IndexQueue::STAGE_PUSH, 20 );
	$queue->complete( (int) $row['id'] );
	$row = array_values( array_filter( $queue->list_rows(), static fn ( array $item ): bool => (int) $item['id'] === (int) $row['id'] ) )[0];
	update_post_meta( (int) $row['id'], '_twp_updated_at', 100 );
	$row['updated'] = 100;
	return $row;
}

function self_heal_read_row( IndexQueue $queue, int $object_id ): void {
	$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => $object_id, 'url' => home_url( '/page-' . $object_id . '/' ), 'page_title' => 'Page ' . $object_id, 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
	$read_id = 0;
	foreach ( $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 20 ) as $claimed ) {
		if ( '' === (string) $claimed['language'] ) {
			$read_id = (int) $claimed['id'];
		}
	}
	$queue->store_payload( $read_id, wp_json_encode( [ [ 'groupId' => 'body', 'markerPayload' => '⟪item_0001⟫Body' ] ] ) );
}

function self_heal_manifest( array $row ): array {
	$body_hash  = str_repeat( 'a', 63 ) . (string) ( (int) $row['id'] % 10 );
	$title_hash = str_repeat( 'b', 63 ) . (string) ( (int) $row['id'] % 10 );
	TranslationReadiness::record( (int) $row['id'], (string) $row['language'], [
		[ 'groupId' => 'body', 'cache' => [ 'hash' => $body_hash ], 'markerPayload' => '⟪item_0001⟫Body' ],
		[ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => $title_hash ], 'markerPayload' => '⟪item_0001⟫Title' ],
	] );
	return [ $body_hash, $title_hash ];
}

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = self_heal_done_row( $queue, 1, 'it' );
[ $body_hash ] = self_heal_manifest( $row );
self_heal_read_row( $queue, 1 );
$lookup = static fn ( string $language, array $hashes ): array => in_array( $body_hash, $hashes, true ) ? [ $body_hash => '⟪item_0001⟫Corpo' ] : [];
$heal   = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $lookup, static fn (): int => 1000, false );
$result = $heal->reconcile( 0 );
self_heal_check( 1 === $result['requeued'], 'Cron reconcile must requeue a done page whose title is cold' );
self_heal_check( IndexQueue::STAGE_PUSH === $queue->list_rows()[0]['stage'], 'A cold page with a stored reading is re-pushed through STAGE_PUSH, not returned to extraction' );

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue    = new IndexQueue();
$indexer  = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row      = self_heal_done_row( $queue, 2, 'fr' );
[ $body_hash ] = self_heal_manifest( $row );
$endpoint_lookup = static fn ( string $language, array $hashes ): array => in_array( $body_hash, $hashes, true ) ? [ $body_hash => '⟪item_0001⟫Corps' ] : [];
$heal     = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $endpoint_lookup, static fn (): int => 1000, false );
$endpoint = new Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer, $heal );
$endpoint->readiness( new WP_REST_Request( 'POST', '/translatewp/v1/indexing/readiness', [], [], '' ) );
self_heal_check( IndexQueue::STAGE_EXTRACT === $queue->list_rows()[0]['stage'], 'Readiness endpoint heals a cold page with no stored reading through the extraction fallback' );

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = self_heal_done_row( $queue, 5, 'uk' );
self_heal_manifest( $row );
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn (): array => [], static fn (): int => 1000 );
$heal->maybe_schedule();
self_heal_check( isset( $GLOBALS['wp_test_scheduled'][ ReadinessReconcile::HOOK ] ), 'Ordinary init must schedule readiness reconcile without an admin request' );
do_action( ReadinessReconcile::HOOK );
self_heal_check( IndexQueue::STATUS_PENDING === $queue->list_rows()[0]['status'], 'The scheduled readiness hook must heal without an admin request' );

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = self_heal_done_row( $queue, 3, 'es' );
self_heal_manifest( $row );
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn (): array => [], static fn (): int => 1000, false );
$cold_requeues = 0;
for ( $attempt = 0; $attempt < 4; ++$attempt ) {
	$result = $heal->reconcile( 0 );
	$cold_requeues += $result['requeued'];
	$current = $queue->list_rows()[0];
	if ( IndexQueue::STATUS_PENDING === $current['status'] ) {
		update_post_meta( (int) $current['id'], '_twp_status', IndexQueue::STATUS_DONE );
		update_post_meta( (int) $current['id'], '_twp_updated_at', 100 );
	}
}
$current = $queue->list_rows()[0];
self_heal_check( 3 === $cold_requeues, 'Cold-page healing must use exactly three automatic attempts' );
self_heal_check( IndexQueue::STATUS_DEAD === $current['status'], 'Cold-page healing must stop at its attempt cap' );
self_heal_check( false !== strpos( $current['reason'], 'warm cache' ), 'An exhausted cold page must retain an actionable failure reason' );

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$row     = self_heal_done_row( $queue, 4, 'lv' );
update_post_meta( (int) $row['id'], '_twp_status', IndexQueue::STATUS_DEAD );
update_post_meta( (int) $row['id'], '_twp_fail_reason', 'Provider unavailable' );
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, static fn (): array => [], static fn (): int => 1000, false );
$dead_retries = 0;
for ( $attempt = 0; $attempt < 4; ++$attempt ) {
	$result = $heal->reconcile( 0 );
	$dead_retries += $result['retried'];
	$current = $queue->list_rows()[0];
	if ( IndexQueue::STATUS_PENDING === $current['status'] ) {
		update_post_meta( (int) $current['id'], '_twp_status', IndexQueue::STATUS_DEAD );
		update_post_meta( (int) $current['id'], '_twp_fail_reason', 'Provider unavailable' );
		update_post_meta( (int) $current['id'], '_twp_updated_at', 100 );
	}
}
$current = $queue->list_rows()[0];
self_heal_check( 3 === $dead_retries, 'A dead row must receive exactly three automatic retries' );
self_heal_check( IndexQueue::STATUS_DEAD === $current['status'], 'Automatic dead-row retries must stop at their attempt cap' );
self_heal_check( 'Provider unavailable' === $current['reason'], 'An exhausted dead row must preserve its failure reason' );

exit( $failures > 0 ? 1 : 0 );
