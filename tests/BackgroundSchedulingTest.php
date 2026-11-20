<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\BackgroundWork;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;
use TranslateWP\Functionality\ReadinessReconcile;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';
function check_schedule( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function scheduling_fixture( bool $connected = true ): void {
	wp_test_reset( $connected ? [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ] ] : [] );
	update_option( LexiconChanges::SEEDED_OPTION, true, false ); update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
}
scheduling_fixture();
wp_schedule_single_event( time(), ReadinessReconcile::HOOK );
wp_schedule_single_event( time(), TranslationStoreSync::BACKFILL_HOOK );
wp_schedule_single_event( time(), BackgroundEngine::CRON_HOOK );
BackgroundEngine::install();
check_schedule( ! wp_next_scheduled( ReadinessReconcile::HOOK ) && ! wp_next_scheduled( TranslationStoreSync::BACKFILL_HOOK ), 'Upgrade removes orphaned readiness and store events' );
check_schedule( ! wp_next_scheduled( BackgroundEngine::CRON_HOOK ), 'An idle connected site leaves no recurring minute event' );
check_schedule( false !== wp_next_scheduled( BackgroundEngine::ELIGIBILITY_HOOK ), 'The hourly eligibility driver remains installed' );
new BackgroundWork();
$scheduled = $GLOBALS['wp_test_scheduled'];
for ( $i = 0; $i < 3; ++$i ) { do_action( 'admin_init' ); }
check_schedule( $scheduled === $GLOBALS['wp_test_scheduled'], 'Admin heartbeat requests do not reinstall a wake' );
scheduling_fixture( false ); BackgroundEngine::install(); BackgroundEngine::cron();
check_schedule( [] === $GLOBALS['wp_test_scheduled'], 'Disconnected sites install no engine timers' );
scheduling_fixture();
$queue = new IndexQueue();
$id = $queue->enqueue_miss_page( [ 'object_type' => 'miss', 'object_id' => 1, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 10 ], [ [ 'groupId' => 'shop', 'markerPayload' => '⟪item_0001⟫Our shop' ] ] );
update_option( BackgroundEngine::LAST_SERVICE_OPTION, time(), false );
BackgroundEngine::schedule();
check_schedule( wp_next_scheduled( BackgroundEngine::CRON_HOOK ) >= time() + 299, 'A pending-work watchdog cannot run until five minutes of service silence' );
$deadline = wp_next_scheduled( BackgroundEngine::CRON_HOOK );
BackgroundEngine::schedule( 600 );
check_schedule( $deadline === wp_next_scheduled( BackgroundEngine::CRON_HOOK ), 'A later enqueue never postpones an existing drain' );
BackgroundEngine::cron();
check_schedule( IndexQueue::STATUS_PENDING === get_post_meta( $id, '_twp_status', true ), 'A healthy service suppresses cron execution' );
update_option( BackgroundEngine::LAST_SERVICE_OPTION, time() - 301, false );
BackgroundEngine::cron();
check_schedule( IndexQueue::STATUS_DONE === get_post_meta( $id, '_twp_status', true ), 'Pending work transfers to cron after service silence' );
check_schedule( ! wp_next_scheduled( BackgroundEngine::WAKE_HOOK ), 'The step does not schedule a redundant service wake' );
$queue->mark_translated( [ $id ] );
BackgroundEngine::cron();
check_schedule( ! wp_next_scheduled( BackgroundEngine::CRON_HOOK ), 'The final completed row removes the watchdog' );
BackgroundEngine::service_step( [ 'pause' => true ] );
BackgroundEngine::service_step( [ 'pause' => false ] );
check_schedule( ! wp_next_scheduled( BackgroundEngine::WAKE_HOOK ), 'Unpausing in a signed step never wakes the service again' );
echo "BackgroundSchedulingTest: all assertions passed\n";
