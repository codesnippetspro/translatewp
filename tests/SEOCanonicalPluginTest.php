<?php

declare(strict_types=1);

use TranslateWP\Functionality\SEO;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';
require_once __DIR__ . '/seo-test-stubs.php';

define( 'WPSEO_VERSION', '22.0' );
define( 'RANK_MATH_VERSION', '1.0.230' );

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
$GLOBALS['wp_test_removed_actions']   = [];
$GLOBALS['wp_test_is_singular']       = true;
$GLOBALS['wp_test_queried_object_id'] = 42;
$GLOBALS['wp_test_permalinks'][42]    = 'https://example.com/rooms/';

$seo = new SEO( 'translatewp', '0.10.20' );
$seo->register_language_tags();

// --- The SEO plugin keeps its canonical; ours is not registered and core's stays put ---
assert_true( ! seo_test_registered( 'wp_head', 'add_canonical_tag' ), 'No own canonical may be registered while an SEO plugin is active' );
assert_true( [] === $GLOBALS['wp_test_removed_actions'], 'Core rel_canonical is left to the SEO plugin' );
assert_true( seo_test_registered( 'wp_head', 'add_hreflang_tags' ), 'Hreflang still prints next to an SEO plugin' );
assert_true( seo_test_filter_is( 'wpseo_canonical', 'filter_seo_plugin_canonical' ), 'The Yoast canonical must be filtered' );
assert_true( seo_test_filter_is( 'rank_math/frontend/canonical', 'filter_seo_plugin_canonical' ), 'The Rank Math canonical must be filtered' );

seo_test_request_language( 'es-es' );
$own = seo_test_capture( [ $seo, 'add_canonical_tag' ] );
assert_true( '' === $own, 'No second canonical may be printed while an SEO plugin is active, got: ' . $own );

// --- The plugin canonical is localized to the active language's folder ---
foreach ( [ 'wpseo_canonical', 'rank_math/frontend/canonical' ] as $hook ) {
	$filtered = apply_filters( $hook, 'https://example.com/rooms/' );
	assert_true( 'https://example.com/es/rooms/' === $filtered, $hook . ' must be localized to the active language URL, got: ' . var_export( $filtered, true ) );

	$with_query = apply_filters( $hook, 'https://example.com/rooms/?preview=1' );
	assert_true( 'https://example.com/es/rooms/?preview=1' === $with_query, $hook . ' must keep other query parameters, got: ' . var_export( $with_query, true ) );
}

seo_test_request_language( 'es' );
assert_true( 'https://example.com/es/rooms/' === $seo->filter_seo_plugin_canonical( 'https://example.com/rooms/' ), 'A request in the language folder must localize to that folder' );

// --- The default-language page and a non-string canonical are left alone ---
seo_test_request_language( '' );
assert_true( 'https://example.com/rooms/' === apply_filters( 'wpseo_canonical', 'https://example.com/rooms/' ), 'The default-language canonical must stay bare' );
seo_test_request_language( 'es-es' );
assert_true( false === $seo->filter_seo_plugin_canonical( false ), 'A canonical the plugin turned off must stay off' );
seo_test_request_language( '' );

$GLOBALS['wp_test_queried_object_id'] = 0;
$GLOBALS['wp_test_permalinks']        = [];
unset( $GLOBALS['wp_test_is_singular'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "SEOCanonicalPluginTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SEOCanonicalPluginTest: all assertions passed\n";
