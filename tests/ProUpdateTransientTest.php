<?php

declare(strict_types=1);

use TranslateWP\Functionality\Pro;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}
if ( ! defined( 'TRANSLATEWP_URL' ) ) {
	define( 'TRANSLATEWP_URL', 'https://translatewp.ai/wp-content/plugins/translate/' );
}
$plugin_dir = sys_get_temp_dir() . '/twp-pro-update-' . getmypid();
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', $plugin_dir );
}

if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( string $file, array $headers ): array {
		$contents = (string) file_get_contents( $file );
		foreach ( $headers as $key => $name ) {
			$headers[ $key ] = 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $contents, $match ) ? trim( $match[1] ) : '';
		}

		return $headers;
	}
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function assert_no_github_requested(): void {
	foreach ( $GLOBALS['wp_test_remote_get_urls'] as $url ) {
		assert_true( false === stripos( (string) $url, 'github' ), 'No request may target GitHub, got ' . $url );
	}
}

function eligibility_response( array $payload ): array {
	return [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( $payload ),
	];
}

function pro_payload(): array {
	return [
		'plan'        => 'pro',
		'entitled'    => true,
		'latest'      => [
			'version'     => '1.2.0',
			'released_at' => '2026-09-12T10:00:00Z',
		],
		'package_url' => 'https://api.translatewp.ai/v1/pro/package?token=signed-abc',
	];
}

$connected = [
	'site' => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
];

$addon_file = 'translatewp-pro/translatewp-pro.php';

// --- Entitled + installed + newer release: the addon is injected with the API package ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );

$pro       = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$transient = (object) [ 'response' => [] ];
$result    = $pro->inject_update( $transient );

assert_true( isset( $result->response[ $addon_file ] ), 'the addon must be injected into the update transient' );
$offer = $result->response[ $addon_file ] ?? null;
assert_true( is_object( $offer ), 'the injected offer must be an object' );
assert_true( '1.2.0' === $offer->new_version, 'the offer must carry the latest version' );
assert_true(
	'https://api.translatewp.ai/v1/pro/package?token=signed-abc' === $offer->package,
	'the update package must be the API URL'
);
assert_true( 'translatewp-pro' === $offer->slug, 'the offer must carry the addon slug' );
assert_true( false === stripos( (string) $offer->package, 'github' ), 'the update package must never point at GitHub' );
assert_no_github_requested();

// --- Installed and current: listed under no_update so WordPress treats it as managed ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.2.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$pro       = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$transient = (object) [ 'response' => [ $addon_file => (object) [ 'new_version' => '1.1.0' ] ] ];
$result    = $pro->inject_update( $transient );
assert_true( ! isset( $result->response[ $addon_file ] ), 'a current addon must not stay listed as an update' );
assert_true( isset( $result->no_update[ $addon_file ] ), 'a current addon must be listed under no_update' );
assert_true( '1.2.0' === $result->no_update[ $addon_file ]->new_version, 'no_update must carry the installed version' );

// --- The connection panel state exposes the update with the native updater URL ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$panel = \TranslateWP\Components\ProEligibility::panel_state();
assert_true( true === $panel['updateAvailable'], 'panel state must flag the available update' );
assert_true( '1.2.0' === $panel['latestVersion'], 'panel state must carry the latest version' );
assert_true( is_string( $panel['updateUrl'] ) && false !== strpos( $panel['updateUrl'], 'update.php?action=upgrade-plugin&plugin=translatewp-pro' ), 'panel state must link the native updater' );
assert_true( false !== strpos( $panel['updateUrl'], '_wpnonce=' ), 'the updater link must carry a nonce' );

// --- The native updater downloads from a freshly signed URL, never the stored one ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_download_urls'] = [];
$fresh_payload                    = pro_payload();
$fresh_payload['package_url']     = 'https://api.translatewp.ai/v1/pro/package?token=signed-fresh';
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( $fresh_payload );
$pro  = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$file = $pro->pre_download( false, 'https://api.translatewp.ai/v1/pro/package?token=signed-stale' );
assert_true( '/tmp/translatewp-test-download.zip' === $file, 'pre_download must hand WordPress the downloaded file' );
assert_true(
	[ 'https://api.translatewp.ai/v1/pro/package?token=signed-fresh' ] === $GLOBALS['wp_test_download_urls'],
	'pre_download must download the freshly signed package URL, not the stored one'
);
assert_true( false === $pro->pre_download( false, 'https://downloads.wordpress.org/plugin/other.zip' ), 'pre_download must leave other packages to WordPress' );
$GLOBALS['wp_test_download_urls']        = [];
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( [ 'plan' => 'free', 'entitled' => false, 'latest' => null ] );
$denied = $pro->pre_download( false, 'https://api.translatewp.ai/v1/pro/package?token=signed-stale' );
assert_true( is_wp_error( $denied ) && [] === $GLOBALS['wp_test_download_urls'], 'an unentitled site must get a typed error and no download' );
assert_no_github_requested();

// --- Not installed: nothing is injected (the install prompt owns that path) ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$pro       = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$transient = (object) [ 'response' => [] ];
$result    = $pro->inject_update( $transient );
assert_true( empty( $result->response ), 'an uninstalled addon must not be injected as an update' );

// --- Not connected: the transient is returned untouched, no API call ---
wp_test_reset( [] );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$pro       = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$transient = (object) [ 'response' => [] ];
$result    = $pro->inject_update( $transient );
assert_true( empty( $result->response ), 'an unconnected site must not inject updates' );

// --- plugins_api returns API-backed info for the addon and never hits GitHub ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$pro  = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$info = $pro->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'translatewp-pro' ] );
assert_true( is_object( $info ), 'plugin_information must return an info object for the addon' );
assert_true( '1.2.0' === $info->version, 'plugin_information must report the latest version' );
assert_true(
	'https://api.translatewp.ai/v1/pro/package?token=signed-abc' === $info->download_link,
	'plugin_information must serve the API download link'
);
assert_true( false === stripos( (string) $info->download_link, 'github' ), 'plugin_information must never point at GitHub' );
assert_no_github_requested();

// --- The update row carries the bundled logo and the manifest's requirements ---
$expected_icons = [
	'1x' => TRANSLATEWP_URL . 'assets/icon-128x128.png',
	'2x' => TRANSLATEWP_URL . 'assets/icon-256x256.png',
];
foreach ( $expected_icons as $icon ) {
	$size = getimagesize( dirname( __DIR__ ) . '/' . substr( $icon, strlen( TRANSLATEWP_URL ) ) );
	assert_true( false !== $size && 'image/png' === $size['mime'], $icon . ' must be a bundled PNG' );
	assert_true( false !== $size && $size[0] === (int) substr( basename( $icon ), 5, 3 ), $icon . ' must match its size' );
}
$with_requirements = pro_payload();
$with_requirements['latest'] += [ 'tested' => '6.9', 'requires' => '6.3', 'requires_php' => '8.0' ];
$other      = (object) [ 'slug' => 'other', 'new_version' => '2.0.0', 'icons' => [ '1x' => 'https://translatewp.ai/other.png' ] ];
$other_copy = clone $other;
foreach ( [ '1.0.0' => 'response', '1.2.0' => 'no_update' ] as $installed => $section ) {
	wp_test_reset( $connected );
	add_filter( 'translatewp_pro_installed_version', static function () use ( $installed ) {
		return $installed;
	} );
	$GLOBALS['wp_test_remote_get_response'] = eligibility_response( $with_requirements );
	$pro    = new Pro( 'translatewp', TRANSLATEWP_VERSION );
	$result = $pro->inject_update( (object) [ 'response' => [ 'other/other.php' => $other ] ] );
	$entry  = $result->{$section}[ $addon_file ] ?? null;
	assert_true( is_object( $entry ) && $expected_icons === $entry->icons, "the {$section} entry must carry the bundled icons" );
	assert_true( is_object( $entry ) && '6.9' === ( $entry->tested ?? null ), "the {$section} entry must carry the tested version" );
	assert_true( is_object( $entry ) && '6.3' === ( $entry->requires ?? null ), "the {$section} entry must carry the required WordPress version" );
	assert_true( is_object( $entry ) && '8.0' === ( $entry->requires_php ?? null ), "the {$section} entry must carry the required PHP version" );
	assert_true( $other_copy == $result->response['other/other.php'], 'other plugins must stay untouched' );
}

$info = $pro->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'translatewp-pro' ] );
assert_true( is_object( $info ) && $expected_icons === $info->icons, 'plugin_information must carry the bundled icons' );
assert_true( is_object( $info ) && [ '6.9', '6.3', '8.0' ] === [ $info->tested ?? null, $info->requires ?? null, $info->requires_php ?? null ], 'plugin_information must carry the manifest requirements' );

// --- Without manifest values the installed Pro header is used; unknown values are left out ---
mkdir( WP_PLUGIN_DIR . '/translatewp-pro', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/' . $addon_file, "<?php\n/**\n * Plugin Name: TranslateWP Pro\n * Tested up to: 6.8\n * Requires PHP: 8.1\n */\n" );
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$pro   = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$entry = $pro->inject_update( (object) [ 'response' => [] ] )->response[ $addon_file ] ?? null;
assert_true( is_object( $entry ) && '6.8' === ( $entry->tested ?? null ), 'the installed Pro header must fill the tested version' );
assert_true( is_object( $entry ) && '8.1' === ( $entry->requires_php ?? null ), 'the installed Pro header must fill the required PHP version' );
assert_true( is_object( $entry ) && ! property_exists( $entry, 'requires' ), 'an unknown required WordPress version must be left out, not sent empty' );
unlink( WP_PLUGIN_DIR . '/' . $addon_file );
rmdir( WP_PLUGIN_DIR . '/translatewp-pro' );
rmdir( WP_PLUGIN_DIR );

wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$pro   = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$entry = $pro->inject_update( (object) [ 'response' => [] ] )->response[ $addon_file ] ?? null;
foreach ( [ 'tested', 'requires', 'requires_php' ] as $key ) {
	assert_true( is_object( $entry ) && ! property_exists( $entry, $key ), "an unknown {$key} must be left out, not sent empty" );
}

// --- plugins_api leaves other plugins to WordPress ---
wp_test_reset( $connected );
$pro     = new Pro( 'translatewp', TRANSLATEWP_VERSION );
$passed  = $pro->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'some-other-plugin' ] );
assert_true( false === $passed, 'plugin_information must ignore other plugins' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ProUpdateTransientTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ProUpdateTransientTest: all assertions passed\n";
