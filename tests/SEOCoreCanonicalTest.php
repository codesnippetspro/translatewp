<?php

declare(strict_types=1);

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
	'ar' => [ 'locale' => 'ar', 'slug' => 'ar', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
];

wp_test_reset(
	[
		'languages' => [
			'list' => $languages,
			'seo'  => [ 'url_structure' => 'query_string', 'output_hreflang' => true, 'include_x_default' => true ],
		],
	]
);
seo_test_request_language( '' );
$GLOBALS['wp_test_is_singular']       = true;
$GLOBALS['wp_test_queried_object_id'] = 42;
$GLOBALS['wp_test_permalinks'][42]    = 'https://example.com/about/';
$GLOBALS['wp_test_removed_actions']   = [];

// --- With no SEO plugin, core's canonical comes off wp_head ---
$seo = new SEO( 'translatewp', '0.10.20' );
$seo->register_language_tags();
$removed = array_filter( $GLOBALS['wp_test_removed_actions'], static fn( array $entry ): bool => 'wp_head' === $entry[0] && 'rel_canonical' === $entry[1] );
assert_true( 1 === count( $removed ), 'Core rel_canonical must be removed from wp_head so a page never carries two canonicals' );

// --- A translated page prints exactly one canonical, the per-language one ---
seo_test_request_language( 'de' );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( 1 === substr_count( $output, 'rel="canonical"' ), 'A translated page prints exactly one canonical, got: ' . $output );
assert_true( false !== strpos( $output, 'href="https://example.com/de/about/"' ), 'The one canonical is the per-language URL, got: ' . $output );
assert_true( false === strpos( $output, 'core-canonical' ), 'Core canonical must not be printed next to the per-language one' );

// --- Where the per-language canonical does not apply, core's is printed back ---
$GLOBALS['wp_test_is_singular'] = false;
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( '<link rel="canonical" href="core-canonical" />' . "\n" === $output, 'A page that is not content prints core canonical back, got: ' . $output );
$GLOBALS['wp_test_is_singular'] = true;

wp_test_reset( [ 'languages' => [ 'list' => [] ] ] );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( '<link rel="canonical" href="core-canonical" />' . "\n" === $output, 'A site with no languages prints core canonical back, got: ' . $output );

wp_test_reset( [ 'languages' => [ 'list' => [ 'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ] ] ] ] );
seo_test_request_language( '' );
$output = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( '<link rel="canonical" href="core-canonical" />' . "\n" === $output, 'A site with no default language prints core canonical back, got: ' . $output );

$GLOBALS['wp_test_queried_object_id'] = 0;
$GLOBALS['wp_test_permalinks']        = [];
unset( $GLOBALS['wp_test_is_singular'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "SEOCoreCanonicalTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SEOCoreCanonicalTest: all assertions passed\n";
