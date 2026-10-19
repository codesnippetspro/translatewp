<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationProxyClientIp;

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

wp_test_reset( [] );
wp_test_set_filter(
	'translatewp_trusted_proxy_hops',
	static function () {
		return 1;
	}
);
wp_test_set_filter(
	'translatewp_trusted_proxies',
	static function () {
		return [ '203.0.113.10' ];
	}
);

$_SERVER['REMOTE_ADDR']           = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 203.0.113.10';
assert_true(
	'198.51.100.20' === TranslationProxyClientIp::resolve(),
	'Client IP must resolve from the right by configured trusted hops'
);

unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
assert_true(
	'' === TranslationProxyClientIp::resolve(),
	'Missing XFF must fail closed when trusted hops are configured'
);

$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
assert_true(
	'' === TranslationProxyClientIp::resolve(),
	'Untrusted REMOTE_ADDR must fail closed when trusted hops are configured'
);

wp_test_set_filter(
	'translatewp_trusted_proxies',
	static function () {
		return [ '203.0.113.0/33' ];
	}
);
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
assert_true(
	! TranslationProxyClientIp::is_trusted_proxy( '203.0.113.10' ),
	'IPv4 CIDR masks above 32 must be rejected'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationProxyClientIpTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "TranslationProxyClientIpTest ok\n" );
