<?php

declare(strict_types=1);

use TranslateWP\Components\Migrations;
use TranslateWP\Components\NetworkScope;
use TranslateWP\Components\Options;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.19' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- an upgrade removes the stored custom CSS and keeps the other switcher settings ---
wp_test_reset( [] );
Options::update( 'languages.switcher', [ 'type' => 'dropdown', 'text_color' => '#123456', 'custom_css' => '.x{color:red}' ] );
update_option( Migrations::VERSION_OPTION, '0.10.18' );
Migrations::maybe_run();
$switcher = Options::get( 'languages.switcher', [] );
assert_true( ! array_key_exists( 'custom_css', $switcher ), 'the stored custom CSS is removed on upgrade, got ' . wp_json_encode( $switcher ) );
assert_true( '#123456' === ( $switcher['text_color'] ?? null ) && 'dropdown' === ( $switcher['type'] ?? null ), 'the other switcher settings are kept' );

// --- an upgrade on a network removes the custom CSS from the network value ---
wp_test_reset( [] );
$GLOBALS['wp_test_multisite']    = true;
$GLOBALS['wp_test_current_blog'] = 1;
$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = [
	'translatewp_languages' => [ 'switcher' => [ 'text_color' => '#654321', 'custom_css' => '.y{}' ] ],
];
update_option( Migrations::VERSION_OPTION, '0.10.18' );
Migrations::maybe_run();
$network = $GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults']['translatewp_languages']['switcher'] ?? [];
assert_true( ! array_key_exists( 'custom_css', $network ), 'the network custom CSS is removed on upgrade, got ' . wp_json_encode( $network ) );
assert_true( '#654321' === ( $network['text_color'] ?? null ), 'the other network switcher values are kept' );

// --- the network settings page offers no custom CSS field ---
$keys = [];
foreach ( NetworkScope::fields() as $fields ) {
	foreach ( $fields as $field ) {
		$keys[] = $field['key'];
	}
}
assert_true( ! in_array( 'languages.switcher.custom_css', $keys, true ), 'the switcher appearance group has no custom CSS field' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SwitcherCustomCssRetirementTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SwitcherCustomCssRetirementTest: all assertions passed\n";
