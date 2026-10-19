<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\KeepWarm;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.5' );
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

function seed_page( string $modified_gmt ): void {
	wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 1 => 'page' ] );
	$GLOBALS['wp_test_inserted_posts'] = [
		[
			'ID'                => 1,
			'post_type'         => 'page',
			'post_title'        => 'Front page',
			'post_excerpt'      => '',
			'post_content'      => 'Shop the collection.',
			'post_status'       => 'publish',
			'post_modified_gmt' => $modified_gmt,
		],
	];
	$GLOBALS['wp_test_posts']['page'] = [ 1 ];
}

function set_page_modified( string $modified_gmt ): void {
	$GLOBALS['wp_test_inserted_posts'][0]['post_modified_gmt'] = $modified_gmt;
}

function index_rows(): array {
	return array_values(
		array_filter(
			$GLOBALS['wp_test_inserted_posts'] ?? [],
			static fn( array $post ): bool => 'twp_index_item' === ( $post['post_type'] ?? '' )
		)
	);
}

function row_for( string $language ): array {
	foreach ( index_rows() as $row ) {
		if ( $language === (string) ( $row['meta_input']['_twp_language'] ?? '' ) ) {
			return $row;
		}
	}

	return [];
}

function stamp_updated_at( int $timestamp ): void {
	foreach ( index_rows() as $row ) {
		update_post_meta( (int) $row['ID'], '_twp_updated_at', $timestamp );
	}
}

$items = [
	[ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Shop the collection.', 'contextKey' => 'main > p', 'contextKind' => 'text-flow' ],
];

/**
 * Read the page, translate it into German and Italian and finish every push,
 * leaving the site fully indexed.
 */
function index_site( IndexQueue $queue, Indexer $indexer, array $items ): void {
	$indexer->enqueue_read_rows();
	$read = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
	$indexer->submit_extraction( (int) $read[0]['id'], $items, true );
	$indexer->start_translation( [ 'de', 'it' ] );
	foreach ( $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 ) as $row ) {
		$queue->complete( (int) $row['id'] );
	}
}

$page_saved_at = strtotime( '2026-09-16 12:00:00 UTC' );

// ---------------------------------------------------------------------------
// A page saved before it was read already carries the saved text: the rewarm
// that fires after the save leaves every row as it is.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_page( gmdate( 'Y-m-d H:i:s', $page_saved_at ) );
$queue    = new IndexQueue();
$indexer  = new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue );
$keepwarm = new KeepWarm( 'translatewp', TRANSLATEWP_VERSION );
index_site( $queue, $indexer, $items );
stamp_updated_at( $page_saved_at + 60 );

$keepwarm->rewarm_saved_object( 1, 'page' );

assert_true( IndexQueue::STATUS_DONE === ( row_for( 'de' )['meta_input']['_twp_status'] ?? '' ), 'A page read after its save must keep its German row done' );
assert_true( IndexQueue::STAGE_READ === ( row_for( IndexQueue::READ_LANGUAGE )['meta_input']['_twp_stage'] ?? '' ), 'A page read after its save must not be read again' );

// ---------------------------------------------------------------------------
// A page edited after it was read is read again once; its translated rows keep
// serving and stay done, and the new reading is translated as soon as it lands.
// ---------------------------------------------------------------------------
set_page_modified( gmdate( 'Y-m-d H:i:s', $page_saved_at + 600 ) );

$keepwarm->rewarm_saved_object( 1, 'page' );

$read_row = row_for( IndexQueue::READ_LANGUAGE );
assert_true( IndexQueue::STAGE_EXTRACT === ( $read_row['meta_input']['_twp_stage'] ?? '' ), 'An edited page must be queued to be read again' );
assert_true( IndexQueue::STATUS_PENDING === ( $read_row['meta_input']['_twp_status'] ?? '' ), 'The re-read must wait for the tab' );
foreach ( [ 'de', 'it' ] as $language ) {
	assert_true( IndexQueue::STATUS_DONE === ( row_for( $language )['meta_input']['_twp_status'] ?? '' ), "The {$language} row must stay done while the page is read again" );
}
assert_true( 1 === $queue->read_counts()['pending'], 'The status must report one page waiting to be read' );

$reread = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
assert_true( 1 === count( $reread ) && IndexQueue::READ_LANGUAGE === $reread[0]['language'], 'Only the read row is claimed for extraction' );

$edited_items = [
	[ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Shop the new collection.', 'contextKey' => 'main > p', 'contextKind' => 'text-flow' ],
];
$indexer->submit_extraction( (int) $reread[0]['id'], $edited_items, true );

$pushed = $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 );
$languages = array_column( $pushed, 'language' );
sort( $languages );
assert_true( [ 'de', 'it' ] === $languages, 'The new reading must be sent for translation in every target language, got ' . wp_json_encode( $languages ) );
$de_payload = '';
foreach ( $pushed as $row ) {
	if ( 'de' === $row['language'] ) {
		$de_payload = (string) $row['payload'];
	}
}
assert_true( false !== strpos( $de_payload, 'Shop the new collection.' ), 'The German push must carry the edited text' );

foreach ( $pushed as $row ) {
	$queue->complete( (int) $row['id'] );
}
$indexer->enqueue_read_rows();
$claimed_again = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 10 );
foreach ( $claimed_again as $row ) {
	$indexer->submit_extraction( (int) $row['id'], $edited_items, true );
}
assert_true( [] === $queue->claim_batch( IndexQueue::STAGE_PUSH, 10 ), 'A later ordinary read must not push the page again on its own' );

// ---------------------------------------------------------------------------
// Rows extracted per language, with no site reading, are revived only when
// they were indexed before the save.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
seed_page( gmdate( 'Y-m-d H:i:s', $page_saved_at ) );
$queue = new IndexQueue();
$queue->enqueue(
	[
		[ 'object_type' => 'page', 'object_id' => 1, 'url' => 'https://example.com/?p=1', 'language' => 'de', 'priority' => Indexer::PRIORITY_DEFAULT ],
	]
);
update_post_meta( (int) row_for( 'de' )['ID'], '_twp_status', IndexQueue::STATUS_DONE );
stamp_updated_at( $page_saved_at + 60 );

$keepwarm->rewarm_saved_object( 1, 'page' );
assert_true( IndexQueue::STATUS_DONE === ( row_for( 'de' )['meta_input']['_twp_status'] ?? '' ), 'A row indexed after the save must not be revived' );

stamp_updated_at( $page_saved_at - 60 );
$keepwarm->rewarm_saved_object( 1, 'page' );
assert_true( IndexQueue::STATUS_PENDING === ( row_for( 'de' )['meta_input']['_twp_status'] ?? '' ), 'A row indexed before the save must be revived' );

if ( $failures > 0 ) {
	fwrite( STDERR, "KeepWarmRereadTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "KeepWarmRereadTest passed\n";
