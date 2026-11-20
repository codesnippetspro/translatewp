<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

define( 'TRANSLATEWP_ENGINE_TRACE', true );

class EngineCountingDatabase extends WP_Testwpdb {
	public int $num_queries = 0;
	public function query( string $query ) { ++$this->num_queries; return parent::query( $query ); }
	public function get_col( string $query ): array { ++$this->num_queries; return parent::get_col( $query ); }
	public function get_results( string $query ): array { ++$this->num_queries; return parent::get_results( $query ); }
	public function get_var( string $query ) { ++$this->num_queries; return parent::get_var( $query ); }
}

function assert_step_cost( array $trace ): void {
	if ( $trace['ms'] >= 8000 ) {
		throw new RuntimeException( 'Whole engine step exceeded eight seconds: ' . wp_json_encode( $trace ) );
	}
	if ( ! empty( $trace['inputRows'] ) ) {
		return;
	}
	$limits = [ 'read' => 1500, 'visitor_push' => 900, 'background_push' => 900 ];
	$limit = $limits[ $trace['phase'] ] ?? 450;
	if ( $trace['queries'] > $limit ) {
		throw new RuntimeException( 'Whole engine step exceeded the phase budget: ' . wp_json_encode( $trace ) );
	}
}

if ( isset( $argv[1] ) ) {
	$steps = 0;
	foreach ( new SplFileObject( $argv[1] ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$record = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
		if ( isset( $record['trace'] ) ) {
			assert_step_cost( $record['trace'] );
			++$steps;
		}
	}
	if ( ! $steps ) {
		throw new RuntimeException( 'No signed-step traces in the drive' );
	}
	echo "$steps live traces within their budgets\n";
}

$languages = [ 'en', 'it', 'es', 'de', 'fr', 'lv', 'pt', 'nl', 'sv', 'da', 'fi', 'et' ];
$list = [];
foreach ( $languages as $lang ) {
	$list[$lang] = [ 'locale' => $lang, 'active' => true, 'default' => 'en' === $lang ];
}
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture' ], 'languages' => [ 'list' => $list ] ] );
update_option( Indexer::SPINE_CHECKED_OPTION, true );
update_option( LexiconChanges::SEEDED_OPTION, true );
for ( $n = 0; $n < 40; ++$n ) {
	foreach ( array_slice( $languages, 1 ) as $lang ) {
		wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private',
			'post_content' => wp_json_encode( [ [ 'groupId' => 'title', 'markerPayload' => '⟪item_0001⟫Dance class page ' . $n ] ] ),
			'meta_input' => [ '_twp_language' => $lang, '_twp_stage' => 'push', '_twp_status' => 'pending', '_twp_retranslate' => 1, '_twp_object_type' => 'page', '_twp_object_id' => 100 + $n, '_twp_url' => home_url( "/page-$n/" ) ],
		] );
	}
}
$GLOBALS['wpdb'] = new EngineCountingDatabase();
$GLOBALS['wp_test_count_core_queries'] = true;
$trace = BackgroundEngine::step()['trace'];
if ( $trace['rows'] <= 0 || ! array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $r ): bool => str_ends_with( $r['path'], '/batches' ) ) || $trace['queries'] !== $GLOBALS['wpdb']->num_queries || $trace['queries'] <= 0 || $trace['queries'] >= 450 ) {
	throw new RuntimeException( 'Working step on 440 rows exceeded its query budget or did no work: ' . wp_json_encode( $trace ) );
}
assert_step_cost( $trace );
echo 'EngineStepCostTest: ' . wp_json_encode( $trace ) . "\n";

update_option( \TranslateWP\Functionality\KeepWarm::REBUILD_CURSOR, [ 'after' => 0, 'languages' => array_slice( $languages, 1 ) ], false );
$trace = BackgroundEngine::step()['trace'];
$cursor = get_option( \TranslateWP\Functionality\KeepWarm::REBUILD_CURSOR );
if ( false !== $cursor || $trace['rows'] < 440 ) {
	throw new RuntimeException( 'Settings cursor failed its working-step budget: ' . wp_json_encode( $trace ) );
}
assert_step_cost( $trace );
echo 'Settings rebuild: ' . wp_json_encode( $trace ) . "\n";

add_filter( 'translatewp_engine_service_reads', static fn(): bool => true );
$pages = [];
$sources = [];
for ( $n = 0; $n < 3; ++$n ) {
	$post = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
	$url = home_url( "/class-$n/" );
	$sources[] = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'meta_input' => [
		'_twp_language' => '', '_twp_status' => 'pending', '_twp_stage' => 'extract', '_twp_object_type' => 'page',
		'_twp_object_id' => $post, '_twp_url' => $url, '_twp_translate_on_read' => 1,
	] ] );
	$pages[] = [ 'url' => $url, 'status' => 200, 'html' => "<p>Available dance classes for group $n.</p>" ];
}
$trace = BackgroundEngine::step( $pages )['trace'];
foreach ( $sources as $id ) {
	if ( 'done' !== get_post_meta( $id, '_twp_status', true ) ) {
		throw new RuntimeException( 'All three supplied pages must exercise extraction on this step' );
	}
}
assert_step_cost( $trace );
echo 'Three-page read: ' . wp_json_encode( $trace ) . "\n";
