<?php
/**
 * Deleting the plugin on a single site: with "Delete all data on uninstall" on,
 * no wp_options row of the plugin is left (transients and site transients
 * included) and no event of the plugin stays scheduled, whatever its arguments.
 * With the setting off every option stays, the connection credentials too; only
 * the schedules and the index queue posts go.
 */

declare(strict_types=1);

use TranslateWP\Components\CleanupInventory;
use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/uninstall-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

// error_log() writes here, so each failure the delete logs can be read back.
$GLOBALS['uninstall_log'] = (string) tempnam( sys_get_temp_dir(), 'twp-uninstall-log' );
ini_set( 'error_log', $GLOBALS['uninstall_log'] );
ini_set( 'display_errors', 'stderr' );

function uninstall_log(): string {
	$log = (string) file_get_contents( $GLOBALS['uninstall_log'] );
	file_put_contents( $GLOBALS['uninstall_log'], '' );
	return $log;
}

const CREDENTIALS = [
	'id'    => '00000000-0000-4000-8000-000000000001',
	'token' => 'site-token',
];

const HELD_EMAIL = [
	'to'          => 'customer@example.com',
	'subject'     => 'Your order',
	'message'     => 'Thank you for your order',
	'headers'     => '',
	'attachments' => [],
	'locale'      => 'fr_FR',
	'held_at'     => 1700000000,
];

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

const PRO_ADDON_ROWS = [
	'translatewp_pro_addon_capabilities_last_known',
	'translatewp_pro_alt_text',
	'translatewp_pro_alt_text_queue',
	'translatewp_pro_indexnow_key',
	'_transient_translatewp_pro_indexnow_debounce',
	'_transient_timeout_translatewp_pro_indexnow_debounce',
	'translatewp_network_ai',
	'translatewp_network_ai_source',
];

const OTHER_ROWS = [
	'woocommerce_version'          => '9.0.0',
	'_transient_wc_count'          => 3,
	'translatewpfoo'               => 'not ours: no underscore after the prefix',
	'twpx_other'                   => 'not ours',
	'my_translatewp_setting'       => 'not ours',
	'_transient_twpz_other'        => 'not ours',
	'_site_transient_update_core'  => 'core',
];

/** Every row the 2026-10-09 audit lists, the rows of other plugins, and the Pro addon's rows. */
function seed_site( bool $delete_on_uninstall ): array {
	$GLOBALS['wpdb']                       = new Uninstall_Test_wpdb();
	$GLOBALS['uninstall_test_mails']         = [];
	$GLOBALS['uninstall_test_meta_deletes']  = [];
	$GLOBALS['uninstall_test_as_unscheduled'] = [];
	$GLOBALS['uninstall_test_remote']        = [];
	unset( $GLOBALS['uninstall_test_remote_response'] );
	$GLOBALS['uninstall_test_cron']        = [];
	$GLOBALS['uninstall_test_cache_deletes'] = [];
	$GLOBALS['uninstall_test_options']     = array_merge(
		[
			'translatewp_advanced'                       => [ 'delete_on_uninstall' => $delete_on_uninstall ],
			'translatewp_site'                           => CREDENTIALS,
			'translatewp_languages'                      => [ 'list' => [ 'fr' ] ],
			'translatewp_version'                        => '1.0.0',
			'translatewp_readiness_reconcile_cursor'     => 12,
			'translatewp_language_sync_start_pending'    => 1,
			'translatewp_translation_switch_carried_over' => 1,
			'translatewp_network_settings_scope'         => [ 'groups' => [] ],
			'translatewp_network_settings_defaults'      => [ 'translatewp_advanced' => [ 'delete_on_uninstall' => true ] ],
			'translatewp_held_email_42'                  => HELD_EMAIL,
			'translatewp_pro_capabilities_last_known'    => [ 'alt_text' => true ],
			'translatewp_pro_welcome_seen'               => 1,
			'_transient_translatewp_pro_eligibility'     => [ 'entitled' => true ],
			'_transient_timeout_translatewp_pro_eligibility' => 2000000000,
			'_transient_translatewp_pro_required_notice' => 1,
			'_transient_translatewp_connect_state_abc'   => 'state',
			'_transient_timeout_translatewp_connect_state_abc' => 2000000000,
			'_transient_twp_ready_home'                  => 1,
			'_transient_twp_swept_home'                  => 1,
			'_transient_twp_px_claim_1'                  => 1,
			'_transient_translatewp_cron_kick_' . md5( 'translatewp_index_queue_drain' ) => 1,
			'_transient_translatewp_context_scan_lock'   => 1,
			'_site_transient_translatewp_network_connect_outcome_1' => [ 'ok' => true ],
			'_site_transient_timeout_translatewp_network_connect_outcome_1' => 2000000000,
		],
		array_fill_keys( PRO_ADDON_ROWS, 'pro' ),
		OTHER_ROWS
	);
	$GLOBALS['wpdb']->seed( 'twp_index_item', 30 );
	$GLOBALS['wpdb']->seed( 'lexicon', 4, 2 );
	$GLOBALS['wpdb']->seed( 'translatewp_metric', 6 );
	$GLOBALS['wpdb']->seed( 'page', 2 );
	$GLOBALS['wpdb']->meta_rows[ array_key_last( $GLOBALS['wpdb']->post_rows ) ]['_translatewp_switcher_placement'] = 'header';
	$GLOBALS['wpdb']->meta_rows[ array_key_last( $GLOBALS['wpdb']->post_rows ) ]['_translatewp_exclude']            = '1';

	uninstall_test_schedule( 'translatewp_deliver_translated_email', [ 42 ] );
	uninstall_test_schedule( 'translatewp_rewarm_debounce', [ 7, 'page' ] );
	uninstall_test_schedule( 'translatewp_translation_store_backfill' );
	uninstall_test_schedule( 'translatewp_prewarm_email_wording' );
	uninstall_test_schedule( 'translatewp_readiness_reconcile' );
	uninstall_test_schedule( 'translatewp_index_queue_drain' );
	uninstall_test_schedule( 'woocommerce_cleanup_sessions' );

	return $GLOBALS['uninstall_test_options'];
}

function plugin_rows( array $options ): array {
	return array_values(
		array_filter(
			array_keys( $options ),
			static fn ( string $name ): bool => 1 === preg_match( '/^(_site_transient_timeout_|_site_transient_|_transient_timeout_|_transient_)?(translatewp_|twp_)/', $name )
		)
	);
}

function plugin_hooks(): array {
	return array_values( array_filter( uninstall_test_scheduled( 1 ), static fn ( string $hook ): bool => 0 === strpos( $hook, 'translatewp_' ) ) );
}

// --- every hook the plugin schedules is in the one list deactivation and uninstall clear ---
$root      = dirname( __DIR__ );
$scheduled = [];
foreach ( [ 'Components', 'Functionality', 'Includes' ] as $dir ) {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );
	foreach ( $files as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		$source = (string) file_get_contents( $file->getPathname() );
		preg_match( '/^namespace\s+([^;]+);/m', $source, $namespace );
		preg_match( '/^class\s+(\w+)/m', $source, $class );
		preg_match_all( '/(?:wp_schedule_(?:single_)?event\(\s*(?:[^,()]|\([^()]*\))+,|DebouncedTask::schedule\()\s*(self::\w+|\'[^\']+\')/', $source, $calls );
		foreach ( $calls[1] as $hook ) {
			$scheduled[] = 0 === strpos( $hook, 'self::' )
				? constant( $namespace[1] . '\\' . $class[1] . '::' . substr( $hook, 6 ) )
				: trim( $hook, "'" );
		}
	}
}
$scheduled = array_unique( $scheduled );
assert_true( count( $scheduled ) >= 10, 'the scan of scheduled hooks found only ' . count( $scheduled ) );
foreach ( $scheduled as $hook ) {
	assert_true( in_array( $hook, CleanupInventory::SCHEDULED_HOOKS, true ), "the plugin schedules {$hook}, but deactivation and uninstall do not clear it" );
}

// --- setting on, Pro not installed: no plugin row and no plugin event is left ---
seed_site( true );
Lyfecycle::uninstall();
$left = plugin_rows( $GLOBALS['uninstall_test_options'] );
assert_true( [] === $left, 'a delete with the setting on left plugin rows: ' . implode( ', ', $left ) );
foreach ( OTHER_ROWS as $name => $value ) {
	assert_true( array_key_exists( $name, $GLOBALS['uninstall_test_options'] ), "the delete removed {$name}, which is not the plugin's" );
}
assert_true( ! array_key_exists( 'translatewp_pro_addon_capabilities_last_known', $GLOBALS['uninstall_test_options'] ), 'the capability key the addon writes is left behind with no addon installed' );
assert_true( [] === plugin_hooks(), 'a delete with the setting on left events scheduled: ' . implode( ', ', plugin_hooks() ) );
assert_true( in_array( 'woocommerce_cleanup_sessions', uninstall_test_scheduled( 1 ), true ), 'the delete unscheduled an event of another plugin' );
foreach ( [ 'twp_index_item', 'lexicon', 'translatewp_metric' ] as $type ) {
	assert_true( 0 === $GLOBALS['wpdb']->count_type( $type ), "{$type} posts remain after a delete with the setting on" );
}
assert_true( 2 === $GLOBALS['wpdb']->count_type( 'page' ), 'the delete touched pages' );
$placements = array_filter( $GLOBALS['wpdb']->meta_rows, static fn ( array $meta ): bool => isset( $meta['_translatewp_switcher_placement'] ) );
assert_true( [] === $placements, 'switcher placement meta remains after a delete with the setting on' );
assert_true( in_array( 'options:alloptions', $GLOBALS['uninstall_test_cache_deletes'], true ), 'the cached autoloaded options must be dropped after the rows are deleted' );
assert_true( in_array( 'options:translatewp_readiness_reconcile_cursor', $GLOBALS['uninstall_test_cache_deletes'], true ), 'the cached copy of each deleted option must be dropped' );
$excluded = array_filter( $GLOBALS['wpdb']->meta_rows, static fn ( array $meta ): bool => isset( $meta['_translatewp_exclude'] ) );
assert_true( [] === $excluded, 'page exclusion meta remains after a delete with the setting on' );
foreach ( [ 'translatewp_coexistence_dismissed', 'translatewp_cache_notice_dismissed', 'translatewp_finish_setup_dismissed' ] as $user_meta ) {
	assert_true( in_array( '1:user:' . $user_meta, $GLOBALS['uninstall_test_meta_deletes'], true ), "the user meta {$user_meta} must be deleted for every user" );
}
foreach ( CleanupInventory::SCHEDULED_HOOKS as $hook ) {
	$calls = array_filter( $GLOBALS['uninstall_test_as_unscheduled'], static fn ( array $call ): bool => $hook === $call['hook'] && null === $call['args'] && 'translatewp' === $call['group'] );
	assert_true( [] !== $calls, "the Action Scheduler actions of {$hook} must be unscheduled" );
}
assert_true( 1 === count( $GLOBALS['uninstall_test_mails'] ) && 'Your order' === $GLOBALS['uninstall_test_mails'][0]['subject'] && 'customer@example.com' === $GLOBALS['uninstall_test_mails'][0]['to'], 'a held order email must be sent, untranslated, before the delete removes it' );
$selects = implode( "\n", $GLOBALS['wpdb']->option_selects );
assert_true( false !== strpos( $selects, 'translatewp\\\\_%' ) && false !== strpos( $selects, '_site\\\\_transient\\\\_timeout\\\\_twp\\\\_%' ), 'the prefix patterns must escape their underscores: ' . $selects );

// --- setting on, Pro installed: the rows only the addon writes stay for the addon ---
seed_site( true );
$GLOBALS['uninstall_test_filters']['translatewp_pro_installed_version'] = '0.3.38';
Lyfecycle::uninstall();
$GLOBALS['uninstall_test_filters'] = [];
$left = plugin_rows( $GLOBALS['uninstall_test_options'] );
sort( $left );
$expected = PRO_ADDON_ROWS;
sort( $expected );
assert_true( $expected === $left, 'with the addon installed exactly its own rows must stay, left: ' . implode( ', ', $left ) );
assert_true( ! array_key_exists( '_transient_translatewp_pro_eligibility', $GLOBALS['uninstall_test_options'] ), 'the eligibility transient the free plugin writes must go' );
assert_true( ! array_key_exists( 'translatewp_pro_capabilities_last_known', $GLOBALS['uninstall_test_options'] ), 'the capability mirror the free plugin writes must go' );

// --- setting off, the service accepts the disconnect: the disconnect clears the credentials as the Disconnect button does ---
$seeded = seed_site( false );
Lyfecycle::uninstall();
$site = $GLOBALS['uninstall_test_options']['translatewp_site'] ?? [];
assert_true( 1 === count( $GLOBALS['uninstall_test_remote'] ), 'a connected site must disconnect at delete' );
assert_true( '' === ( $site['id'] ?? '' ) && '' === ( $site['token'] ?? '' ), 'an accepted disconnect must clear the credentials' );
// The Disconnect button also forgets the cached Pro eligibility of the account.
$changed_by_disconnect = [ 'translatewp_site', '_transient_translatewp_pro_eligibility', '_transient_timeout_translatewp_pro_eligibility', 'translatewp_pro_capabilities_last_known' ];
$delivered             = [ 'translatewp_held_email_42' ];
$kept                  = array_diff_key( $seeded, array_flip( array_merge( $changed_by_disconnect, $delivered ) ) );
assert_true( $kept === array_diff_key( $GLOBALS['uninstall_test_options'], array_flip( $changed_by_disconnect ) ), 'a delete with the setting off changed options: ' . implode( ', ', array_keys( array_diff_key( $kept, $GLOBALS['uninstall_test_options'] ) ) ) );
assert_true( ! isset( $GLOBALS['uninstall_test_options']['translatewp_held_email_42'] ) && 1 === count( $GLOBALS['uninstall_test_mails'] ), 'a held order email must be sent with the setting off too' );

// --- setting off, the disconnect fails: the credentials and every option stay ---
$seeded = seed_site( false );
$GLOBALS['uninstall_test_remote_response'] = [ 'response' => [ 'code' => 503 ], 'body' => '{"error":"down"}' ];
Lyfecycle::uninstall();
unset( $GLOBALS['uninstall_test_remote_response'] );
assert_true( CREDENTIALS === ( $GLOBALS['uninstall_test_options']['translatewp_site'] ?? null ), 'credentials must stay when the disconnect fails' );
assert_true( array_diff_key( $seeded, array_flip( $delivered ) ) === $GLOBALS['uninstall_test_options'], 'a delete with the setting off and a failed disconnect changed options: ' . implode( ', ', array_keys( array_diff_key( $seeded, $GLOBALS['uninstall_test_options'] ) ) ) );
assert_true( 1 === preg_match( '/disconnect failed.*503/', uninstall_log() ), 'the failed disconnect must be logged' );
assert_true( [] === plugin_hooks(), 'a delete with the setting off left events scheduled: ' . implode( ', ', plugin_hooks() ) );
assert_true( 0 === $GLOBALS['wpdb']->count_type( 'twp_index_item' ), 'index queue posts remain after a delete with the setting off' );
assert_true( 4 === $GLOBALS['wpdb']->count_type( 'lexicon' ) && 6 === $GLOBALS['wpdb']->count_type( 'translatewp_metric' ), 'a delete with the setting off removed Lexicon or metric posts' );

// --- a held email the mailer refuses: with the setting on it is wiped, so the log says it was not sent ---
seed_site( true );
uninstall_log();
$GLOBALS['uninstall_test_mail_result'] = false;
Lyfecycle::uninstall();
$log = uninstall_log();
assert_true( 1 === preg_match( '/1 held email.* not sent/', $log ), 'a refused held email wiped by the delete must be logged as not sent, log: ' . $log );
assert_true( false === stripos( $log, 'stay' ), 'a held email the delete wipes must not be logged as staying held, log: ' . $log );
assert_true( false === strpos( $log, 'customer@example.com' ) && false === strpos( $log, 'Your order' ), 'the log must not carry an address or the email' );

// --- with the setting off it stays held, and the log says so ---
seed_site( false );
Lyfecycle::uninstall();
unset( $GLOBALS['uninstall_test_mail_result'] );
$log = uninstall_log();
assert_true( HELD_EMAIL === ( $GLOBALS['uninstall_test_options']['translatewp_held_email_42'] ?? null ), 'a refused held email must stay held when the data is kept' );
assert_true( 1 === preg_match( '/1 held email.* stay/', $log ), 'a refused held email that is kept must be logged as staying held, log: ' . $log );

unlink( $GLOBALS['uninstall_log'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "UninstallCleanupTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "UninstallCleanupTest ok\n" );
