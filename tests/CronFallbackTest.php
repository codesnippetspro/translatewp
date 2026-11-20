<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\BackgroundSwitch;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

function check_fallback( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ] ] );
update_option( LexiconChanges::SEEDED_OPTION, true, false );
update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
$cron = new CronFallback();
check_fallback( ! $cron->is_behind(), 'An idle connected site is not behind' );
update_option( Indexer::SPINE_PENDING_OPTION, true, false );
update_option( BackgroundEngine::LAST_STEP_OPTION, time() - 901, false );
check_fallback( $cron->is_behind(), 'Pending work without a successful driver for fifteen minutes is behind' );
update_option( BackgroundEngine::LAST_STEP_OPTION, time(), false );
check_fallback( ! $cron->is_behind(), 'A recent successful step suppresses the late-work warning' );
foreach ( [ 'page_tls_failed' => 'HTTPS certificate', 'page_fetch_401' => 'HTTP authentication', 'page_fetch_403' => 'security rules', 'page_parser_unavailable' => 'DOM extension' ] as $code => $phrase ) {
	update_option( Indexer::READER_FAILURE_OPTION, $code, false );
	check_fallback( str_contains( CronFallback::notice_message(), $phrase ) && str_contains( CronFallback::notice_message(), 'Indexing' ), 'Every reader cause has an actionable Indexing fallback' );
}
delete_option( Indexer::READER_FAILURE_OPTION );
check_fallback( str_contains( CronFallback::notice_message(), 'service has not reached' ), 'A stalled engine is described without pretending work succeeded' );
BackgroundSwitch::observe( [ 'backgroundPaused' => true ] );
check_fallback( str_contains( CronFallback::notice_message(), 'paused' ), 'Pause is stated plainly' );
BackgroundSwitch::observe( [ 'backgroundPaused' => false ] );
define( 'DISABLE_WP_CRON', true );
check_fallback( str_contains( CronFallback::notice_message(), 'WP-Cron is disabled' ), 'Disabled WP-Cron is named as the fallback cause' );
fwrite( STDOUT, "CronFallbackTest: all assertions passed\n" );
