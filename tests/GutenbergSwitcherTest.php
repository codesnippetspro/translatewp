<?php

declare(strict_types=1);

use TranslateWP\Functionality\GutenbergSwitcher;
use TranslateWP\Functionality\SwitcherSurfaces;
use TranslateWP\Entities\Scripts\Editor;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.2' );
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
new GutenbergSwitcher( 'translatewp', TRANSLATEWP_VERSION );
do_action( 'init' );

$block = $GLOBALS['wp_test_blocks']['translatewp/language-switcher'] ?? null;
assert_true( is_array( $block ), 'The language switcher block must be registered on init' );
assert_true( isset( $block['render_callback'] ) && is_callable( $block['render_callback'] ?? null ), 'Block must use a render callback' );
assert_true( 'translatewp-editor' === ( $block['editor_script'] ?? '' ), 'Block must use the editor-only bundle' );

$rendered = is_array( $block ) ? call_user_func( $block['render_callback'], [], '', null ) : '';
assert_true( SwitcherSurfaces::PLACEHOLDER === $rendered, 'Block output must reuse the shared switcher placeholder' );

$editor = new Editor( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $editor->conditions(), 'The editor bundle must load so the block and the button toggle are offered' );

if ( $failures > 0 ) {
	fwrite( STDERR, "GutenbergSwitcherTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "GutenbergSwitcherTest: all assertions passed\n";
