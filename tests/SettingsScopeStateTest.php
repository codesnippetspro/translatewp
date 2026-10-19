<?php

declare(strict_types=1);

use TranslateWP\Components\MultisiteSettings;
use TranslateWP\Components\SettingsScope;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function network_setup( bool $multisite = true ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = $multisite;
	$GLOBALS['wp_test_current_blog'] = $multisite ? 2 : 1;
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://b.example.com' ],
	];
}

function network_switcher( array $switcher ): void {
	$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = [
		'translatewp_languages' => [ 'switcher' => $switcher ],
	];
}

function read_languages_as( int $blog_id ): array {
	switch_to_blog( $blog_id );
	$key   = 1 === $blog_id ? 'languages' : 'blog_' . $blog_id . '_languages';
	$value = MultisiteSettings::merge_network_defaults( $GLOBALS['wp_test_options_store'][ $key ] ?? [], 'translatewp_languages' );
	restore_current_blog();

	return $value;
}

const SCOPE_OPTION = 'translatewp_network_settings_scope';

// --- without any network record each group starts in its tier's state ---
network_setup();
$expected = [
	'account'             => 'locked',
	'uninstall'           => 'locked',
	'ai_crawlers'         => 'locked',
	'switcher_appearance' => 'default',
	'seo_technical'       => 'default',
	'visitor_language'    => 'default',
	'style_tone'          => 'default',
	'target_languages'    => 'local',
	'switcher_placement'  => 'local',
	'ai_context'          => 'local',
];
foreach ( $expected as $group_id => $state ) {
	assert_true( $state === SettingsScope::state( $group_id ), $group_id . ' starts ' . $state . ', got ' . SettingsScope::state( $group_id ) );
}
assert_true( ! isset( $GLOBALS['wp_test_site_options'][ SCOPE_OPTION ] ), 'reading states writes no record when there is nothing to migrate' );

// --- today's flat defaults record migrates as every group default ---
network_setup();
network_switcher( [ 'text_color' => '#111111' ] );
foreach ( SettingsScope::groups() as $group_id => $group ) {
	$expected_state = 'network_only' === $group['tier'] ? 'locked' : 'default';
	assert_true( $expected_state === SettingsScope::state( $group_id ), 'after migration ' . $group_id . ' is ' . $expected_state . ', got ' . SettingsScope::state( $group_id ) );
}
$record = $GLOBALS['wp_test_site_options'][ SCOPE_OPTION ] ?? null;
assert_true( is_array( $record ) && count( $record['groups'] ?? [] ) === count( SettingsScope::groups() ), 'the migrated record is stored with one entry per group, got ' . wp_json_encode( $record ) );

// --- locked: the network value wins over the subsite value and reads as not editable ---
network_setup();
network_switcher( [ 'text_color' => '#111111', 'show_flag' => true ] );
$GLOBALS['wp_test_options_store']['blog_2_languages'] = [
	'switcher' => [ 'text_color' => '#222222', 'icon_style' => 'circle', 'floating_position' => [ 'anchor' => 'top-left', 'x' => 5, 'y' => 5 ] ],
];
assert_true( SettingsScope::set_group_state( 'switcher_appearance', 'locked' ), 'a network default group can be locked' );
$read = read_languages_as( 2 );
assert_true( '#111111' === ( $read['switcher']['text_color'] ?? null ), 'a locked group returns the network value over the subsite value, got ' . wp_json_encode( $read ) );
assert_true( 'circle' === ( $read['switcher']['icon_style'] ?? null ), 'a key the network does not set keeps the subsite value' );
assert_true( [ 'anchor' => 'top-left', 'x' => 5, 'y' => 5 ] === ( $read['switcher']['floating_position'] ?? null ), 'locking the appearance leaves the per-subsite placement alone' );
$group = SettingsScope::resolve_group( 'switcher_appearance', 2 );
assert_true( false === $group['editable'] && 'network' === $group['source'], 'a locked group reads as not editable from the network, got ' . wp_json_encode( $group ) );
assert_true( '#111111' === ( $group['value']['text_color'] ?? null ), 'the group read carries the network value' );

// --- default: the subsite value wins and blanks are filled ---
assert_true( SettingsScope::set_group_state( 'switcher_appearance', 'default' ), 'a group can go back to default' );
$read = read_languages_as( 2 );
assert_true( '#222222' === ( $read['switcher']['text_color'] ?? null ) && true === ( $read['switcher']['show_flag'] ?? null ), 'a default group keeps the subsite value and fills blanks, got ' . wp_json_encode( $read ) );
assert_true( true === SettingsScope::resolve_group( 'switcher_appearance', 2 )['editable'], 'a default group is editable' );

// --- local: the network value is ignored ---
assert_true( SettingsScope::set_group_state( 'switcher_appearance', 'local' ), 'a group can be left to each subsite' );
$read = read_languages_as( 2 );
assert_true( '#222222' === ( $read['switcher']['text_color'] ?? null ), 'a local group keeps the subsite value' );
assert_true( ! array_key_exists( 'show_flag', $read['switcher'] ?? [] ), 'a local group does not fill blanks from the network, got ' . wp_json_encode( $read ) );
assert_true( true === SettingsScope::resolve_group( 'switcher_appearance', 2 )['editable'], 'a local group is editable' );

// --- exceptions override the group state for the named subsites only ---
network_setup();
network_switcher( [ 'text_color' => '#111111' ] );
$GLOBALS['wp_test_options_store']['blog_2_languages'] = [ 'switcher' => [ 'text_color' => '#222222' ] ];
$GLOBALS['wp_test_options_store']['blog_3_languages'] = [ 'switcher' => [ 'text_color' => '#333333' ] ];
SettingsScope::set_group_state( 'switcher_appearance', 'locked' );
assert_true( SettingsScope::set_subsite_exception( 'switcher_appearance', 3, 'local' ), 'a subsite can be excepted from a locked group' );
assert_true( '#111111' === ( read_languages_as( 2 )['switcher']['text_color'] ?? null ), 'a subsite without an exception follows the lock' );
assert_true( '#333333' === ( read_languages_as( 3 )['switcher']['text_color'] ?? null ), 'the excepted subsite keeps its own value' );
assert_true( 'locked' === SettingsScope::state( 'switcher_appearance', 2 ) && 'local' === SettingsScope::state( 'switcher_appearance', 3 ), 'states read per subsite' );
assert_true( false === SettingsScope::resolve_group( 'switcher_appearance', 2 )['editable'] && true === SettingsScope::resolve_group( 'switcher_appearance', 3 )['editable'], 'editable follows the exception' );

SettingsScope::set_group_state( 'switcher_appearance', 'local' );
SettingsScope::set_subsite_exception( 'switcher_appearance', 3, null );
SettingsScope::set_subsite_exception( 'switcher_appearance', 2, 'locked' );
assert_true( '#111111' === ( read_languages_as( 2 )['switcher']['text_color'] ?? null ), 'a subsite can be locked inside a local group' );
assert_true( '#333333' === ( read_languages_as( 3 )['switcher']['text_color'] ?? null ), 'removing an exception returns the subsite to the group state' );

// --- only valid changes are stored ---
network_setup();
assert_true( ! SettingsScope::set_group_state( 'switcher_appearance', 'open' ), 'an unknown state is refused' );
assert_true( ! SettingsScope::set_group_state( 'no_such_group', 'locked' ), 'an unknown group is refused' );
assert_true( ! SettingsScope::set_group_state( 'uninstall', 'local' ), 'a network-only group is never delegated' );
assert_true( ! SettingsScope::set_group_state( 'target_languages', 'locked' ), 'a per-subsite group never takes a network value' );
assert_true( SettingsScope::set_group_state( 'ai_crawlers', 'local' ), 'a delegable group can be left to each subsite' );
assert_true( ! SettingsScope::set_subsite_exception( 'switcher_appearance', 99, 'local' ), 'an exception for a subsite that does not exist is refused' );
assert_true( ! SettingsScope::set_subsite_exception( 'uninstall', 2, 'local' ), 'a network-only group takes no exceptions' );
assert_true( 'default' === SettingsScope::state( 'switcher_appearance' ), 'refused changes leave the state as it was' );

// --- a single site ignores every state ---
network_setup( false );
network_switcher( [ 'text_color' => '#111111' ] );
$GLOBALS['wp_test_site_options'][ SCOPE_OPTION ] = [ 'groups' => [ 'switcher_appearance' => [ 'state' => 'locked', 'exceptions' => [] ] ] ];
$GLOBALS['wp_test_options_store']['languages'] = [ 'switcher' => [ 'text_color' => '#222222' ] ];
$own = SettingsScope::resolve( 'translatewp_languages', $GLOBALS['wp_test_options_store']['languages'] );
assert_true( '#222222' === ( $own['switcher']['text_color'] ?? null ), 'a single site reads its own value' );
assert_true( true === SettingsScope::resolve_group( 'switcher_appearance' )['editable'], 'a single site is always editable' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsScopeStateTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SettingsScopeStateTest: all assertions passed\n";
