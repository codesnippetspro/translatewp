<?php

declare(strict_types=1);

use TranslateWP\Components\Migrations;
use TranslateWP\Components\NetworkScope;
use TranslateWP\Components\Options;
use TranslateWP\Components\SettingsScope;
use TranslateWP\Components\TranslationSwitchNotice;
use TranslateWP\Entities\Scripts\TranslateWP;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.21' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function ready_site(): void {
	wp_test_reset(
		[
			'setup'     => [ 'ended' => true ],
			'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ] ] ],
			'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'token' ],
		]
	);
}

// --- no setting turns translation off for visitors ---
assert_true( ! array_key_exists( 'translation_switch', SettingsScope::groups() ), 'the settings scope has no translation switch group' );
assert_true( 'translation_switch' !== SettingsScope::group_for( 'translatewp_translations', 'settings', 'translation_enabled' ), 'the stored switch key belongs to no switch group' );
assert_true( ! array_key_exists( 'translation_switch', NetworkScope::fields() ), 'the network settings list no translation switch group' );
$keys = [];
foreach ( NetworkScope::fields() as $fields ) {
	foreach ( $fields as $field ) {
		$keys[] = $field['key'];
	}
}
assert_true( ! in_array( 'translations.settings.translation_enabled', $keys, true ), 'the network settings offer no translation switch field' );

// --- a site that stored the switch off keeps translating for visitors ---
ready_site();
Options::update( 'translations.settings', [ 'translation_enabled' => false ] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'a stored switch off is read as on: the visitor runtime still loads' );

// --- an upgrade drops the stored switch and keeps the other translation settings ---
ready_site();
Options::update( 'translations.settings', [ 'translation_enabled' => false, 'post_types' => [ 'page' ] ] );
update_option( Migrations::VERSION_OPTION, '0.10.20' );
Migrations::maybe_run();
$settings = Options::get( 'translations.settings', [] );
assert_true( ! array_key_exists( 'translation_enabled', $settings ), 'the stored switch is removed on upgrade, got ' . wp_json_encode( $settings ) );
assert_true( [ 'page' ] === ( $settings['post_types'] ?? null ), 'the other translation settings are kept' );

// --- an upgrade on a network drops the switch value and its group state ---
wp_test_reset( [] );
$GLOBALS['wp_test_multisite']    = true;
$GLOBALS['wp_test_current_blog'] = 1;
$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = [
	'translatewp_translations' => [ 'settings' => [ 'translation_enabled' => false, 'auto_translate' => true ] ],
];
$GLOBALS['wp_test_site_options']['translatewp_network_settings_scope'] = [
	'groups' => [
		'translation_switch' => [ 'state' => 'locked', 'exceptions' => [] ],
		'ai_crawlers'        => [ 'state' => 'local', 'exceptions' => [] ],
	],
];
update_option( Migrations::VERSION_OPTION, '0.10.20' );
Migrations::maybe_run();
$network = $GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults']['translatewp_translations']['settings'] ?? [];
assert_true( ! array_key_exists( 'translation_enabled', $network ), 'the network switch value is removed on upgrade, got ' . wp_json_encode( $network ) );
assert_true( true === ( $network['auto_translate'] ?? null ), 'the other network translation values are kept' );
$scope = $GLOBALS['wp_test_site_options']['translatewp_network_settings_scope']['groups'] ?? [];
assert_true( ! array_key_exists( 'translation_switch', $scope ), 'the switch group state is removed on upgrade, got ' . wp_json_encode( $scope ) );
assert_true( 'local' === ( $scope['ai_crawlers']['state'] ?? null ), 'the other group states are kept' );

// --- a site that stored the switch off is told once that translation is now on ---
$site_notice = static function (): string {
	ob_start();
	TranslationSwitchNotice::render_admin_notice();
	return (string) ob_get_clean();
};
$network_notice = static function (): string {
	ob_start();
	TranslationSwitchNotice::render_network_notice();
	return (string) ob_get_clean();
};
$upgrade_site = static function ( ?bool $stored ): void {
	ready_site();
	$GLOBALS['wp_test_multisite']    = false;
	$GLOBALS['wp_test_site_options'] = [];
	Options::update( 'translations.settings', null === $stored ? [ 'post_types' => [ 'page' ] ] : [ 'translation_enabled' => $stored ] );
	update_option( Migrations::VERSION_OPTION, '0.10.20' );
	Migrations::maybe_run();
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
};
$upgrade_site( false );
$shown = $site_notice();
assert_true( str_contains( $shown, 'Translation for visitors is now on' ), 'a switch stored off gives the notice, got ' . $shown );
assert_true( str_contains( $shown, 'deactivate' ), 'the notice says deactivating the plugin turns translation off' );
assert_true( str_contains( $shown, 'value="' . TranslationSwitchNotice::DISMISS_ACTION . '"' ) && str_contains( $shown, 'admin-post.php' ), 'the notice carries a Dismiss action saved on the server' );
assert_true( ! str_contains( $shown, '—' ), 'the notice has no em dash' );
$GLOBALS['wp_test_caps'] = [];
assert_true( '' === $site_notice(), 'a user who cannot manage the site gets no notice' );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
TranslationSwitchNotice::dismiss( 'site' );
assert_true( '' === $site_notice(), 'a dismissed notice is not shown again' );
$upgrade_site( true );
assert_true( '' === $site_notice(), 'a switch stored on gives no notice' );
$upgrade_site( null );
assert_true( '' === $site_notice(), 'a site with no stored switch gets no notice' );

// --- on a network, a default stored off tells the super admin ---
$upgrade_network = static function ( ?bool $stored ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 1;
	$GLOBALS['wp_test_site_options'] = [];
	$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = [
		'translatewp_translations' => [ 'settings' => null === $stored ? [ 'auto_translate' => true ] : [ 'translation_enabled' => $stored ] ],
	];
	update_option( Migrations::VERSION_OPTION, '0.10.20' );
	Migrations::maybe_run();
	$GLOBALS['wp_test_super_admin'] = true;
	$GLOBALS['wp_test_caps']        = [ 'manage_network_options', 'manage_options' ];
};
$upgrade_network( false );
$shown = $network_notice();
assert_true( str_contains( $shown, 'Translation for visitors is now on' ), 'a network default stored off gives the super admin the notice, got ' . $shown );
assert_true( str_contains( $shown, 'deactivate' ), 'the network notice says deactivating the plugin turns translation off' );
assert_true( '' === $site_notice(), 'a network default stored off does not mark the site itself' );
$GLOBALS['wp_test_super_admin'] = false;
assert_true( '' === $network_notice(), 'only a super admin gets the network notice' );
$GLOBALS['wp_test_super_admin'] = true;
TranslationSwitchNotice::dismiss( 'network' );
assert_true( '' === $network_notice(), 'a dismissed network notice is not shown again' );
$upgrade_network( true );
assert_true( '' === $network_notice(), 'a network default stored on gives no notice' );
$upgrade_network( null );
assert_true( '' === $network_notice(), 'a network with no stored switch gets no notice' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationSwitchRetirementTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslationSwitchRetirementTest: all assertions passed\n";
