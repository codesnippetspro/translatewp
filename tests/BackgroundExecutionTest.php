<?php

declare(strict_types=1);

use TranslateWP\Functionality\BackgroundWork;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;
use TranslateWP\Functionality\ReadinessReconcile;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_execution( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function fastcgi_finish_request(): bool { check_execution( false, 'No background queue driver may flush a visitor response' ); return true; }
function litespeed_finish_request(): bool { check_execution( false, 'No background queue driver may flush a visitor response' ); return true; }
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ] ] );
new Indexer(); new LexiconChanges(); new BackgroundWork(); new CronFallback(); new ReadinessReconcile(); new TranslationStoreSync();
$GLOBALS['wp_test_get_posts_calls'] = [];
$GLOBALS['wp_test_remote_request_calls'] = [];
$GLOBALS['engine_option_reads'] = [];
foreach ( [ 'translatewp_background_paused', 'translatewp_engine_state', 'translatewp_engine_last_service', 'translatewp_engine_phase', 'translatewp_spine_checked', 'translatewp_lexicon_changes_seeded' ] as $name ) {
	$GLOBALS['wp_test_after_option_read'][ $name ] = static function () use ( $name ): void { $GLOBALS['engine_option_reads'][] = $name; };
}
foreach ( [ 'Mozilla/5.0', 'Googlebot' ] as $agent ) {
	$_SERVER['HTTP_USER_AGENT'] = $agent;
	do_action( 'init' ); do_action( 'template_redirect' ); do_action( 'shutdown' );
}
check_execution( [] === $GLOBALS['wp_test_get_posts_calls'], 'Idle visitor and crawler requests perform zero queue queries' );
check_execution( [] === $GLOBALS['wp_test_remote_request_calls'], 'Visitor and crawler hooks make no service or self requests' );
check_execution( [] === $GLOBALS['engine_option_reads'], 'Visitor and crawler hooks read no new engine state' );
check_execution( ! method_exists( CronFallback::class, 'maybe_run_on_front_end' ) && ! method_exists( CronFallback::class, 'run_after_flush' ), 'Both front-end queue entry points are removed' );
check_execution( [] === $GLOBALS['wp_test_scheduled'], 'A visitor never schedules background drivers' );
fwrite( STDOUT, "BackgroundExecutionTest: all assertions passed\n" );
