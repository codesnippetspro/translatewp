<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';
define( 'TRANSLATEWP_ENGINE_TRACE', true );

function check_legacy( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
function legacy_state(): array {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [
		'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ], 'es' => [ 'locale' => 'es', 'active' => true ], 'it' => [ 'locale' => 'it', 'active' => true ], 'lv' => [ 'locale' => 'lv', 'active' => true ],
	] ] ] );
	update_option( LexiconChanges::SEEDED_OPTION, true, false ); update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
	$rows = [];
	foreach ( [ '', 'es', 'it', 'lv' ] as $language ) {
		$meta = [ '_twp_object_type' => 'page', '_twp_object_id' => 8, '_twp_url' => 'https://example.com/cart/', '_twp_language' => $language, '_twp_status' => 'pending', '_twp_stage' => 'extract', '_twp_priority' => 100, '_twp_attempts' => 0 ];
		if ( '' !== $language ) { $meta += [ '_twp_retranslate' => 1, '_twp_retranslating' => 1, '_twp_translated_at' => 1795008977, '_twp_job_outcome' => 'done', '_twp_cold_retries' => 1 ]; }
		$rows[] = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'post_content' => '', 'meta_input' => $meta ] );
	}
	wp_test_set_filter( 'translatewp_engine_service_reads', static fn (): bool => true );
	return $rows;
}
$ids = legacy_state();
update_option( \TranslateWP\Functionality\ReadinessReconcile::STEP_CURSOR_OPTION, [ 'offset' => 0, 'row' => $ids[1], 'cursor' => 1 ], false );
$result = BackgroundEngine::step( [ [ 'url' => 'https://example.com/cart/', 'status' => 200, 'html' => '<html><body><p>Our shopping cart</p></body></html>' ] ] );
check_legacy( 1 <= $result['trace']['rows'], 'The captured pending/extract rows must read immediately, regardless of old translated/job marks' );
check_legacy( 'done' === get_post_meta( $ids[0], '_twp_status', true ), 'The source read must actually finish' );


$ids = legacy_state();
$queue = new IndexQueue();
$visitor = $queue->enqueue_miss_page( [ 'object_type' => 'miss', 'object_id' => 20, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 1 ], [ [ 'groupId' => 'shop', 'markerPayload' => '⟪item_0001⟫Our shop' ] ] );
$GLOBALS['wp_test_after_option_read'][ BackgroundEngine::VISITOR_STREAK_OPTION ] = static function () use ( $visitor ): void {
	update_post_meta( $visitor, '_twp_status', 'done' ); update_post_meta( $visitor, '_twp_translated_at', time() );
};
$result = BackgroundEngine::step( [ [ 'url' => 'https://example.com/cart/', 'status' => 200, 'html' => '<p>Our shopping cart</p>' ] ] );
check_legacy( 'done' === get_post_meta( $ids[0], '_twp_status', true ), 'A selected push whose claim is empty must fall through to a real read in the same turn' );

$ids = legacy_state();
update_option( IndexQueue::NORMALIZE_CURSOR_OPTION, 0, false );
$queue = new IndexQueue();
\TranslateWP\Components\QueueExecution::run( static function () use ( $queue ): void { $queue->normalize_legacy_rows(); } );
foreach ( array_slice( $ids, 1 ) as $id ) {
	check_legacy( '' === get_post_meta( $id, '_twp_translated_at', true ) && '' === get_post_meta( $id, '_twp_job_outcome', true ), 'Upgrade normalization clears old delivery evidence from pending extraction' );
	check_legacy( 'pending' === get_post_meta( $id, '_twp_status', true ) && 'extract' === get_post_meta( $id, '_twp_stage', true ), 'Normalization preserves the real pending extraction and force flag' );
	check_legacy( 1 === (int) get_post_meta( $id, '_twp_retranslate', true ), 'Normalization never loses the forced retranslation' );
}
check_legacy( false === get_option( IndexQueue::NORMALIZE_CURSOR_OPTION, false ), 'The bounded upgrade audit retires its cursor after the final chunk' );

$ids = legacy_state();
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
$pages = [ 'checkout', 'shop', 'pricing' ];
$queue = new IndexQueue();
foreach ( $pages as $index => $slug ) {
	$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => 20 + $index, 'url' => 'https://example.com/' . $slug . '/', 'priority' => 100 ] ] );
}
$source_ids = array_column( $queue->pending_read_rows(), 'id', 'url' );
$supplied = [
	[ 'url' => 'https://example.com/checkout/', 'status' => 302, 'error' => 'redirected_away' ],
	[ 'url' => 'https://example.com/shop/', 'status' => 200, 'html' => '<p>Our shop</p>' ],
	[ 'url' => 'https://example.com/pricing/', 'status' => 200, 'html' => '<p>Our prices</p>' ],
];
update_option( Indexer::NEXT_READER_OPTION, time() + 3600, false );
BackgroundEngine::step( $supplied );
$read = array_column( $queue->read_rows(), 'url' );
check_legacy( in_array( $supplied[1]['url'], $read, true ) && in_array( $supplied[2]['url'], $read, true ), 'A redirect cannot block either supplied neighbour, even during self-read cooldown' );
$redirect = $source_ids[ $supplied[0]['url'] ];
check_legacy( 'dead' === get_post_meta( $redirect, '_twp_status', true ) && 'page_redirected' === get_post_meta( $redirect, '_twp_fail_reason', true ), 'A supplied redirect has a terminal reason' );

$ids = legacy_state();
$old_url = 'http://previous.example/cart/?size=m';
$current_url = 'https://example.com/cart/?size=m';
foreach ( $ids as $id ) {
	update_post_meta( $id, '_twp_url', $old_url );
}
$indexer = new Indexer( '', '', null, null, false );
check_legacy( [ [ 'url' => $current_url ] ] === $indexer->wanted_pages(), 'The service asks for the stored path and query on the current home' );
BackgroundEngine::step( [ [ 'url' => $current_url, 'status' => 200, 'html' => '<p>Our shopping cart</p>' ] ] );
foreach ( array_slice( $ids, 1 ) as $id ) {
	check_legacy( $current_url === get_post_meta( $id, '_twp_url', true ), 'A successful read rewrites the stored queue address' );
	$manifest = get_post_meta( $id, '_twp_delivery_manifest', true );
	foreach ( $manifest['groups'] as $hash => $markers ) {
		$GLOBALS['wp_test_cache_lookup_items'][ $hash ] = implode( '', array_map( static fn ( $marker ) => '⟪' . $marker . '⟫Translated cart', $markers ) );
	}
	$job = get_post_meta( $id, '_twp_job_id', true );
	$GLOBALS['wp_test_job_states'][ $job ] = 'done';
	delete_post_meta( $id, '_twp_reader_not_before' );
}
BackgroundEngine::mark_dirty();
BackgroundEngine::step();
foreach ( array_slice( $ids, 1 ) as $id ) {
	check_legacy( (int) get_post_meta( $id, '_twp_translated_at', true ) > 0, 'The rebased target finishes with confirmed delivery' );
}

$ids = legacy_state();
$miss = $ids[1];
update_post_meta( $miss, '_twp_object_type', 'miss' );
BackgroundEngine::step();
check_legacy( 'dead' === get_post_meta( $miss, '_twp_status', true ) && 'missing_source_groups' === get_post_meta( $miss, '_twp_fail_reason', true ), 'A legacy miss without a replay payload has a final visible reason' );

$ids = legacy_state();
foreach ( $ids as $id ) {
	update_post_meta( $id, '_twp_url', $old_url );
}
for ( $attempt = 0; $attempt < 3; ++$attempt ) {
	foreach ( $ids as $id ) {
		delete_post_meta( $id, '_twp_reader_not_before' );
	}
	BackgroundEngine::mark_dirty();
	BackgroundEngine::step( [ [ 'url' => $current_url, 'status' => 404 ] ] );
}
check_legacy( 'dead' === get_post_meta( $ids[0], '_twp_status', true ) && 'page_fetch_404' === get_post_meta( $ids[0], '_twp_fail_reason', true ), 'A removed path is dead with its 404 reason after the retry cap' );
check_legacy( $old_url === get_post_meta( $ids[0], '_twp_url', true ), 'Only a successful read rewrites the saved address' );

$ids = legacy_state();
$first_wait = BackgroundEngine::step();
$second_wait = BackgroundEngine::step();
$third_wait = BackgroundEngine::step();
check_legacy( ! isset( $first_wait['stalled'] ) && ! isset( $second_wait['stalled'] ), 'One or two unserved requests do not trigger a stall warning' );
check_legacy( ! empty( $third_wait['stalled']['rows'] ) && in_array( $ids[0], $third_wait['stalled']['rows'], true ), 'Three due steps with no supplied page identify the waiting source row' );
check_legacy( false !== strpos( \TranslateWP\Functionality\CronFallback::notice_message(), 'three attempts' ), 'Indexing explains the stalled queue' );
$result = BackgroundEngine::step( [ [ 'url' => 'https://example.com/cart/', 'status' => 200, 'html' => '<p>Our shopping cart</p>' ] ] );
check_legacy( ! isset( $result['stalled'] ), 'Making progress clears the stall warning' );

if ( ! class_exists( 'WP_Http' ) ) {
	class WP_Http {
		public static function make_absolute_url( string $location, string $base ): string {
			if ( false === strpos( $location, '://' ) ) { throw new RuntimeException( 'This fixture supplies an absolute Location' ); }
			return $location;
		}
	}
}
foreach ( [ '/category/recipes/', '/cart/', 'https://external.example/private/' ] as $location ) {
	$ids = legacy_state();
	wp_test_set_filter( 'translatewp_engine_service_reads', static fn (): bool => false );
	foreach ( array_slice( $ids, 1 ) as $id ) { wp_delete_post( $id, true ); }
	$GLOBALS['wp_test_home_url'] = 'https://example.com/studio';
	update_post_meta( $ids[0], '_twp_url', 'http://www.old.example/studio/category/recipes' );
	$away = 0 === strpos( $location, 'https:' );
	$target = $away ? $location : 'https://example.com/studio' . $location;
	$GLOBALS['wp_test_remote_get_sequence'] = [
		[ 'response' => [ 'code' => 301 ], 'headers' => [ 'location' => $target ] ],
		[ 'response' => [ 'code' => 200 ], 'body' => '<p>Our studio</p>', 'headers' => [ 'content-type' => 'text/html' ] ],
	];
	$indexer = new Indexer( '', '', null, null, false );
	$indexer->step_read( new \TranslateWP\Components\ReadinessBudget() );
	$requests = $GLOBALS['wp_test_remote_get_urls'];
	check_legacy( 'https://example.com/studio/category/recipes' === $requests[0], 'An old www address is read over HTTPS on the current subdirectory home' );
	check_legacy( $away ? 1 === count( $requests ) : $target === $requests[1], 'The reader follows a canonical slash or a new path only on the same origin' );
	check_legacy( ( $away ? 'dead' : 'done' ) === get_post_meta( $ids[0], '_twp_status', true ), 'The real read outcome is recorded on the requested source row' );
	unset( $GLOBALS['wp_test_remote_get_sequence'] );
}

echo "BackgroundLegacyStateTest: all assertions passed\n";
