<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function settings_request( string $option, array $data ): WP_REST_Request {
	return new WP_REST_Request( 'POST', '/translatewp/v1/admin/set-data', [
		'option' => $option,
		'data'   => $data,
	], [], '' );
}

wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );

// --- whitespace-only guidance is stored empty ---
$admin->update_data( settings_request( 'translations.context', [ 'text_context' => '     ' ] ) );
assert_true( '' === (string) Options::get( 'translations.context.text_context', 'unset' ), 'whitespace-only context must be stored empty, got ' . wp_json_encode( Options::get( 'translations.context.text_context' ) ) );

// --- surrounding whitespace goes, inner text stays ---
$admin->update_data( settings_request( 'translations.context', [ 'text_context' => "  A shop for climbing gear.\n\n" ] ) );
assert_true( 'A shop for climbing gear.' === (string) Options::get( 'translations.context.text_context' ), 'context must be stored trimmed' );

// --- nested style fields are trimmed too, non-strings untouched ---
$admin->update_data( settings_request( 'translations.style', [ 'preset' => ' formal ', 'options' => [ 'custom' => "  warm  " ], 'enabled' => true ] ) );
$style = Options::get( 'translations.style', [] );
assert_true( 'formal' === ( $style['preset'] ?? '' ) && 'warm' === ( $style['options']['custom'] ?? '' ) && true === ( $style['enabled'] ?? false ), 'style strings must be trimmed at every depth and other values kept, got ' . wp_json_encode( $style ) );

// --- other options are left as sent ---
$admin->update_data( settings_request( 'advanced', [ 'note' => ' keep ' ] ) );
assert_true( ' keep ' === (string) Options::get( 'advanced.note' ), 'options outside the guidance fields are stored as sent' );

// --- A languages list without a default language is refused and the stored list is untouched ---
wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$kept  = [ 'fr' => [ 'locale' => 'fr', 'slug' => 'fr', 'active' => true, 'default' => true ] ];
Options::update( 'languages.list', $kept );
$refused = $admin->update_data( settings_request( 'languages.list', [
	[ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
	[ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
] ) );
assert_true( is_wp_error( $refused ) && 'missing_default_language' === $refused->get_error_code(), 'A languages list with no default is refused' );
assert_true( 400 === (int) ( $refused->get_error_data()['status'] ?? 0 ), 'The refusal is a 400' );
assert_true( $kept === Options::get( 'languages.list' ), 'The stored languages stay as they were' );
$accepted = $admin->update_data( settings_request( 'languages.list', [
	[ 'locale' => 'fr', 'slug' => 'fr', 'active' => true, 'default' => true ],
	[ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
] ) );
assert_true( ! is_wp_error( $accepted ), 'A languages list with a default is saved' );
assert_true( 'fr' === Admin::default_locale_of( Options::get( 'languages.list' ) ), 'The saved list carries its default' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsTextTrimTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SettingsTextTrimTest: all assertions passed\n";
