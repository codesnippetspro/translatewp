<?php

declare(strict_types=1);

use TranslateWP\Entities\Scripts\TranslateWP;

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

function capture_head_meta( TranslateWP $script ): string {
	ob_start();
	$script->browser_notranslate_meta();
	return (string) ob_get_clean();
}

$connected_options = [
	'site'      => [ 'id' => '00000000-0000-4000-8000-000000000042', 'token' => 'secret-site-token' ],
	'languages' => [ 'list' => [ 'pt' => [ 'locale' => 'pt', 'active' => true, 'default' => true ], 'ja' => [ 'locale' => 'ja', 'active' => true ] ] ],
];

// --- Runtime enqueued: the notranslate meta is emitted ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$meta   = capture_head_meta( $script );
assert_true( false !== strpos( $meta, '<meta name="google" content="notranslate">' ), 'The notranslate meta must be emitted when the runtime is enqueued, got: ' . $meta );
assert_true( false === strpos( $meta, 'translate="no"' ), 'The document element must never be marked untranslatable' );

// --- Filter turned off: nothing is emitted ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
add_filter( 'translatewp_browser_notranslate', static fn () => false );
$meta = capture_head_meta( $script );
assert_true( '' === $meta, 'The filter must be able to turn the meta off, got: ' . $meta );

// --- Runtime not enqueued (unconnected): nothing is emitted ---
wp_test_reset( [ 'languages' => $connected_options['languages'] ] );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$meta   = capture_head_meta( $script );
assert_true( '' === $meta, 'No meta when the runtime is not enqueued, got: ' . $meta );

if ( $failures > 0 ) {
	fwrite( STDERR, "BrowserNotranslateTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "BrowserNotranslateTest: all assertions passed\n";
