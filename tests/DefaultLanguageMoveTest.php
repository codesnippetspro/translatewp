<?php

declare(strict_types=1);

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

function languages_request( array $list ): WP_REST_Request {
	return new WP_REST_Request( 'POST', '/translatewp/v1/admin/set-data', [
		'option' => 'languages.list',
		'data'   => $list,
	] );
}

function rebuilds_fired(): int {
	$count = 0;
	foreach ( $GLOBALS['wp_test_actions_fired'] ?? [] as $fired ) {
		if ( 'translatewp_translation_affecting_settings_saved' === ( $fired['tag'] ?? '' ) ) {
			$count++;
		}
	}
	return $count;
}

$base = [
	[ 'locale' => 'pt', 'slug' => 'pt', 'active' => true, 'default' => true ],
	[ 'locale' => 'ja', 'slug' => 'ja', 'active' => true, 'default' => false ],
];

// --- Adding a translation language does not retranslate ---
wp_test_reset( [ 'languages' => [ 'list' => $base ] ] );
$GLOBALS['wp_test_actions_fired'] = [];
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( languages_request( array_merge( $base, [ [ 'locale' => 'ar', 'slug' => 'ar', 'active' => true, 'default' => false ] ] ) ) );
assert_true( 0 === rebuilds_fired(), 'Adding a translation language must not fire the rebuild, fired ' . rebuilds_fired() );

// --- Moving the default language retranslates exactly once ---
wp_test_reset( [ 'languages' => [ 'list' => $base ] ] );
$GLOBALS['wp_test_actions_fired'] = [];
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( languages_request( [
	[ 'locale' => 'pt', 'slug' => 'pt', 'active' => false, 'default' => false ],
	[ 'locale' => 'ja', 'slug' => 'ja', 'active' => true, 'default' => true ],
] ) );
assert_true( 1 === rebuilds_fired(), 'Moving the default language must fire the rebuild once, fired ' . rebuilds_fired() );

// --- The helper reads the default locale ---
assert_true( 'pt' === Admin::default_locale_of( $base ), 'default_locale_of must return the flagged locale' );
assert_true( '' === Admin::default_locale_of( 'not a list' ), 'default_locale_of must tolerate a non-list' );

if ( $failures > 0 ) {
	fwrite( STDERR, "DefaultLanguageMoveTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "DefaultLanguageMoveTest: all assertions passed\n";
