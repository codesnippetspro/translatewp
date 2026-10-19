<?php

declare(strict_types=1);

use TranslateWP\Entities\Styles\Shared;
use TranslateWP\Functionality\Styles;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0-beta.1' );
}
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
}
if ( ! defined( 'TRANSLATEWP_PATH' ) ) {
	define( 'TRANSLATEWP_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'TRANSLATEWP_URL' ) ) {
	define( 'TRANSLATEWP_URL', 'https://example.com/wp-content/plugins/translatewp/' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [] );
$style = new Shared( 'translatewp', TRANSLATEWP_VERSION );

// --- True on every TranslateWP screen ---
$plugin_screens = [
	'toplevel_page_translatewp',
	'translatewp_page_translatewp-languages',
	'translatewp_page_translatewp-translations',
	'translatewp_page_translatewp-lexicon',
	'translatewp_page_translatewp-ai-providers',
	'translatewp_page_translatewp-settings',
];
foreach ( $plugin_screens as $screen_id ) {
	$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => $screen_id ];
	assert_true( true === $style->conditions(), "Shared admin CSS must load on {$screen_id}" );
}

// --- False on foreign screens ---
$foreign_screens = [ 'plugins', 'dashboard', 'edit-post', 'options-general' ];
foreach ( $foreign_screens as $screen_id ) {
	$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => $screen_id ];
	assert_true( false === $style->conditions(), "Shared admin CSS must not load on {$screen_id}" );
}

// --- False when no screen is available ---
$GLOBALS['wp_test_current_screen'] = null;
assert_true( false === $style->conditions(), 'Shared admin CSS must not load without a screen' );

// --- Lifecycle wiring: the Styles coordinator enqueues translatewp-shared
// on our screens and not on foreign ones. ---
wp_test_reset( [] );
$GLOBALS['wp_test_filters'] = [];
new Styles( 'translatewp', TRANSLATEWP_VERSION );

$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => 'toplevel_page_translatewp' ];
do_action( 'admin_enqueue_scripts' );
assert_true( in_array( 'translatewp-shared', $GLOBALS['wp_test_enqueued_styles'] ?? [], true ), 'Styles coordinator must enqueue translatewp-shared on our screen' );

$GLOBALS['wp_test_enqueued_styles'] = [];
$GLOBALS['wp_test_current_screen']  = (object) [ 'id' => 'plugins' ];
do_action( 'admin_enqueue_scripts' );
assert_true( ! in_array( 'translatewp-shared', $GLOBALS['wp_test_enqueued_styles'] ?? [], true ), 'Styles coordinator must not enqueue translatewp-shared on a foreign screen' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SharedStyleConditionsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SharedStyleConditionsTest: all assertions passed\n";
