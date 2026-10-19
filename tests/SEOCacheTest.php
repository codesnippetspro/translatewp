<?php

declare(strict_types=1);

use TranslateWP\Functionality\SEO;

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

function sent_cache_control_is_private_no_store(): bool {
	foreach ( $GLOBALS['wp_test_sent_headers'] as $header ) {
		if ( 0 === stripos( $header, 'Cache-Control:' )
			&& false !== stripos( $header, 'no-store' )
			&& false !== stripos( $header, 'private' ) ) {
			return true;
		}
	}

	return false;
}

$languages = [
	'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
	'es' => [ 'locale' => 'es', 'slug' => 'es', 'active' => true, 'default' => false ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
];

// A locale whose switcher slug differs from the locale (?lang=en selects en-us).
$slug_languages = [
	'lv'    => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
	'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => false ],
];

// --- No param, logged out: nothing set ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$GLOBALS['wp_test_logged_in']    = false;
seo_test_request_language( '' );
$GLOBALS['wp_test_sent_headers'] = [];
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$seo->prevent_personalized_page_caching();
assert_true( [] === $GLOBALS['wp_test_sent_headers'], 'No lang param must not send nocache headers' );
assert_true( ! defined( 'DONOTCACHEPAGE' ), 'No lang param must not define DONOTCACHEPAGE' );

// --- ?lang=en (default), logged out: nothing set ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$GLOBALS['wp_test_logged_in']    = false;
seo_test_request_language( 'en' );
$GLOBALS['wp_test_sent_headers'] = [];
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$seo->prevent_personalized_page_caching();
assert_true( [] === $GLOBALS['wp_test_sent_headers'], 'Default-language param must not send nocache headers' );
assert_true( ! defined( 'DONOTCACHEPAGE' ), 'Default-language param must not define DONOTCACHEPAGE' );

// --- Unknown ?lang=xx, logged out: nothing set ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$GLOBALS['wp_test_logged_in']    = false;
seo_test_request_language( 'xx' );
$GLOBALS['wp_test_sent_headers'] = [];
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$seo->prevent_personalized_page_caching();
assert_true( [] === $GLOBALS['wp_test_sent_headers'], 'Unknown lang param must not send nocache headers' );
assert_true( ! defined( 'DONOTCACHEPAGE' ), 'Unknown lang param must not define DONOTCACHEPAGE' );

// --- html lang rewrite still works for an active locale ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$GLOBALS['wp_test_logged_in'] = false;
seo_test_request_language( 'de' );
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$output = $seo->set_html_lang_from_query( 'lang="en-US" dir="ltr"' );
assert_true( 'lang="de" dir="ltr"' === $output, 'html lang must rewrite to the requested locale, got ' . $output );

// --- a right-to-left language sets the document direction; a left-to-right one restores it ---
wp_test_reset( [ 'languages' => [ 'list' => array_merge( $languages, [ [ 'locale' => 'ar', 'slug' => 'ar', 'active' => true, 'default' => false ] ] ) ] ] );
$GLOBALS['wp_test_logged_in'] = false;
seo_test_request_language( 'ar' );
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
assert_true( 'lang="ar" dir="rtl"' === $seo->set_html_lang_from_query( 'lang="en-US" dir="ltr"' ), 'an rtl language must set dir rtl' );
assert_true( 'lang="ar" dir="rtl"' === $seo->set_html_lang_from_query( 'lang="en-US"' ), 'an rtl language must add dir rtl when the theme printed none' );
seo_test_request_language( 'de' );
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
assert_true( 'lang="de" dir="ltr"' === $seo->set_html_lang_from_query( 'lang="ar" dir="rtl"' ), 'a ltr language must restore dir ltr' );
assert_true( 'lang="de"' === $seo->set_html_lang_from_query( 'lang="en-US"' ), 'a ltr language adds no dir when the theme printed none' );

// --- ?lang= accepts the language slug and resolves to the locale ---
wp_test_reset( [ 'languages' => [ 'list' => $slug_languages ] ] );
$GLOBALS['wp_test_logged_in'] = false;
seo_test_request_language( 'en' );
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$output = $seo->set_html_lang_from_query( 'lang="lv" dir="ltr"' );
assert_true( 'lang="en-us" dir="ltr"' === $output, 'html lang must resolve a slug to its locale, got ' . $output );

// --- Logged-in render (no lang param): not cacheable (first to define the constant) ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$GLOBALS['wp_test_logged_in']    = true;
seo_test_request_language( '' );
$GLOBALS['wp_test_sent_headers'] = [];
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$seo->prevent_personalized_page_caching();
assert_true( ! empty( $GLOBALS['wp_test_sent_headers'] ), 'Logged-in render must send nocache headers' );
assert_true( sent_cache_control_is_private_no_store(), 'Logged-in render must send Cache-Control private, no-store' );
assert_true( defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE, 'Logged-in render must define DONOTCACHEPAGE' );

// --- ?lang=de (non-default), logged out: not cacheable ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$GLOBALS['wp_test_logged_in']    = false;
seo_test_request_language( 'de' );
$GLOBALS['wp_test_sent_headers'] = [];
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$seo->prevent_personalized_page_caching();
assert_true( ! empty( $GLOBALS['wp_test_sent_headers'] ), '?lang=de must send nocache headers' );
assert_true( sent_cache_control_is_private_no_store(), '?lang=de must send Cache-Control private, no-store' );

// --- ?lang= slug for a non-default locale, logged out: not cacheable ---
wp_test_reset( [ 'languages' => [ 'list' => $slug_languages ] ] );
$GLOBALS['wp_test_logged_in']    = false;
seo_test_request_language( 'en' );
$GLOBALS['wp_test_sent_headers'] = [];
$seo = new SEO( 'translatewp', TRANSLATEWP_VERSION );
$seo->prevent_personalized_page_caching();
assert_true( ! empty( $GLOBALS['wp_test_sent_headers'] ), 'Slug for a non-default locale must send nocache headers' );
assert_true( sent_cache_control_is_private_no_store(), 'Slug for a non-default locale must send Cache-Control private, no-store' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SEOCacheTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SEOCacheTest: all assertions passed\n";
