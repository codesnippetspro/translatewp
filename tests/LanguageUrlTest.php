<?php

declare(strict_types=1);

// A translated page lives under its language folder: /de/about/ for the
// page at /about/. The default language keeps the plain address.

use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\Options;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_same( $expected, $actual, string $message ): void {
	global $failures;
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . ': expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . "\n" );
		$failures++;
	}
}

function seed_languages( string $home = 'https://example.com' ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_home_url'] = $home;
	Options::update(
		'languages.list',
		[
			'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
			'de'    => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
			'pt-br' => [ 'locale' => 'pt-br', 'slug' => 'pt-br', 'active' => true, 'default' => false ],
			'fr'    => [ 'locale' => 'fr', 'slug' => 'fr', 'active' => false, 'default' => false ],
		]
	);
}

seed_languages();

// --- Which language a request path names ---
assert_same( [ 'locale' => 'de', 'path' => '/about/' ], LanguageUrl::split_path( '/de/about/' ), 'A language folder names its language and leaves the page path' );
assert_same( [ 'locale' => 'de', 'path' => '/' ], LanguageUrl::split_path( '/de/' ), 'The language folder alone is that language home page' );
assert_same( [ 'locale' => 'de', 'path' => '/' ], LanguageUrl::split_path( '/de' ), 'The folder without a trailing slash is the same page' );
assert_same( [ 'locale' => 'pt-br', 'path' => '/a/b/' ], LanguageUrl::split_path( '/pt-br/a/b/' ), 'A regional slug is matched whole' );
assert_same( [ 'locale' => 'de', 'path' => '/about/' ], LanguageUrl::split_path( '/DE/about/' ), 'The folder is matched whatever its case' );
assert_same( [ 'locale' => '', 'path' => '/about/' ], LanguageUrl::split_path( '/about/' ), 'A plain path names no language' );
assert_same( [ 'locale' => '', 'path' => '/en/about/' ], LanguageUrl::split_path( '/en/about/' ), 'The default language has no folder' );
assert_same( [ 'locale' => '', 'path' => '/fr/about/' ], LanguageUrl::split_path( '/fr/about/' ), 'An inactive language has no folder' );
assert_same( [ 'locale' => '', 'path' => '/design/' ], LanguageUrl::split_path( '/design/' ), 'A page whose address only starts with a slug is no language folder' );

// --- The address of a page in a language ---
assert_same( 'https://example.com/de/about/', LanguageUrl::localize( 'https://example.com/about/', 'de' ), 'A translated page lives under its language folder' );
assert_same( 'https://example.com/de/', LanguageUrl::localize( 'https://example.com/', 'de' ), 'The translated home page is the folder' );
assert_same( 'https://example.com/de/', LanguageUrl::localize( 'https://example.com', 'de' ), 'A home address without a slash still gets the folder' );
assert_same( 'https://example.com/about/', LanguageUrl::localize( 'https://example.com/about/', 'en-us' ), 'The default language keeps the plain address' );
assert_same( 'https://example.com/pt-br/about/', LanguageUrl::localize( 'https://example.com/de/about/', 'pt-br' ), 'An address already in a language folder moves to the other folder' );
assert_same( 'https://example.com/about/', LanguageUrl::localize( 'https://example.com/de/about/', 'en-us' ), 'Back to the default language drops the folder' );
assert_same( 'https://example.com/de/about/?preview=1', LanguageUrl::localize( 'https://example.com/about/?preview=1&lang=fr', 'de' ), 'The old lang parameter is dropped and other parameters stay' );
assert_same( 'https://example.com/de/about/#team', LanguageUrl::localize( 'https://example.com/about/#team', 'de' ), 'A fragment stays' );
assert_same( 'https://other.example/about/', LanguageUrl::localize( 'https://other.example/about/', 'de' ), 'An address on another site is left alone' );
assert_same( 'https://example.com/about/', LanguageUrl::localize( 'https://example.com/about/', 'fr' ), 'An inactive language has no address of its own' );

// --- The page a translated address stands for ---
assert_same( 'https://example.com/about/', LanguageUrl::source( 'https://example.com/de/about/' ), 'The source page of a translated address' );
assert_same( 'https://example.com/about/?x=1', LanguageUrl::source( 'https://example.com/about/?lang=de&x=1' ), 'The source page of an old lang address' );

// --- A site in a folder keeps its folder first ---
seed_languages( 'https://example.com/shop' );
assert_same( [ 'locale' => 'de', 'path' => '/shop/about/' ], LanguageUrl::split_path( '/shop/de/about/' ), 'On a site in a folder the language folder follows the site folder' );
assert_same( [ 'locale' => '', 'path' => '/de/about/' ], LanguageUrl::split_path( '/de/about/' ), 'A path outside the site folder names no language' );
assert_same( 'https://example.com/shop/de/about/', LanguageUrl::localize( 'https://example.com/shop/about/', 'de' ), 'The address keeps the site folder first' );
assert_same( 'https://example.com/shop/de/', LanguageUrl::localize( 'https://example.com/shop', 'de' ), 'The translated home of a site in a folder' );

// --- The language of the request, set by the router ---
seed_languages();
assert_same( '', LanguageUrl::requested_locale(), 'No language is requested until the router says so' );
LanguageUrl::set_requested_locale( 'de' );
assert_same( 'de', LanguageUrl::requested_locale(), 'The router records the requested language' );
LanguageUrl::set_requested_locale( 'fr' );
assert_same( '', LanguageUrl::requested_locale(), 'An inactive language is never the requested one' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LanguageUrlTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LanguageUrlTest: all assertions passed\n";
