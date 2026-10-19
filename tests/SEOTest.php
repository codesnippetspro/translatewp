<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\SEO;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';
require_once __DIR__ . '/seo-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$languages = [
	'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
	'es-es' => [ 'locale' => 'es-es', 'slug' => 'es', 'active' => true, 'default' => false ],
	'de-de' => [ 'locale' => 'de-de', 'slug' => 'de', 'active' => false, 'default' => false ],
];

function reset_seo_test( array $languages, array $seo = [] ): void {
	wp_test_reset(
		[
			'languages' => [
				'list' => $languages,
				'seo'  => $seo + [ 'url_structure' => 'query_string' ],
			],
		]
	);
	seo_test_request_language( '' );
	$GLOBALS['wp_test_removed_actions']   = [];
	$GLOBALS['wp_test_is_singular']       = true;
	$GLOBALS['wp_test_queried_object_id'] = 42;
	$GLOBALS['wp_test_permalinks'][42]    = 'https://example.com/about/?preview=1&lang=fr-fr';
}

reset_seo_test( $languages );
$seo = new SEO( 'translatewp', '0.10.20' );

// --- The tags register once every plugin has loaded, so an SEO plugin or the addon is known ---
assert_true( seo_test_registered( 'plugins_loaded', 'register_language_tags' ), 'The language tags must be registered on plugins_loaded' );
$seo->register_language_tags();
assert_true( seo_test_registered( 'wp_head', 'add_hreflang_tags' ), 'Hreflang must print on wp_head' );
assert_true( seo_test_registered( 'wp_head', 'add_canonical_tag' ), 'The per-language canonical must print on wp_head' );

// --- URLs are the language folder form; the default language has no folder ---
assert_true(
	'https://example.com/about/?preview=1' === $seo->localized_url( 'https://example.com/about/?preview=1&lang=fr-fr', 'en-us' ),
	'The default-language URL must remove the language query parameter'
);
assert_true(
	'https://example.com/es/about/?preview=1' === $seo->localized_url( 'https://example.com/about/?preview=1&lang=fr-fr', 'es-es' ),
	'A translated URL must keep other query parameters and move to the language folder'
);

// --- Two active languages: one hreflang each plus x-default, no API or plan check ---
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );

// A page left out of translation advertises no translated versions of itself.
$GLOBALS['wp_test_queried_object_id'] = 4242;
$GLOBALS['wp_test_inserted_posts'][]  = [ 'ID' => 4242, 'post_type' => 'page', 'meta_input' => [ '_translatewp_exclude' => '1' ] ];
assert_true( '' === seo_test_capture( [ $seo, 'add_hreflang_tags' ] ), 'An excluded page must print no hreflang tag' );
$GLOBALS['wp_test_queried_object_id'] = 0;
assert_true( false !== strpos( $output, '<link rel="alternate" hreflang="en-US" href="https://example.com/about/?preview=1" />' ), 'The default language hreflang must print, got: ' . $output );
assert_true( false !== strpos( $output, '<link rel="alternate" hreflang="es-ES" href="https://example.com/es/about/?preview=1" />' ), 'The translated language hreflang must print, got: ' . $output );
assert_true( false === strpos( $output, 'de-DE' ), 'An inactive language must be left out' );
assert_true( false !== strpos( $output, 'hreflang="x-default" href="https://example.com/about/?preview=1"' ), 'x-default must point at the default language URL' );
assert_true( 3 === substr_count( $output, 'rel="alternate"' ), 'Exactly two hreflang tags plus x-default, got: ' . $output );

// --- The options default to on when the site never saved them ---
reset_seo_test( $languages );
Options::update( 'languages.seo', [] );
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );
assert_true( 3 === substr_count( $output, 'rel="alternate"' ), 'Unsaved options must default to hreflang with x-default, got: ' . $output );

// --- x-default off ---
reset_seo_test( $languages, [ 'output_hreflang' => true, 'include_x_default' => false ] );
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );
assert_true( false === strpos( $output, 'x-default' ), 'x-default must not print when the option is off, got: ' . $output );
assert_true( 2 === substr_count( $output, 'rel="alternate"' ), 'The language hreflang tags still print without x-default, got: ' . $output );

// --- hreflang off ---
reset_seo_test( $languages, [ 'output_hreflang' => false, 'include_x_default' => true ] );
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );
assert_true( '' === $output, 'No hreflang may print when output_hreflang is off, got: ' . $output );

// --- Only content pages carry the tags ---
reset_seo_test( $languages );
$GLOBALS['wp_test_is_singular'] = false;
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );
assert_true( '' === $output, 'An archive or search page must not carry hreflang, got: ' . $output );

// --- The front page falls back to the home URL ---
$GLOBALS['wp_test_is_front_page']     = true;
$GLOBALS['wp_test_queried_object_id'] = 0;
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );
assert_true( false !== strpos( $output, 'hreflang="x-default" href="https://example.com/"' ), 'The front page must use the home URL, got: ' . $output );
unset( $GLOBALS['wp_test_is_front_page'] );

// --- The canonical points at the current language's own URL ---
reset_seo_test( $languages );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( '<link rel="canonical" href="https://example.com/about/?preview=1" />' . "\n" === $output, 'The default-language page must canonicalize to itself without lang, got: ' . $output );

seo_test_request_language( 'es-es' );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( false !== strpos( $output, '<link rel="canonical" href="https://example.com/es/about/?preview=1" />' ), 'A translated page must canonicalize to its own language URL, got: ' . $output );
assert_true( 1 === substr_count( $output, 'rel="canonical"' ), 'Exactly one canonical, got: ' . $output );

// The folder is the slug; the canonical names the same folder.
seo_test_request_language( 'es' );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( false !== strpos( $output, 'href="https://example.com/es/about/?preview=1" />' ), 'A page in a language folder must canonicalize to its own language, got: ' . $output );

// An inactive language is not a language of the site: the canonical is the default one.
seo_test_request_language( 'de-de' );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( '<link rel="canonical" href="https://example.com/about/?preview=1" />' . "\n" === $output, 'An inactive language must canonicalize to the default URL, got: ' . $output );
seo_test_request_language( '' );

// --- A position-indexed list with the default in the middle ---
$indexed = [
	[ 'locale' => 'ja-jp', 'slug' => 'ja', 'active' => true, 'default' => false ],
	[ 'locale' => 'pt', 'slug' => 'pt', 'active' => true, 'default' => true ],
	[ 'locale' => 'ar', 'slug' => 'ar', 'active' => true, 'default' => false ],
];
reset_seo_test( $indexed, [ 'output_hreflang' => true, 'include_x_default' => true ] );
$GLOBALS['wp_test_queried_object_id'] = 77;
$GLOBALS['wp_test_permalinks'][77]    = 'https://example.com/schedule/';
$output = seo_test_capture( [ $seo, 'add_hreflang_tags' ] );
assert_true( false !== strpos( $output, 'hreflang="pt" href="https://example.com/schedule/"' ), 'The indexed list must print the default hreflang, got: ' . $output );
assert_true( false !== strpos( $output, 'hreflang="ja-JP" href="https://example.com/ja/schedule/"' ), 'The indexed list must print the Japanese hreflang, got: ' . $output );
assert_true( false !== strpos( $output, 'hreflang="ar" href="https://example.com/ar/schedule/"' ), 'The indexed list must print the Arabic hreflang, got: ' . $output );
assert_true( false !== strpos( $output, 'hreflang="x-default" href="https://example.com/schedule/"' ), 'The indexed list must print x-default, got: ' . $output );

$GLOBALS['wp_test_queried_object_id'] = 0;
$GLOBALS['wp_test_permalinks']        = [];
unset( $GLOBALS['wp_test_is_singular'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "SEOTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SEOTest: all assertions passed\n";
