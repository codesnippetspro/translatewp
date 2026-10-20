<?php

declare(strict_types=1);

use TranslateWP\Functionality\Admin\Menus;
use TranslateWP\Components\Options;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
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

function assert_contains( string $needle, string $haystack, string $message ): void {
	global $failures;
	if ( false === strpos( $haystack, $needle ) ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [] );

// Seed saved languages
Options::update( 'languages.list', [
	'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => false ],
	'es-es' => [ 'locale' => 'es-es', 'slug' => 'es', 'active' => true, 'default' => true ],
] );

// Seed translation settings
Options::update( 'translations.settings', [ 'auto_translate' => false ] );

// Seed context
Options::update( 'translations.context.text_context', 'marketing' );

// Seed style and tone
Options::update( 'translations.style.type', 'legal' );
Options::update( 'translations.style.tone', 'professional' );
Options::update( 'translations.style.formality', 3 );
Options::update( 'translations.style.creativity', 8 );

// Seed step
Options::update( 'setup.step', 'context' );

ob_start();
$menus = new Menus( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_user_locale'] = 'nl_NL';
$menus->render_setup_page();
$output = ob_get_clean();

assert_contains( '"languages":["en-us","es-es"]', $output, 'setupData must carry saved languages array' );
assert_contains( '"autoTranslate":false', $output, 'setupData must carry saved autoTranslate value' );
assert_contains( '"context":"marketing"', $output, 'setupData must carry saved context string' );
assert_contains( '"style":"legal"', $output, 'setupData must carry the saved style so the wizard preselects it' );
assert_contains( '"tone":"professional"', $output, 'setupData must carry the saved tone so the wizard preselects it' );
assert_contains( '"formality":3', $output, 'Setup must restore the saved formality without remapping its tone' );
assert_contains( '"creativity":8', $output, 'Setup must restore the saved precision vs fluency' );
assert_true( 1 === preg_match( '#"homeUrl":"https:\\\\?/\\\\?/example\.com\\\\?/"#', $output ), 'setupData must carry the home address for the homepage preview' );
assert_contains( '"step":"context"', $output, 'setupData must carry saved step string' );
assert_contains( '"defaultLanguage":"es-es"', $output, 'setupData defaultLanguage must be the saved default entry, not the WP locale' );
assert_contains( '"userLocale":"nl_NL"', $output, 'setupData must carry the admin profile locale so the wizard can suggest it first' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SetupPrefillTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SetupPrefillTest: all assertions passed\n";
