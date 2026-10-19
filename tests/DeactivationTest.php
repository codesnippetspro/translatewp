<?php
/**
 * Deactivating the plugin leaves no event of the plugin scheduled, whatever its
 * arguments; a network deactivation does so on every site, in pages of 100.
 */

declare(strict_types=1);

use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/uninstall-test-stubs.php';

$log_file = (string) tempnam( sys_get_temp_dir(), 'twp-deactivation-log' );
ini_set( 'error_log', $log_file );
ini_set( 'display_errors', 'stderr' );

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function schedule_on( int $blog_id ): void {
	switch_to_blog( $blog_id );
	uninstall_test_schedule( 'translatewp_deliver_translated_email', [ 42 ] );
	uninstall_test_schedule( 'translatewp_rewarm_debounce', [ 7, 'page' ] );
	uninstall_test_schedule( 'translatewp_prewarm_email_wording' );
	uninstall_test_schedule( 'translatewp_translation_store_backfill' );
	uninstall_test_schedule( 'translatewp_readiness_reconcile' );
	uninstall_test_schedule( 'translatewp_context_scan_run' );
	uninstall_test_schedule( 'woocommerce_cleanup_sessions' );
	restore_current_blog();
}

function plugin_events( int $blog_id ): array {
	return array_values( array_filter( uninstall_test_scheduled( $blog_id ), static fn ( string $hook ): bool => 0 === strpos( $hook, 'translatewp_' ) ) );
}

// --- a single site ---
schedule_on( 1 );
$GLOBALS['uninstall_test_options']['translatewp_held_email_a1'] = [
	'to'          => 'customer@example.com',
	'subject'     => 'Your order',
	'message'     => 'Thank you for your order',
	'headers'     => '',
	'attachments' => [],
	'locale'      => 'fr_FR',
	'held_at'     => 1700000000,
];
Lyfecycle::deactivate( false );
assert_true( 1 === count( $GLOBALS['uninstall_test_mails'] ) && 'Your order' === $GLOBALS['uninstall_test_mails'][0]['subject'], 'deactivation must send a held order email, untranslated, before its schedule goes' );
assert_true( ! isset( $GLOBALS['uninstall_test_options']['translatewp_held_email_a1'] ), 'a held email that was sent is no longer held' );
$as_hooks = array_column( array_filter( $GLOBALS['uninstall_test_as_unscheduled'], static fn ( array $call ): bool => null === $call['args'] && 'translatewp' === $call['group'] ), 'hook' );
foreach ( \TranslateWP\Components\CleanupInventory::SCHEDULED_HOOKS as $hook ) {
	assert_true( in_array( $hook, $as_hooks, true ), "deactivation must unschedule the Action Scheduler actions of {$hook}" );
}
assert_true( [] === plugin_events( 1 ), 'deactivation left events scheduled: ' . implode( ', ', plugin_events( 1 ) ) );
assert_true( in_array( 'woocommerce_cleanup_sessions', uninstall_test_scheduled( 1 ), true ), 'deactivation unscheduled an event of another plugin' );
assert_true( 1 === did_action( 'TranslateWP/deactivation' ), 'deactivation must still fire TranslateWP/deactivation' );

// --- a held email the mailer refuses is kept and logged ---
$GLOBALS['uninstall_test_mails']       = [];
$GLOBALS['uninstall_test_mail_result'] = false;
$GLOBALS['uninstall_test_options']['translatewp_held_email_b2'] = [ 'to' => 'customer@example.com', 'subject' => 'Refund', 'message' => 'Refunded', 'headers' => '', 'attachments' => [] ];
Lyfecycle::deactivate( false );
unset( $GLOBALS['uninstall_test_mail_result'] );
assert_true( isset( $GLOBALS['uninstall_test_options']['translatewp_held_email_b2'] ), 'a held email the mailer refused must stay held' );
assert_true( 1 === preg_match( '/refused 1 held email.* stay held/', (string) file_get_contents( $log_file ) ), 'a held email the mailer refused must be logged, by count, as staying held' );
unset( $GLOBALS['uninstall_test_options']['translatewp_held_email_b2'] );

// --- a network deactivation: every site, in pages of 100 ---
$GLOBALS['uninstall_test_multisite']   = true;
$GLOBALS['uninstall_test_sites']       = range( 1, 150 );
$GLOBALS['uninstall_test_cron']        = [];
$GLOBALS['uninstall_test_get_sites']   = [];
foreach ( [ 1, 100, 101, 150 ] as $blog_id ) {
	schedule_on( $blog_id );
}
Lyfecycle::deactivate( true );
foreach ( [ 1, 100, 101, 150 ] as $blog_id ) {
	assert_true( [] === plugin_events( $blog_id ), "network deactivation left events on blog {$blog_id}: " . implode( ', ', plugin_events( $blog_id ) ) );
}
assert_true( [] !== $GLOBALS['uninstall_test_get_sites'], 'network deactivation did not list the sites' );
foreach ( $GLOBALS['uninstall_test_get_sites'] as $args ) {
	assert_true( 100 === (int) ( $args['number'] ?? 0 ), 'network deactivation asked for ' . var_export( $args['number'] ?? null, true ) . ' sites at once' );
	assert_true( 1 === ( $args['network_id'] ?? null ), 'network deactivation must list the sites of the current network only' );
}
assert_true( 1 === get_current_blog_id(), 'network deactivation must end on the blog it started on' );

// --- deactivation on one site of a network: that site only ---
$GLOBALS['uninstall_test_cron'] = [];
schedule_on( 5 );
schedule_on( 6 );
switch_to_blog( 5 );
Lyfecycle::deactivate( false );
restore_current_blog();
assert_true( [] === plugin_events( 5 ), 'deactivation on one site left its events: ' . implode( ', ', plugin_events( 5 ) ) );
assert_true( 6 === count( plugin_events( 6 ) ), 'deactivation on one site unscheduled the events of another site' );

// --- the release stops at one time limit shared by the sites, and logs only how many are left ---
$GLOBALS['uninstall_test_cron']  = [];
$GLOBALS['uninstall_test_mails'] = [];
$GLOBALS['uninstall_test_sites'] = [ 1, 2 ];
file_put_contents( $log_file, '' );
foreach ( [ 1, 2 ] as $blog_id ) {
	switch_to_blog( $blog_id );
	for ( $i = 0; $i < 4; $i++ ) {
		update_option( 'translatewp_held_email_' . $blog_id . '_' . $i, [ 'to' => 'customer@example.com', 'subject' => 'Your order', 'message' => 'Thanks', 'headers' => '', 'attachments' => [] ] );
	}
	restore_current_blog();
}
$GLOBALS['uninstall_test_now']          = 1000.0;
$GLOBALS['uninstall_test_mail_seconds'] = 1.0;
Lyfecycle::set_clock( static fn (): float => $GLOBALS['uninstall_test_now'] );
Lyfecycle::deactivate( true );
Lyfecycle::set_clock( null );
$GLOBALS['uninstall_test_mail_seconds'] = 0.0;
$sent = count( $GLOBALS['uninstall_test_mails'] );
$log  = (string) file_get_contents( $log_file );
assert_true( $sent > 0 && $sent < 8, "the release must stop at its time limit, sent {$sent} of 8" );
assert_true( 2 === count( array_unique( array_column( $GLOBALS['uninstall_test_mails'], 'blog' ) ) ), 'the time limit is shared: the second site still sends once the first is done' );
preg_match_all( '/(\d+) held emails? (?:stay|were|was)/', $log, $left );
assert_true( 8 - $sent === array_sum( array_map( 'intval', $left[1] ) ), 'the log must count the held emails left, log: ' . $log );
assert_true( false === strpos( $log, 'customer@example.com' ) && false === strpos( $log, 'Your order' ), 'the log must not carry an address or the email' );
$kept = 0;
foreach ( [ 1, 2 ] as $blog_id ) {
	switch_to_blog( $blog_id );
	for ( $i = 0; $i < 4; $i++ ) {
		$kept += false !== get_option( 'translatewp_held_email_' . $blog_id . '_' . $i ) ? 1 : 0;
	}
	restore_current_blog();
}
assert_true( 8 - $sent === $kept, 'the held emails the time limit left must stay held' );

$GLOBALS['uninstall_test_multisite'] = false;
unlink( $log_file );

if ( $failures > 0 ) {
	fwrite( STDERR, "DeactivationTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "DeactivationTest ok\n" );
