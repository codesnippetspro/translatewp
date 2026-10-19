<?php

declare(strict_types=1);

// A request for /de/about/ is the page /about/ in German: the language
// folder is taken off before WordPress resolves the page. The old ?lang=
// form answers with a permanent redirect to the folder form.

use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\Options;
use TranslateWP\Functionality\LanguageRouting;

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

function request( string $uri ): LanguageRouting {
	wp_test_reset( [] );
	Options::update(
		'languages.list',
		[
			'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
			'de'    => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
		]
	);
	LanguageUrl::set_requested_locale( '' );
	$_SERVER['REQUEST_URI'] = $uri;
	$_GET                   = [];
	parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $_GET );

	return new LanguageRouting( 'translatewp', '1.0.0' );
}

// --- The language folder is taken off before WordPress resolves the page ---
$routing = request( '/de/about/?x=1' );
assert_same( true, $routing->route( true ), 'Routing never stops WordPress parsing the request' );
assert_same( '/about/?x=1', $_SERVER['REQUEST_URI'], 'WordPress resolves the page path without the language folder' );
assert_same( 'de', LanguageUrl::requested_locale(), 'The request is recorded as German' );
assert_same( 'https://example.com/de/about/?x=1', $routing->requested_url(), 'The address the visitor asked for is kept' );

// --- An older stored role list leaves no visitor out: the locale is recorded ---
$routing = request( '/de/about/' );
\TranslateWP\Components\Options::update( 'translations.settings', [ 'visible_roles' => [ 'editor' ] ] );
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => true );
LanguageUrl::set_requested_locale( '' );
$routing->route( true );
assert_same( '/about/', $_SERVER['REQUEST_URI'], 'The page path resolves' );
assert_same( 'de', LanguageUrl::requested_locale(), 'The locale is recorded for every visitor, whatever role list is stored' );
\TranslateWP\Components\Options::update( 'translations.settings', [ 'visible_roles' => [] ] );
$GLOBALS['wp_test_filters'] = [];

$routing = request( '/de' );
$routing->route( true );
assert_same( '/', $_SERVER['REQUEST_URI'], 'The language folder alone is the home page' );

$routing = request( '/about/' );
$routing->route( true );
assert_same( '/about/', $_SERVER['REQUEST_URI'], 'A plain request is untouched' );
assert_same( '', LanguageUrl::requested_locale(), 'A plain request asks for no translation language' );

// --- The old ?lang= form redirects permanently to the folder form ---
$routing = request( '/about/?lang=de&x=1' );
$routing->route( true );
assert_same( 'https://example.com/de/about/?x=1', $routing->lang_parameter_redirect(), 'The lang parameter redirects to the folder form and keeps other parameters' );

$routing = request( '/about/?lang=en-us' );
$routing->route( true );
assert_same( 'https://example.com/about/', $routing->lang_parameter_redirect(), 'The default language redirects to the plain address' );

$routing = request( '/de/about/?lang=de' );
$routing->route( true );
assert_same( 'https://example.com/de/about/', $routing->lang_parameter_redirect(), 'A folder address that still carries the parameter loses it' );

$routing = request( '/about/?lang=xx' );
$routing->route( true );
assert_same( '', $routing->lang_parameter_redirect(), 'A lang value that is none of the site languages is left to whoever owns it' );

$routing = request( '/about/' );
$routing->route( true );
assert_same( '', $routing->lang_parameter_redirect(), 'No parameter, no redirect' );

// --- A language folder written in another case redirects to the one form ---
$routing = request( '/DE/about/?x=a%20b' );
$routing->route( true );
assert_same( 'https://example.com/de/about/?x=a%20b', $routing->folder_case_redirect(), 'A folder in capitals redirects to the lower-case folder and keeps the query as it was written' );

$routing = request( '/de/about/?x=a%20b' );
$routing->route( true );
assert_same( '', $routing->folder_case_redirect(), 'The one form of the folder redirects nowhere' );

$routing = request( '/about/' );
$routing->route( true );
assert_same( '', $routing->folder_case_redirect(), 'A plain address redirects nowhere' );

// --- A canonical redirect WordPress makes keeps the language ---
$routing = request( '/de/About' );
$routing->route( true );
assert_same( 'https://example.com/de/about/', $routing->keep_language_on_canonical_redirect( 'https://example.com/about/' ), 'A canonical redirect stays in the language folder' );
assert_same( false, $routing->keep_language_on_canonical_redirect( false ), 'No canonical redirect stays no redirect' );
assert_same( false, $routing->keep_language_on_canonical_redirect( 'https://example.com/de/About' ), 'A redirect to the address already asked for is dropped, so it cannot loop' );

$routing = request( '/About' );
$routing->route( true );
assert_same( 'https://example.com/about/', $routing->keep_language_on_canonical_redirect( 'https://example.com/about/' ), 'A plain request keeps its plain canonical redirect' );

// --- Admin requests are never rerouted ---
$routing = request( '/de/about/' );
$GLOBALS['wp_test_is_admin'] = true;
$routing->route( true );
assert_same( '/de/about/', $_SERVER['REQUEST_URI'], 'An admin request is left alone' );
$GLOBALS['wp_test_is_admin'] = false;

if ( $failures > 0 ) {
	fwrite( STDERR, "LanguageRoutingTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LanguageRoutingTest: all assertions passed\n";
