<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationUrl;

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
$GLOBALS['wp_test_home_url'] = 'https://shop.example';

assert_true( 'https://shop.example/menu/?x=1' === TranslationUrl::rebase_to_home( 'https://old-host.example/menu/?x=1' ), 'A stored URL on another host is rebased onto the current home address, path and query kept' );
assert_true( 'https://shop.example/about/' === TranslationUrl::rebase_to_home( 'https://shop.example/about/' ), 'A URL already on the home host is unchanged' );
assert_true( 'https://SHOP.example/about/' === TranslationUrl::rebase_to_home( 'https://SHOP.example/about/' ), 'Host comparison is case-insensitive, the URL is left as is' );
assert_true( 'not a url' === TranslationUrl::rebase_to_home( 'not a url' ), 'An unparsable value is returned as is' );

$GLOBALS['wp_test_home_url'] = 'http://localhost:8890';
assert_true( 'http://localhost:8890/category/recipes' === TranslationUrl::rebase_to_home( 'https://localhost:8890/category/recipes' ), 'A canonical https URL on a site served over http is fetched over http' );
assert_true( 'http://localhost:8890/about/' === TranslationUrl::rebase_to_home( 'http://localhost:8890/about/' ), 'A URL already on the home scheme and host is unchanged' );

$GLOBALS['wp_test_multisite'] = true;
assert_true( 'https://mapped.example/about/' === TranslationUrl::rebase_to_home( 'https://mapped.example/about/' ), 'Multisite preserves the mapped address' );
$GLOBALS['wp_test_multisite'] = false;
update_option( 'siteurl', 'https://backend.example' );
assert_true( 'https://backend.example/about/' === TranslationUrl::rebase_to_home( 'https://backend.example/about/' ), 'The separate configured WordPress host stays intact' );

if ( $failures > 0 ) {
	fwrite( STDERR, "RebaseToHomeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "RebaseToHomeTest: all assertions passed\n";
