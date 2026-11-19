<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_convergence( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => [
	'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ],
	'es' => [ 'locale' => 'es', 'active' => true ],
] ] ] );
update_option( LexiconChanges::SEEDED_OPTION, true, false ); update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
update_option( BackgroundEngine::VISITOR_STREAK_OPTION, 3, false );
Options::update( 'cache.lexicon_language_versions', [ 'es' => 8 ] );
$meta = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/background/completed-page-refill.json' ), true );
$scope = new ReflectionMethod( TranslationReadiness::class, 'scope' ); $scope->setAccessible( true );
$meta['_twp_delivery_manifest']['scope'] = $scope->invoke( null, 'es' );
foreach ( $meta['_twp_delivery_manifest']['groups'] as $hash => $markers ) {
	$GLOBALS['wp_test_cache_lookup_items'][ $hash ] = implode( '', array_map( static fn ( string $marker ): string => '⟪' . $marker . '⟫Texto traducido ', $markers ) );
}
$ids = [];
for ( $i = 0; $i < 15; ++$i ) {
	$row = $meta; $row['_twp_object_id'] = 10 + $i;
	$row['_twp_job_id'] = sprintf( '00000000-0000-4000-8000-%012d', $i + 1 );
	$GLOBALS['wp_test_job_states'][ $row['_twp_job_id'] ] = 'done';
	$ids[] = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'post_content' => '', 'meta_input' => $row ] );
}
for ( $i = 0; $i < 20; ++$i ) {
	$row = [ '_twp_object_type' => 'miss', '_twp_object_id' => 1000 + $i, '_twp_language' => 'es', '_twp_url' => 'http://example.com/my-account/', '_twp_stage' => 'push', '_twp_status' => 'done', '_twp_translated_at' => 1794836957, '_twp_job_outcome' => 'done' ];
	wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'post_content' => '', 'meta_input' => $row ] );
}
$steps = 0;
do {
	$result = BackgroundEngine::step(); ++$steps;
} while ( 'done' !== $result['state'] && $steps < 4 );
foreach ( $ids as $id ) {
	check_convergence( (int) get_post_meta( $id, '_twp_translated_at', true ) > 0, 'Every completed refill is confirmed within four bounded steps' );
}
check_convergence( 'done' === $result['state'], 'The completed refill reaches done within four bounded steps with legacy visitor rows present' );
check_convergence( 0 === $result['pending']['visitor'], 'Previously translated legacy misses do not become new visitor work' );
echo 'BackgroundConvergenceTest: all assertions passed in ' . $steps . " step(s)\n";
