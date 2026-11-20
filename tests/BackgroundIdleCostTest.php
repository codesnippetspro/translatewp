<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_idle( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'default' => true, 'active' => true ], 'it' => [ 'locale' => 'it', 'active' => true ] ] ] ] );
update_option( LexiconChanges::SEEDED_OPTION, true, false ); update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
for ( $i = 0; $i < 1000; ++$i ) {
    wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'post_content' => '', 'meta_input' => [ '_twp_object_type' => 'page', '_twp_object_id' => 1000 + $i, '_twp_language' => 'it', '_twp_stage' => 'push', '_twp_status' => 'done', '_twp_translated_at' => 1794836957 ] ] );
}
BackgroundEngine::mark_dirty();
BackgroundEngine::step();
$GLOBALS['wp_test_get_posts_calls'] = [];
$GLOBALS['wp_test_engine_aggregate_calls'] = 0;
$GLOBALS['wp_test_engine_selects'] = [];
$memory = memory_get_usage();
for ( $i = 0; $i < 10; ++$i ) {
	check_idle( 'done' === BackgroundEngine::step()['state'], 'An idle step returns done' );
}
check_idle( [] === $GLOBALS['wp_test_get_posts_calls'] && 0 === $GLOBALS['wp_test_engine_aggregate_calls'] && [] === $GLOBALS['wp_test_engine_selects'], 'Persisted idle steps perform zero queue queries' );
check_idle( false === wp_next_scheduled( BackgroundEngine::CRON_HOOK ), 'An idle site leaves no recurring minute drain' );
check_idle( memory_get_usage() - $memory < 131072, 'Idle step memory stays bounded' );
$memory_delta = memory_get_usage() - $memory;
$queue = new IndexQueue();
$id = $queue->enqueue_miss_page( [ 'object_type' => 'miss', 'object_id' => 123, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10 ], [ [ 'groupId' => 'one', 'markerPayload' => '⟪item_0001⟫Our shop' ] ] );
$GLOBALS['wp_test_get_posts_calls'] = [];
$result = BackgroundEngine::step();
check_idle( IndexQueue::STATUS_DONE === get_post_meta( $id, '_twp_status', true ), 'An enqueue invalidates the idle marker and the next step pushes it' );
foreach ( $GLOBALS['wp_test_get_posts_calls'] as $query ) {
	if ( IndexQueue::POST_TYPE === ( $query['post_type'] ?? '' ) ) {
		check_idle( (int) ( $query['posts_per_page'] ?? -1 ) > 0 && (int) $query['posts_per_page'] <= 50, 'A working step materializes only limited queue selections: ' . wp_json_encode( $query ) );
	}
}
for ( $i = 0; $i < 6; ++$i ) { BackgroundEngine::step(); }
foreach ( $GLOBALS['wp_test_get_posts_calls'] as $query ) {
    if ( IndexQueue::POST_TYPE === ( $query['post_type'] ?? '' ) ) {
        check_idle( (int) ( $query['posts_per_page'] ?? -1 ) > 0 && (int) $query['posts_per_page'] <= 50, 'Readiness and maintenance also use bounded selections: ' . wp_json_encode( $query ) );
    }
}
echo "BackgroundIdleCostTest: 1000 settled rows, 10 idle steps, zero queue selects/counts; memory delta " . $memory_delta . " bytes; all assertions passed\n";
