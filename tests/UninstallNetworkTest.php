<?php
/**
 * Deleting the plugin on a network. The delete-data setting is read as the
 * booted plugin reads it: when the plugin is network active the setting is
 * network-only, so it comes from the network defaults and never from a subsite
 * row. A plugin activated on single sites of a network wipes only the sites
 * whose own setting is on and never a network option. Site loops run in pages
 * of 100.
 */

declare(strict_types=1);

use TranslateWP\Includes\Lyfecycle;

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/uninstall-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

const BLOGS = [ 1, 2, 3 ];

/**
 * A network of three blogs, each with options, Lexicon and metric posts, queue
 * posts and events with arguments; the network options; the activation record.
 *
 * @param array|null $network_flag  The network default of the setting; null for none.
 * @param array      $blog_flags    blog id => the setting in that blog's own row.
 * @param string|null $activation   The recorded activation, null for an install made before it was recorded.
 */
function seed_network( ?bool $network_flag, array $blog_flags, ?string $activation, array $sites = BLOGS ): void {
	$GLOBALS['uninstall_test_multisite']     = true;
	$GLOBALS['uninstall_test_sites']         = $sites;
	$GLOBALS['uninstall_test_blog']          = 1;
	$GLOBALS['uninstall_test_blog_stack']    = [];
	$GLOBALS['uninstall_test_wpdbs']         = [];
	$GLOBALS['wpdb']                         = new Uninstall_Test_wpdb();
	$GLOBALS['uninstall_test_options']       = [];
	$GLOBALS['uninstall_test_blog_options']  = [];
	$GLOBALS['uninstall_test_cron']          = [];
	$GLOBALS['uninstall_test_get_sites']     = [];
	$GLOBALS['uninstall_test_unscheduled']   = [];
	$GLOBALS['uninstall_test_site_options']  = [
		'translatewp_network_settings_scope'   => [ 'groups' => [ 'switcher_appearance' => [ 'state' => 'locked', 'exceptions' => [ 2 => 'local' ] ] ] ],
		'translatewp_network_connect_results'  => [ 2 => 'ok' ],
		'_site_transient_translatewp_network_connect_outcome_1' => [ 'ok' => true ],
		'_site_transient_timeout_translatewp_network_connect_outcome_1' => 2000000000,
		'site_admins'                          => [ 'admin' ],
	];
	if ( null !== $network_flag ) {
		$GLOBALS['uninstall_test_site_options']['translatewp_network_settings_defaults'] = [ 'translatewp_advanced' => [ 'delete_on_uninstall' => $network_flag ] ];
	}
	if ( null !== $activation ) {
		$GLOBALS['uninstall_test_site_options']['translatewp_activation_scope'] = $activation;
	}

	foreach ( BLOGS as $blog_id ) {
		switch_to_blog( $blog_id );
		update_option( 'translatewp_languages', [ 'list' => [ 'fr' ] ] );
		update_option( 'translatewp_readiness_reconcile_cursor', $blog_id );
		update_option( '_transient_twp_ready_home', 1 );
		update_option( 'woocommerce_version', '9.0.0' );
		if ( array_key_exists( $blog_id, $blog_flags ) ) {
			update_option( 'translatewp_advanced', [ 'delete_on_uninstall' => $blog_flags[ $blog_id ] ] );
		}
		$GLOBALS['wpdb']->seed( 'twp_index_item', 5 );
		$GLOBALS['wpdb']->seed( 'lexicon', 2, 1 );
		$GLOBALS['wpdb']->seed( 'translatewp_metric', 3 );
		uninstall_test_schedule( 'translatewp_deliver_translated_email', [ $blog_id ] );
		uninstall_test_schedule( 'translatewp_rewarm_debounce', [ 9, 'post' ] );
		uninstall_test_schedule( 'translatewp_index_queue_drain' );
		restore_current_blog();
	}
}

function blog_options( int $blog_id ): array {
	return 1 === $blog_id ? $GLOBALS['uninstall_test_options'] : ( $GLOBALS['uninstall_test_blog_options'][ $blog_id ] ?? [] );
}

function plugin_rows( array $options ): array {
	return array_values(
		array_filter(
			array_keys( $options ),
			static fn ( string $name ): bool => 1 === preg_match( '/^(_site_transient_timeout_|_site_transient_|_transient_timeout_|_transient_)?(translatewp_|twp_)/', $name )
		)
	);
}

function assert_no_plugin_event( string $case ): void {
	foreach ( BLOGS as $blog_id ) {
		$left = array_filter( uninstall_test_scheduled( $blog_id ), static fn ( string $hook ): bool => 0 === strpos( $hook, 'translatewp_' ) );
		assert_true( [] === $left, "{$case}: blog {$blog_id} kept events " . implode( ', ', $left ) );
	}
}

/** @return array<int,array> blog id => options, captured before the delete */
function snapshot(): array {
	$out = [];
	foreach ( BLOGS as $blog_id ) {
		$out[ $blog_id ] = blog_options( $blog_id );
	}
	return $out;
}

// --- activation records whether the plugin is network active ---
$GLOBALS['uninstall_test_multisite']    = true;
$GLOBALS['uninstall_test_site_options'] = [];
Lyfecycle::activate( true );
assert_true( 'network' === ( $GLOBALS['uninstall_test_site_options']['translatewp_activation_scope'] ?? null ), 'a network activation must be recorded' );
Lyfecycle::activate( false );
assert_true( 'site' === ( $GLOBALS['uninstall_test_site_options']['translatewp_activation_scope'] ?? null ), 'an activation on one site of a network must be recorded' );

// --- network active, network default off, subsite rows on: every option stays ---
seed_network( false, [ 1 => true, 2 => true ], 'network' );
$before       = snapshot();
$site_options = $GLOBALS['uninstall_test_site_options'];
Lyfecycle::uninstall();
foreach ( BLOGS as $blog_id ) {
	assert_true( $before[ $blog_id ] === blog_options( $blog_id ), "network default off: blog {$blog_id} lost options " . implode( ', ', array_keys( array_diff_key( $before[ $blog_id ], blog_options( $blog_id ) ) ) ) );
	$tables = uninstall_test_wpdb( $blog_id );
	assert_true( 2 === $tables->count_type( 'lexicon' ) && 3 === $tables->count_type( 'translatewp_metric' ), "network default off: blog {$blog_id} lost Lexicon or metric posts" );
	assert_true( 0 === $tables->count_type( 'twp_index_item' ), "network default off: blog {$blog_id} kept index queue posts" );
}
assert_true( $site_options === $GLOBALS['uninstall_test_site_options'], 'network default off: network options changed' );
assert_no_plugin_event( 'network default off' );
assert_true( 1 === get_current_blog_id(), 'the delete must end on the blog it started on' );

// --- network active, network default on, a subsite row off: every blog and the network options are wiped ---
seed_network( true, [ 2 => false ], 'network' );
Lyfecycle::uninstall();
foreach ( BLOGS as $blog_id ) {
	$left = plugin_rows( blog_options( $blog_id ) );
	assert_true( [] === $left, "network default on: blog {$blog_id} kept " . implode( ', ', $left ) );
	assert_true( '9.0.0' === ( blog_options( $blog_id )['woocommerce_version'] ?? null ), "network default on: blog {$blog_id} lost another plugin's option" );
	$tables = uninstall_test_wpdb( $blog_id );
	assert_true( 0 === $tables->count_type( 'lexicon' ) + $tables->count_type( 'translatewp_metric' ) + $tables->count_type( 'twp_index_item' ), "network default on: blog {$blog_id} kept plugin posts" );
}
$left = plugin_rows( $GLOBALS['uninstall_test_site_options'] );
assert_true( [] === $left, 'network default on: network options left: ' . implode( ', ', $left ) );
assert_true( isset( $GLOBALS['uninstall_test_site_options']['site_admins'] ), 'network default on: a core network option was deleted' );
assert_no_plugin_event( 'network default on' );

// --- network active with no network default: the setting is off, whatever a subsite row says ---
seed_network( null, [ 1 => true, 2 => true, 3 => true ], 'network' );
$before = snapshot();
Lyfecycle::uninstall();
foreach ( BLOGS as $blog_id ) {
	assert_true( $before[ $blog_id ] === blog_options( $blog_id ), "no network default: blog {$blog_id} lost options" );
}

// --- activated on single sites of a network: only the sites whose own setting is on, never a network option ---
seed_network( true, [ 2 => false, 3 => true ], 'site' );
$before       = snapshot();
$site_options = $GLOBALS['uninstall_test_site_options'];
Lyfecycle::uninstall();
assert_true( [] === plugin_rows( blog_options( 3 ) ), 'single-site activation: the blog with the setting on kept ' . implode( ', ', plugin_rows( blog_options( 3 ) ) ) );
assert_true( 0 === uninstall_test_wpdb( 3 )->count_type( 'lexicon' ), 'single-site activation: the blog with the setting on kept Lexicon posts' );
foreach ( [ 1, 2 ] as $blog_id ) {
	assert_true( $before[ $blog_id ] === blog_options( $blog_id ), "single-site activation: blog {$blog_id} lost options" );
	assert_true( 2 === uninstall_test_wpdb( $blog_id )->count_type( 'lexicon' ), "single-site activation: blog {$blog_id} lost Lexicon posts" );
}
assert_true( $site_options === $GLOBALS['uninstall_test_site_options'], 'single-site activation: network options changed' );
assert_no_plugin_event( 'single-site activation' );

// --- installs from before activation was recorded count as network active: only the network default decides ---
seed_network( true, [ 2 => false ], null );
Lyfecycle::uninstall();
assert_true( [] === plugin_rows( $GLOBALS['uninstall_test_site_options'] ), 'an older network install with the network default on must wipe the network options' );
assert_true( [] === plugin_rows( blog_options( 2 ) ), 'an older network install with the network default on must wipe every blog' );
seed_network( null, [ 1 => true, 2 => true ], null );
$before       = snapshot();
$site_options = $GLOBALS['uninstall_test_site_options'];
Lyfecycle::uninstall();
foreach ( BLOGS as $blog_id ) {
	assert_true( $before[ $blog_id ] === blog_options( $blog_id ), "an older install with no network default must keep blog {$blog_id}, whatever its own row says" );
}
assert_true( $site_options === $GLOBALS['uninstall_test_site_options'], 'an older install with no network default must keep the network options' );

// --- user meta is shared by the network: deleted once with the network wipe, never by a single-site wipe ---
$user_meta = [ 'translatewp_coexistence_dismissed', 'translatewp_cache_notice_dismissed', 'translatewp_finish_setup_dismissed' ];
seed_network( true, [], 'network' );
$GLOBALS['uninstall_test_meta_deletes'] = [];
Lyfecycle::uninstall();
foreach ( $user_meta as $key ) {
	$deletes = array_filter( $GLOBALS['uninstall_test_meta_deletes'], static fn ( string $entry ): bool => 1 === preg_match( '/^\d+:user:' . $key . '$/', $entry ) );
	assert_true( 1 === count( $deletes ), "the network wipe must delete the user meta {$key} once, did " . count( $deletes ) );
}
seed_network( null, [ 3 => true ], 'site' );
$GLOBALS['uninstall_test_meta_deletes'] = [];
Lyfecycle::uninstall();
assert_true( [] === array_filter( $GLOBALS['uninstall_test_meta_deletes'], static fn ( string $entry ): bool => false !== strpos( $entry, ':user:' ) ), 'a single-site wipe in a network must leave the user meta the other sites share' );

// --- the time budget counts disconnects only: a slow cleanup never skips a disconnect ---
seed_network( false, [], 'network' );
foreach ( BLOGS as $blog_id ) {
	switch_to_blog( $blog_id );
	update_option( 'translatewp_site', [ 'id' => '00000000-0000-4000-8000-00000000000' . $blog_id, 'token' => 'site-token-' . $blog_id ] );
	restore_current_blog();
}
$GLOBALS['uninstall_test_remote']           = [];
$GLOBALS['uninstall_test_now']              = 1000.0;
$GLOBALS['uninstall_test_cleanup_seconds']  = 10.0;
Lyfecycle::set_clock( static fn (): float => $GLOBALS['uninstall_test_now'] );
Lyfecycle::uninstall();
Lyfecycle::set_clock( null );
$GLOBALS['uninstall_test_cleanup_seconds'] = 0.0;
$disconnects = array_filter( $GLOBALS['uninstall_test_remote'], static fn ( array $call ): bool => false !== strpos( $call['url'], '/v1/site/disconnect' ) );
assert_true( 3 === count( $disconnects ), 'every site must be disconnected however long the cleanup takes, got ' . count( $disconnects ) . ' of 3' );

// --- site loops run in pages of 100 ---
seed_network( false, [], 'network', range( 1, 250 ) );
Lyfecycle::uninstall();
$pages = $GLOBALS['uninstall_test_get_sites'];
assert_true( [] !== $pages, 'the delete did not list the sites' );
foreach ( $pages as $args ) {
	assert_true( 100 === (int) ( $args['number'] ?? 0 ), 'a site loop asked for ' . var_export( $args['number'] ?? null, true ) . ' sites at once' );
	assert_true( 1 === ( $args['network_id'] ?? null ), 'a site loop must list the sites of the current network only' );
}
$visited = array_unique(
	array_map(
		static fn ( string $entry ): int => (int) strtok( $entry, ':' ),
		$GLOBALS['uninstall_test_unscheduled']
	)
);
assert_true( 250 === count( $visited ), 'the delete reached ' . count( $visited ) . ' of 250 sites' );

$GLOBALS['uninstall_test_multisite'] = false;

if ( $failures > 0 ) {
	fwrite( STDERR, "UninstallNetworkTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "UninstallNetworkTest ok\n" );
