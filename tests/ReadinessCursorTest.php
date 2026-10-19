<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.19' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function connected_site(): array {
	return [ 'site' => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ] ];
}

/** A done page row of one small proven-able segment group plus its title. */
function cursor_done_row( IndexQueue $queue, int $object_id, string $language ): void {
	$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => $object_id, 'url' => home_url( '/page-' . $object_id . '/' ), 'page_title' => 'Page ' . $object_id, 'language' => $language, 'priority' => Indexer::PRIORITY_DEFAULT ] ] );
	$rows = $queue->list_rows();
	$row  = end( $rows );
	$id   = (int) $row['id'];
	TranslationReadiness::record( $id, $language, [
		[ 'groupId' => 'body', 'cache' => [ 'hash' => str_pad( dechex( crc32( $object_id . $language . 'body' ) ), 64, '0' ) ], 'markerPayload' => '⟪item_0001⟫Text' ],
		[ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_pad( dechex( crc32( $object_id . $language . 'title' ) ), 64, '0' ) ], 'markerPayload' => '⟪item_0001⟫Page ' . $object_id ],
	] );
	update_post_meta( $id, '_twp_status', IndexQueue::STATUS_DONE );
	update_post_meta( $id, '_twp_updated_at', 100 );
}

// ---------------------------------------------------------------------------
// The unattended scheduled reconcile persists its batch cursor, so repeated
// runs walk every language's batch and wrap back to the start, instead of
// re-checking only the first batch forever on a site with no admin polling.
// ---------------------------------------------------------------------------
wp_test_reset( connected_site() );
delete_option( ReadinessReconcile::CURSOR_OPTION );
$queue   = new IndexQueue();
$indexer = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );

// Three languages, one done page each, so refresh builds three batches.
cursor_done_row( $queue, 10, 'fr' );
cursor_done_row( $queue, 11, 'de' );
cursor_done_row( $queue, 12, 'it' );

$answer = static function ( string $language, array $hashes ): array {
	$items = [];
	foreach ( $hashes as $hash ) {
		$items[ $hash ] = '⟪item_0001⟫Texte';
	}
	return $items;
};
$heal = new ReadinessReconcile( 'translatewp', TRANSLATEWP_VERSION, $indexer, $answer, static fn (): int => 10_000 );

$heal->run();
assert_true( 1 === (int) get_option( ReadinessReconcile::CURSOR_OPTION, 0 ), 'The first scheduled run advances the persisted cursor to the second batch' );
$heal->run();
assert_true( 2 === (int) get_option( ReadinessReconcile::CURSOR_OPTION, 0 ), 'The second scheduled run advances to the third batch' );
$heal->run();
assert_true( 0 === (int) get_option( ReadinessReconcile::CURSOR_OPTION, 0 ), 'The third scheduled run wraps the cursor back to the start' );

foreach ( [ 'fr', 'de', 'it' ] as $language ) {
	$row = array_values( array_filter( $queue->list_rows(), static fn ( array $item ): bool => $language === $item['language'] ) )[0];
	assert_true( TranslationReadiness::is_ready( $row ), 'Every language is proven ready once the cursor has walked all batches: ' . $language );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "ReadinessCursorTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "ReadinessCursorTest passed\n";
