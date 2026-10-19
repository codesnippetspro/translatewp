<?php

declare(strict_types=1);

use TranslateWP\Functionality\SEO;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_attr( string $actual, string $expected, string $message ): void {
	global $failures;
	if ( $actual !== $expected ) {
		fwrite( STDERR, $message . ' (got: ' . $actual . ")\n" );
		$failures++;
	}
}

wp_test_reset(
	[
		'languages' => [
			'list' => [
				'ar' => [ 'locale' => 'ar', 'slug' => 'ar', 'active' => true, 'default' => true ],
				'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				'he' => [ 'locale' => 'he', 'slug' => 'he', 'active' => true, 'default' => false ],
			],
		],
	]
);
$seo = new SEO( 'translatewp', '0.10.0' );

// --- A German view of an Arabic site: dir printed first by the theme turns ltr ---
seo_test_request_language( 'de' );
assert_attr( $seo->set_html_lang_from_query( 'dir="rtl" lang="ar"' ), 'dir="ltr" lang="de"', 'dir printed before lang must follow the requested language' );
assert_attr( $seo->set_html_lang_from_query( 'lang="ar" dir="rtl"' ), 'lang="de" dir="ltr"', 'dir printed after lang must follow the requested language' );

// --- A Hebrew view of the same site keeps rtl ---
seo_test_request_language( 'he' );
assert_attr( $seo->set_html_lang_from_query( 'dir="rtl" lang="ar"' ), 'dir="rtl" lang="he"', 'a right-to-left target keeps dir rtl' );

// --- A right-to-left target on a theme that prints no dir gains one ---
assert_attr( $seo->set_html_lang_from_query( 'lang="ar"' ), 'lang="he" dir="rtl"', 'a right-to-left target adds dir when the theme printed none' );
seo_test_request_language( '' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SEOHtmlAttributesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SEOHtmlAttributesTest: all assertions passed\n";
