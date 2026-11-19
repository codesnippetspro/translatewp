<?php
/**
 * A plugin update fires no activation hook, so the background engine must be
 * installed by the version check on the first request after the update, and
 * the events the previous release scheduled for callbacks that no longer exist
 * must be cleared. That request can be a visitor's, so it does no queue scan
 * and no remote request.
 */

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\Migrations;
use TranslateWP\Functionality\ReadinessReconcile;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

define( 'TRANSLATEWP_VERSION', '1.1.2' );
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
}
if ( ! defined( 'TRANSLATEWP_PATH' ) ) {
	define( 'TRANSLATEWP_PATH', dirname( __DIR__ ) . '/' );
}

class EngineUpgradeCountingWpdb extends WP_Testwpdb {
	public int $reads = 0;

	public function query( string $query ) {
		$this->reads++;
		return parent::query( $query );
	}

	public function get_col( string $query ): array {
		$this->reads++;
		return parent::get_col( $query );
	}

	public function get_var( string $query ) {
		$this->reads++;
		return parent::get_var( $query );
	}
}

$failures = 0;

function check_upgrade( bool $ok, string $message ): void {
	global $failures;
	if ( ! $ok ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

/** A site as released 1.1.1 left it: its markers set and its own events scheduled. */
function released_site( string $stored ): void {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ] ] );
	$GLOBALS['wpdb']                         = new EngineUpgradeCountingWpdb();
	$GLOBALS['wp_test_get_posts_calls']      = [];
	$GLOBALS['wp_test_remote_request_calls'] = [];
	if ( '' !== $stored ) {
		update_option( Migrations::VERSION_OPTION, $stored );
		update_option( Migrations::JOB_CLEANUP_OPTION, [], false );
		update_option( Migrations::TRANSLATED_MARKS_OPTION, time(), false );
		update_option( Migrations::LEXICON_PRIVATE_CACHE_PURGED_OPTION, time(), true );
		wp_schedule_single_event( time() + 30, ReadinessReconcile::HOOK );
		wp_schedule_single_event( time() + 30, TranslationStoreSync::BACKFILL_HOOK );
		wp_schedule_single_event( time() + 30, BackgroundEngine::CRON_HOOK );
	}
	$GLOBALS['wp_test_scheduled_log'] = [];
}

function engine_installed(): bool {
	return false !== get_option( BackgroundEngine::STARTED_OPTION, false )
		&& false !== wp_next_scheduled( BackgroundEngine::CRON_HOOK )
		&& false !== wp_next_scheduled( BackgroundEngine::ELIGIBILITY_HOOK );
}

function orphans_cleared(): bool {
	return false === wp_next_scheduled( ReadinessReconcile::HOOK )
		&& false === wp_next_scheduled( TranslationStoreSync::BACKFILL_HOOK );
}

function no_heavy_work( string $case ): void {
	check_upgrade( [] === $GLOBALS['wp_test_get_posts_calls'], $case . ': the version check must not query posts (queue rows)' );
	check_upgrade( 0 === $GLOBALS['wpdb']->reads, $case . ': the version check must not run database queries' );
	check_upgrade( [] === $GLOBALS['wp_test_remote_request_calls'] && [] === $GLOBALS['wp_test_remote_get_urls'] && [] === $GLOBALS['wp_test_remote_posts'], $case . ': the version check must not send remote requests' );
}

// --- Update from 1.1.1, no activation hook: the first request installs the engine once ---
released_site( '1.1.1' );
Migrations::maybe_run();
check_upgrade( engine_installed(), 'Update from 1.1.1: the first request must install the engine' );
check_upgrade( TRANSLATEWP_VERSION === get_option( Migrations::VERSION_OPTION ), 'Update from 1.1.1: the version marker must move to the code version' );
check_upgrade( orphans_cleared(), 'Update from 1.1.1: the readiness and store backfill events of 1.1.1 must be cleared' );
check_upgrade( 1 === count( array_filter( $GLOBALS['wp_test_scheduled_log'], static fn ( array $event ): bool => BackgroundEngine::WAKE_HOOK === $event['hook'] ) ), 'Update from 1.1.1: the engine install must run exactly once' );
no_heavy_work( 'Update from 1.1.1' );
$started = get_option( BackgroundEngine::STARTED_OPTION );
$GLOBALS['wp_test_scheduled_log'] = [];
unset( $GLOBALS['wp_test_scheduled'][ BackgroundEngine::WAKE_HOOK ] );
Migrations::maybe_run();
check_upgrade( [] === $GLOBALS['wp_test_scheduled_log'], 'Update from 1.1.1: the second request must not install the engine again' );
check_upgrade( $started === get_option( BackgroundEngine::STARTED_OPTION ), 'Update from 1.1.1: the second request must keep the engine start time' );

// --- An obsolete minute drain becomes one bootstrap fallback after the service grace ---
released_site( '1.1.1' );
$drain_due = wp_next_scheduled( BackgroundEngine::CRON_HOOK );
Migrations::maybe_run();
check_upgrade( wp_next_scheduled( BackgroundEngine::CRON_HOOK ) >= time() + BackgroundEngine::FALLBACK_SECONDS, 'Update from 1.1.1: bootstrap waits for the service grace instead of preserving an obsolete minute loop' );

// --- A stored version above the code version (a development build): engine still installed ---
released_site( '9.9.9' );
Migrations::maybe_run();
check_upgrade( false === get_option( BackgroundEngine::STARTED_OPTION, false ), 'An already-current visitor request does not inspect or install the engine' );
Migrations::install_engine_if_needed();
check_upgrade( engine_installed(), 'Stored version above the code version: the engine must still be installed' );
check_upgrade( orphans_cleared(), 'Stored version above the code version: the 1.1.1 events must be cleared' );
check_upgrade( '9.9.9' === get_option( Migrations::VERSION_OPTION ), 'Stored version above the code version: the marker is not lowered' );
no_heavy_work( 'Stored version above the code version' );

// --- Fresh install without the activation hook ---
released_site( '' );
Migrations::maybe_run();
check_upgrade( engine_installed(), 'Fresh install: the engine must be installed' );
check_upgrade( TRANSLATEWP_VERSION === get_option( Migrations::VERSION_OPTION ), 'Fresh install: the version marker must be stored' );
check_upgrade( [] === $GLOBALS['wp_test_remote_request_calls'] && [] === $GLOBALS['wp_test_remote_posts'], 'Fresh install: the version check must not send remote requests' );

// --- Fresh install through activation, then the first request ---
released_site( '' );
Migrations::seed_for_fresh_install();
check_upgrade( engine_installed(), 'Activation: the engine must be installed' );
$GLOBALS['wp_test_scheduled_log'] = [];
Migrations::maybe_run();
check_upgrade( [] === $GLOBALS['wp_test_scheduled_log'], 'Activation then a request: the engine must not be installed again' );

// --- Stored version equal to the code version, engine installed: nothing runs ---
released_site( TRANSLATEWP_VERSION );
update_option( BackgroundEngine::STARTED_OPTION, 1700000000, false );
$GLOBALS['wp_test_scheduled'] = [];
Migrations::maybe_run();
check_upgrade( [] === $GLOBALS['wp_test_scheduled_log'], 'Current version: nothing may be scheduled' );
check_upgrade( 1700000000 === get_option( BackgroundEngine::STARTED_OPTION ), 'Current version: the engine start time must stay' );
no_heavy_work( 'Current version' );

foreach ( [ 'connected', 'invalidated', 'disconnected' ] as $connection ) {
	released_site( TRANSLATEWP_VERSION );
	if ( 'disconnected' === $connection ) { \TranslateWP\Components\Options::update( 'site', [] ); }
	else { \TranslateWP\Components\Options::update( 'site.connection_status', $connection ); }
	$GLOBALS['wp_test_after_option_read'][ BackgroundEngine::STARTED_OPTION ] = static function (): void {
		throw new RuntimeException( 'A current-version page view must not even read the engine start option' );
	};
	Migrations::maybe_run();
	no_heavy_work( 'Current page view: ' . $connection );
	unset( $GLOBALS['wp_test_after_option_read'][ BackgroundEngine::STARTED_OPTION ] );
}
released_site( '1.1.1' );
\TranslateWP\Components\Options::update( 'site.connection_status', 'invalidated' );
Migrations::maybe_run();
no_heavy_work( 'An invalidated upgrade uses stored credentials without a probe' );

// --- Multisite: each site installs its own engine on its own first request ---
released_site( '1.1.1' );
$GLOBALS['wp_test_multisite'] = true;
Migrations::maybe_run();
check_upgrade( engine_installed(), 'Multisite: the main site must install the engine' );
switch_to_blog( 2 );
\TranslateWP\Components\Options::update( 'site', [ 'id' => '00000000-0000-4000-8000-000000000002', 'token' => 'subsite-token' ] );
update_option( Migrations::VERSION_OPTION, '1.1.1' );
update_option( Migrations::JOB_CLEANUP_OPTION, [], false );
update_option( Migrations::TRANSLATED_MARKS_OPTION, time(), false );
update_option( Migrations::LEXICON_PRIVATE_CACHE_PURGED_OPTION, time(), true );
check_upgrade( false === get_option( BackgroundEngine::STARTED_OPTION, false ), 'Multisite: the engine marker is per site' );
Migrations::maybe_run();
check_upgrade( false !== get_option( BackgroundEngine::STARTED_OPTION, false ), 'Multisite: a subsite must install its own engine on its first request' );
check_upgrade( TRANSLATEWP_VERSION === get_option( Migrations::VERSION_OPTION ), 'Multisite: a subsite must move its own version marker' );
restore_current_blog();

if ( $failures ) {
	exit( 1 );
}
echo "EngineUpgradeTest passed\n";
