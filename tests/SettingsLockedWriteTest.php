<?php

declare(strict_types=1);

use TranslateWP\Components\MultisiteSettings;
use TranslateWP\Components\Options;
use TranslateWP\Components\SettingsScope;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Functionality\Endpoints\Connect;
use TranslateWP\Functionality\Endpoints\Management;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.0.0-test' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function subsite_admin( bool $super = false ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 2;
	$GLOBALS['wp_test_caps']         = $super ? [ 'manage_options', 'manage_network_options' ] : [ 'manage_options' ];
	$GLOBALS['wp_test_super_admin']  = $super;
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://b.example.com' ],
	];
}

function network_values( array $values ): void {
	$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = $values;
}

function save( string $option, array $data ) {
	return ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->update_data(
		new WP_REST_Request( 'POST', '/translatewp/v1/admin/set-data', [ 'option' => $option, 'data' => $data ] )
	);
}

function is_locked_refusal( $result, string $group ): bool {
	return $result instanceof WP_Error
		&& 'settings_locked' === $result->get_error_code()
		&& 403 === (int) ( $result->get_error_data()['status'] ?? 0 )
		&& $group === ( $result->get_error_data()['group'] ?? '' );
}

// --- a locked group refuses a subsite admin's change, names the group, and leaves the stored value ---
subsite_admin();
network_values( [ 'translatewp_languages' => [ 'switcher' => [ 'text_color' => '#111111' ] ] ] );
$GLOBALS['wp_test_options_store']['blog_2_languages'] = [ 'switcher' => [ 'text_color' => '#222222' ] ];
SettingsScope::set_group_state( 'switcher_appearance', 'locked' );
$result = save( 'languages.switcher', [ 'text_color' => '#999999' ] );
assert_true( is_locked_refusal( $result, 'switcher_appearance' ), 'a locked group refuses the change with settings_locked 403 naming the group, got ' . wp_json_encode( $result instanceof WP_Error ? [ $result->get_error_code(), $result->get_error_data() ] : $result ) );
assert_true( $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'Switcher appearance' ), 'the refusal names the group in plain words, got ' . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
assert_true( '#222222' === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['text_color'] ?? null ), 'a refused change is not stored' );

// --- a save that repeats the network value of a locked group is not a change ---
$result = save( 'languages.switcher', [ 'text_color' => '#111111' ] );
assert_true( ! ( $result instanceof WP_Error ), 'repeating the locked network value is allowed, got ' . wp_json_encode( $result instanceof WP_Error ? $result->get_error_code() : $result ) );

// --- the per-subsite placement stays writable while the appearance is locked ---
$position = ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->save_switcher_position(
	new WP_REST_Request( 'POST', '/translatewp/v1/admin/switcher-position', [ 'position' => [ 'anchor' => 'top-left', 'x' => 4, 'y' => 8 ], 'revision' => Admin::settings_revision_value() ] )
);
assert_true( ! ( $position instanceof WP_Error ), 'moving the switcher is allowed while its appearance is locked, got ' . wp_json_encode( $position instanceof WP_Error ? $position->get_error_code() : $position ) );

// --- a super admin is never refused ---
subsite_admin( true );
network_values( [ 'translatewp_languages' => [ 'switcher' => [ 'text_color' => '#111111' ] ] ] );
SettingsScope::set_group_state( 'switcher_appearance', 'locked' );
$result = save( 'languages.switcher', [ 'text_color' => '#999999' ] );
assert_true( ! ( $result instanceof WP_Error ), 'a super admin changes a locked group on a subsite' );

// --- default and local groups, and a subsite excepted from the lock, take the change ---
foreach ( [ 'default', 'local' ] as $state ) {
	subsite_admin();
	network_values( [ 'translatewp_languages' => [ 'switcher' => [ 'text_color' => '#111111' ] ] ] );
	SettingsScope::set_group_state( 'switcher_appearance', $state );
	assert_true( ! ( save( 'languages.switcher', [ 'text_color' => '#999999' ] ) instanceof WP_Error ), 'a ' . $state . ' group takes the subsite change' );
}
subsite_admin();
network_values( [ 'translatewp_languages' => [ 'switcher' => [ 'text_color' => '#111111' ] ] ] );
SettingsScope::set_group_state( 'switcher_appearance', 'locked' );
SettingsScope::set_subsite_exception( 'switcher_appearance', 2, 'local' );
assert_true( ! ( save( 'languages.switcher', [ 'text_color' => '#999999' ] ) instanceof WP_Error ), 'a subsite excepted from the lock takes its change' );

// --- a single site is never refused ---
wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
assert_true( ! ( save( 'advanced', [ 'delete_on_uninstall' => true ] ) instanceof WP_Error ), 'a single site admin sets the uninstall rule' );
assert_true( ! ( save( 'languages.seo', [ 'ai_crawlers' => [ 'preset_answer_engines' => true ] ] ) instanceof WP_Error ), 'a single site admin sets the crawler policy' );

// --- network-only groups are locked for subsite admins, even on a network with a migrated defaults record ---
subsite_admin();
network_values( [ 'translatewp_languages' => [ 'switcher' => [ 'text_color' => '#111111' ] ] ] );
assert_true( 'locked' === SettingsScope::state( 'uninstall' ) && 'locked' === SettingsScope::state( 'account' ), 'network-only groups stay locked after migration' );
assert_true( is_locked_refusal( save( 'advanced', [ 'delete_on_uninstall' => true ] ), 'uninstall' ), 'a subsite admin cannot set the uninstall rule' );
subsite_admin( true );
assert_true( ! ( save( 'advanced', [ 'delete_on_uninstall' => true ] ) instanceof WP_Error ), 'a super admin sets the uninstall rule on a subsite' );

// --- the uninstall rule reads from the network ---
subsite_admin();
network_values( [ 'translatewp_advanced' => [ 'delete_on_uninstall' => true ] ] );
$read = MultisiteSettings::merge_network_defaults( [ 'delete_on_uninstall' => false ], 'translatewp_advanced' );
assert_true( true === ( $read['delete_on_uninstall'] ?? null ), 'a subsite reads the network uninstall rule, got ' . wp_json_encode( $read ) );

// --- the crawler policy starts locked; other SEO flags stay the subsite's ---
subsite_admin();
assert_true( is_locked_refusal( save( 'languages.seo', [ 'ai_crawlers' => [ 'preset_answer_engines' => true ] ] ), 'ai_crawlers' ), 'the crawler policy is locked to the network' );
assert_true( ! ( save( 'languages.seo', [ 'output_hreflang' => false ] ) instanceof WP_Error ), 'a technical SEO flag is the subsite\'s while the crawler policy is locked' );
SettingsScope::set_group_state( 'ai_crawlers', 'local' );
assert_true( ! ( save( 'languages.seo', [ 'ai_crawlers' => [ 'preset_answer_engines' => true ] ] ) instanceof WP_Error ), 'a crawler policy left to each subsite takes the change' );

// --- browser auto-detect stays in the visitor-language group; there is no translation switch ---
subsite_admin();
assert_true( 'visitor_language' === SettingsScope::group_for( 'translatewp_translations', 'settings', 'auto_translate' ), 'browser auto-detect stays in the visitor-language group' );
assert_true( ! array_key_exists( 'translation_switch', SettingsScope::groups() ), 'there is no translation switch group' );

// --- a save from an older form that still carries the retired switch key is not refused and does not store it ---
subsite_admin();
network_values( [ 'translatewp_translations' => [ 'settings' => [ 'translate_image_alt_tags' => true ] ] ] );
$GLOBALS['wp_test_options_store']['blog_2_translations'] = [ 'settings' => [ 'translate_image_alt_tags' => true ] ];
SettingsScope::set_group_state( 'translation_behaviour', 'locked' );
$result = save( 'translations.settings', [ 'translate_image_alt_tags' => true, 'translation_enabled' => true ] );
assert_true( ! ( $result instanceof WP_Error ), 'a locked subsite save carrying the retired switch key is not refused, got ' . wp_json_encode( $result instanceof WP_Error ? [ $result->get_error_code(), $result->get_error_data() ] : $result ) );
assert_true( ! array_key_exists( 'translation_enabled', $GLOBALS['wp_test_options_store']['blog_2_translations']['settings'] ?? [] ), 'the subsite does not store the retired switch key' );
wp_test_reset( [] );
$GLOBALS['wp_test_multisite'] = false;
$GLOBALS['wp_test_caps']      = [ 'manage_options' ];
$result = save( 'translations.settings', [ 'post_types' => [ 'page' ], 'translation_enabled' => false ] );
$stored = Options::get( 'translations.settings', [] );
assert_true( ! ( $result instanceof WP_Error ), 'a single-site save carrying the retired switch key succeeds' );
assert_true( ! array_key_exists( 'translation_enabled', is_array( $stored ) ? $stored : [] ), 'a single-site save does not store the retired switch key, got ' . wp_json_encode( $stored ) );
assert_true( [ 'page' ] === ( $stored['post_types'] ?? null ), 'the rest of the single-site save is stored' );

// --- the visitor runtime runs wherever the plugin is active and set up ---
wp_test_reset(
	[
		'setup'     => [ 'ended' => true ],
		'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ] ] ],
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'token' ],
	]
);
assert_true( true === TranslateWP::visitor_runtime_ready(), 'a set-up site translates for visitors' );
Options::update( 'translations.settings', [ 'translation_enabled' => false ] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'a switch stored off before the retirement no longer stops the visitor runtime' );

// --- the account is the network's: a subsite admin cannot connect, reconnect, disconnect or reset it ---
subsite_admin();
$connect    = new Connect( 'translatewp', TRANSLATEWP_VERSION );
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );
assert_true( is_locked_refusal( $connect->begin( new WP_REST_Request( 'POST', '/translatewp/v1/connect/begin' ) ), 'account' ), 'a subsite admin cannot start a connection' );
assert_true( is_locked_refusal( $connect->disconnect( new WP_REST_Request( 'POST', '/translatewp/v1/connect/disconnect' ) ), 'account' ), 'a subsite admin cannot disconnect' );
assert_true( is_locked_refusal( $management->reconnect( new WP_REST_Request( 'POST', '/translatewp/v1/management/reconnect', [ 'path' => 'relink' ] ) ), 'account' ), 'a subsite admin cannot reconnect' );
assert_true( is_locked_refusal( $management->reset_data( new WP_REST_Request( 'POST', '/translatewp/v1/management/reset-data' ) ), 'account' ), 'a subsite admin cannot reset the connection and data' );
subsite_admin( true );
$begin = ( new Connect( 'translatewp', TRANSLATEWP_VERSION ) )->begin( new WP_REST_Request( 'POST', '/translatewp/v1/connect/begin' ) );
assert_true( ! is_locked_refusal( $begin, 'account' ), 'a super admin connects a subsite' );
wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$begin = ( new Connect( 'translatewp', TRANSLATEWP_VERSION ) )->begin( new WP_REST_Request( 'POST', '/translatewp/v1/connect/begin' ) );
assert_true( ! is_locked_refusal( $begin, 'account' ), 'a single site admin connects their site' );

// --- onboarding keeps a locked value instead of failing setup ---
subsite_admin();
network_values( [ 'translatewp_translations' => [ 'settings' => [ 'auto_translate' => true ] ] ] );
SettingsScope::set_group_state( 'visitor_language', 'locked' );
$step = $management->save_step( new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [ 'step' => 'translation', 'auto_translate' => false ] ) );
assert_true( ! ( $step instanceof WP_Error ), 'a setup step is not refused over a locked group' );
$stored = $GLOBALS['wp_test_options_store']['blog_2_translations']['settings']['auto_translate'] ?? null;
assert_true( false !== $stored, 'setup leaves the locked auto-detect value to the network, got ' . wp_json_encode( $stored ) );
SettingsScope::set_group_state( 'visitor_language', 'default' );
$management->save_step( new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [ 'step' => 'translation', 'auto_translate' => false ] ) );
assert_true( false === ( $GLOBALS['wp_test_options_store']['blog_2_translations']['settings']['auto_translate'] ?? null ), 'setup stores the subsite choice when the group is open' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsLockedWriteTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SettingsLockedWriteTest: all assertions passed\n";
