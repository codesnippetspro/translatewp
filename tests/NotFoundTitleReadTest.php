<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\TranslationStoreSync;

// The store read on a missing address keys by the 404 page's own address.
function is_404(): bool {
	return ! empty( $GLOBALS['wp_test_is_404'] );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.27' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

Options::update( 'languages', [ 'list' => [
	'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
] ] );

$not_found_url = (string) Indexer::object_url( Indexer::NOT_FOUND_OBJECT_ID, Indexer::NOT_FOUND_OBJECT_TYPE );
assert_true( false !== strpos( $not_found_url, Indexer::NOT_FOUND_PATH ), 'The 404 page has its own address' );

TranslationStore::record_pending( $not_found_url, 0, 'it', 'lv', [ [ 'hash' => 'h-title', 'markerPayload' => '⟪item_0001⟫Page not found - Harbor Dance Studio' ] ] );
TranslationStore::resolve_pending( $not_found_url, 0, 'it', static fn(): array => [ 'h-title' => '⟪item_0001⟫Pagina non trovata - Harbor Dance Studio' ] );

$sync = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );

$_SERVER['REQUEST_URI']   = '/it/does-not-exist/';
$GLOBALS['wp_test_is_404'] = true;
$read = $sync->translated_string( 'Page not found - Harbor Dance Studio', 'Page not found - Harbor Dance Studio', 'it', 0 );
assert_true(
	'Pagina non trovata - Harbor Dance Studio' === $read,
	'On a missing address the title reads from the 404 page entry, got ' . wp_json_encode( $read )
);

$GLOBALS['wp_test_is_404'] = false;
$read = $sync->translated_string( 'Page not found - Harbor Dance Studio', 'Page not found - Harbor Dance Studio', 'it', 0 );
assert_true(
	'Page not found - Harbor Dance Studio' === $read,
	'On an ordinary address with no entry the source string comes back, got ' . wp_json_encode( $read )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "NotFoundTitleReadTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "NotFoundTitleReadTest: all assertions passed\n";
