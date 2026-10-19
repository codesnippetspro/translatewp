<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/ReadinessSelfHealTest.php' ), $self_heal_status );
if ( 0 !== $self_heal_status ) {
	exit( $self_heal_status );
}

$failures = 0;
function check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [ 'site' => [ 'id' => 'site', 'token' => 'secret' ] ] );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 1, 'url' => home_url( '/' ), 'page_title' => 'Home', 'language' => 'it', 'priority' => 1 ] ] );
$row = $queue->list_rows()[0];
check( ! TranslationReadiness::is_ready( $row ), 'A queued page is not ready' );
$items = [ [ 'groupId' => 'body', 'cache' => [ 'hash' => str_repeat( 'a', 64 ) ], 'markerPayload' => '⟪item_0001⟫Hello⟪item_0002⟫World' ], [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'b', 64 ) ], 'markerPayload' => '⟪item_0001⟫Home' ] ];
TranslationReadiness::record( (int) $row['id'], 'it', $items );
$row['status'] = IndexQueue::STATUS_DONE;
check( ! TranslationReadiness::is_ready( $row ), 'API acceptance alone is not ready' );
TranslationReadiness::refresh( [ $row ], 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Ciao' ] );
check( ! TranslationReadiness::is_ready( $row ), 'A partial marker group is not ready' );
TranslationReadiness::refresh( [ $row ], 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Ciao⟪item_0002⟫Mondo' ] );
check( ! TranslationReadiness::is_ready( $row ), 'A missing expected fingerprint is not ready' );
TranslationReadiness::refresh( [ $row ], 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Ciao⟪item_0002⟫Mondo', str_repeat( 'b', 64 ) => '⟪item_0001⟫Casa' ] );
check( TranslationReadiness::is_ready( $row ), 'All complete cached fingerprints make the page ready' );
TranslationReadiness::refresh( [ $row ], 0, static fn() => [] );
check( ! TranslationReadiness::is_ready( $row ), 'An unavailable cache invalidates readiness' );
check( ! TranslationReadiness::is_ready( array_merge( $row, [ 'id' => 999 ] ) ), 'A legacy row without evidence is not ready' );
$summary = \TranslateWP\Components\DashboardTranslations::summary( $queue, [], time() );
check( 0 === $summary['translationsToday'], 'The dashboard must not count queued pages as translated' );
$extract = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
$queue->store_payload( (int) $row['id'], wp_json_encode( $items ) );
$queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
$queue->complete( (int) $row['id'] );
$summary = \TranslateWP\Components\DashboardTranslations::summary( $queue, [], time() );
check( 0 === $summary['translationsToday'], 'The dashboard must not count accepted but cold pages as translated' );

TranslationReadiness::record( (int) $row['id'], 'it', [ $items[0], [ 'cache' => [ 'hash' => 'bad' ], 'markerPayload' => '⟪item_0001⟫Other' ] ] );
TranslationReadiness::refresh( [ $row ], 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Ciao⟪item_0002⟫Mondo' ] );
check( ! TranslationReadiness::is_ready( $row ), 'An invalid expected group must not be silently dropped from completeness' );

TranslationReadiness::record( (int) $row['id'], 'it', $items, false );
TranslationReadiness::refresh( [ $row ], 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Ciao⟪item_0002⟫Mondo', str_repeat( 'b', 64 ) => '⟪item_0001⟫Casa' ] );
check( ! TranslationReadiness::is_ready( $row ), 'A visitor miss subset cannot establish whole-page readiness without a scanned manifest' );

exit( $failures > 0 ? 1 : 0 );
