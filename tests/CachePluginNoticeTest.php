<?php

declare(strict_types=1);

use TranslateWP\Components\CachePluginNotice;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$message}\n";
	}
}

wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$render = static function ( string $screen_id ): string {
	$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => $screen_id ];
	ob_start();
	CachePluginNotice::render_admin_notice();
	return (string) ob_get_clean();
};

// No cache plugin, no notice.
assert_true( [] === CachePluginNotice::detected( static fn(): bool => false ), 'no cache plugin detected when no signal is present' );
assert_true( '' === $render( 'toplevel_page_translatewp' ), 'no cache plugin, no notice' );

// Each named cache plugin is detected when its signal is present.
$expected = [ 'LiteSpeed Cache', 'WP Rocket', 'W3 Total Cache', 'WP Super Cache', 'SiteGround Speed Optimizer', 'WP Fastest Cache' ];
foreach ( $expected as $name ) {
	$detected = CachePluginNotice::detected(
		static function ( array $plugin ) use ( $name ): bool {
			return $plugin['name'] === $name;
		}
	);
	assert_true( [ $name ] === $detected, "only {$name} detected when only its signal is present" );
}

// The message for LiteSpeed Cache is the agreed guidance, word for word.
assert_true(
	'LiteSpeed Cache is active. TranslateWP clears its cache when translations change. If a translated page shows old text, purge the cache once from the LiteSpeed Cache menu.' === CachePluginNotice::message( [ 'LiteSpeed Cache' ] ),
	'the LiteSpeed Cache message is the agreed copy, got ' . CachePluginNotice::message( [ 'LiteSpeed Cache' ] )
);
foreach ( $expected as $name ) {
	$message = CachePluginNotice::message( [ $name ] );
	assert_true( str_contains( $message, $name . ' is active.' ), "the message names {$name}" );
	assert_true( ! str_contains( $message, "\u{2014}" ) && ! str_contains( $message, 'hosted' ), "the {$name} message follows the copy rules" );
}

// LiteSpeed Cache active shows one notice naming it on TranslateWP screens only.
if ( ! defined( 'LSCWP_V' ) ) {
	define( 'LSCWP_V', '6.0' );
}
assert_true( [ 'LiteSpeed Cache' ] === CachePluginNotice::detected(), 'the LSCWP_V constant marks LiteSpeed Cache active' );
$dashboard = $render( 'toplevel_page_translatewp' );
assert_true( 1 === substr_count( $dashboard, 'class="notice ' ), 'LiteSpeed Cache active shows one notice on the TranslateWP Dashboard' );
assert_true( str_contains( $dashboard, 'LiteSpeed Cache is active.' ), 'the notice names LiteSpeed Cache' );
assert_true( str_contains( $render( 'translatewp_page_translatewp-settings' ), 'LiteSpeed Cache is active.' ), 'the notice shows on TranslateWP subpages' );
assert_true( '' === $render( 'dashboard' ), 'the notice is not shown on the WordPress Dashboard' );
assert_true( '' === $render( 'plugins' ), 'the notice is not shown on the Plugins screen' );
assert_true( '' === $render( 'toplevel_page_litespeed' ), 'the notice is not shown on the cache plugin screen' );
assert_true( str_contains( $dashboard, 'value="' . CachePluginNotice::DISMISS_ACTION . '"' ) && str_contains( $dashboard, 'admin-post.php' ), 'the notice carries a Dismiss action that is saved on the server' );

$GLOBALS['wp_test_caps'] = [];
assert_true( '' === $render( 'toplevel_page_translatewp' ), 'a user who cannot manage options sees no notice' );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

// A dismissed notice stays hidden for that user.
CachePluginNotice::dismiss();
assert_true( '' === $render( 'toplevel_page_translatewp' ), 'a dismissed notice stays hidden for that user' );
$GLOBALS['wp_test_current_user'] = 2;
assert_true( str_contains( $render( 'toplevel_page_translatewp' ), 'LiteSpeed Cache is active.' ), 'a dismissal belongs to the user who made it' );
$GLOBALS['wp_test_current_user'] = 1;
$GLOBALS['wp_test_user_meta'][1]['translatewp_cache_notice_dismissed'] = CachePluginNotice::fingerprint( [ 'WP Rocket' ] );
assert_true( str_contains( $render( 'toplevel_page_translatewp' ), 'LiteSpeed Cache is active.' ), 'the notice returns when the set of detected cache plugins changes' );

if ( $failures > 0 ) {
	echo "CachePluginNoticeTest: {$failures} failure(s)\n";
	exit( 1 );
}

echo "CachePluginNoticeTest: all assertions passed\n";
