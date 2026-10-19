<?php

declare(strict_types=1);

use TranslateWP\Components\UrlScheme;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- https request on a site whose stored address is http: REST and own assets follow the request ---
$GLOBALS['wp_test_is_ssl'] = true;
assert_true( 'https://shop.example/wp-json/translatewp/v1/admin' === UrlScheme::match_request_scheme( 'http://shop.example/wp-json/translatewp/v1/admin' ), 'REST address must follow an https request' );
$own = dirname( __DIR__ ) . '/translatewp.php';
assert_true( 'https://shop.example/wp-content/plugins/translate/build/admin.js' === UrlScheme::match_plugin_asset_scheme( 'http://shop.example/wp-content/plugins/translate/build/admin.js', 'build/admin.js', $own ), 'Own plugin asset must follow an https request' );
assert_true( 'http://shop.example/wp-content/plugins/other/x.js' === UrlScheme::match_plugin_asset_scheme( 'http://shop.example/wp-content/plugins/other/x.js', 'x.js', '/srv/plugins/other/other.php' ), 'Other plugins are left alone' );
$slug = basename( dirname( __DIR__ ) );
assert_true( 'https://shop.example/wp-content/plugins/' . $slug . '/build/translatewp.js' === UrlScheme::match_plugin_asset_scheme( 'http://shop.example/wp-content/plugins/' . $slug . '/build/translatewp.js', 'build/translatewp.js', '' ), 'Own asset matched by directory when no plugin file is passed' );

// --- plain http request behind a tunnel that forwards https: treated as https ---
$GLOBALS['wp_test_is_ssl'] = false;
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
assert_true( 'https://shop.example/wp-json/translatewp/v1/admin' === UrlScheme::match_request_scheme( 'http://shop.example/wp-json/translatewp/v1/admin' ), 'A forwarded https request must count as https' );
unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
$_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}';
assert_true( true === UrlScheme::request_is_https(), 'A tunnel visitor header naming https must count as https' );
unset( $_SERVER['HTTP_CF_VISITOR'] );

// --- http request: nothing is forced to https ---
$GLOBALS['wp_test_is_ssl'] = false;
assert_true( 'http://shop.example/wp-json/translatewp/v1/admin' === UrlScheme::match_request_scheme( 'https://shop.example/wp-json/translatewp/v1/admin' ), 'REST address must follow an http request' );

if ( $failures > 0 ) {
	fwrite( STDERR, "UrlSchemeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "UrlSchemeTest: all assertions passed\n";
