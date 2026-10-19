<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationUrl;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_same_url( string $expected, string $actual, string $message ): void {
	global $failures;
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . " (expected '{$expected}', got '{$actual}')\n" );
		$failures++;
	}
}

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- Trailing slash: with and without collapse to the same key ---
assert_same_url(
	'https://example.com/about',
	TranslationUrl::canonicalize( 'https://example.com/about/' ),
	'A trailing slash must be dropped'
);
assert_same_url(
	TranslationUrl::canonicalize( 'https://example.com/about' ),
	TranslationUrl::canonicalize( 'https://example.com/about/' ),
	'A trailing slash must not change the canonical URL'
);

// --- Root path is kept as a single slash ---
assert_same_url(
	'https://example.com/',
	TranslationUrl::canonicalize( 'https://example.com/' ),
	'The root path must stay a single slash'
);
assert_same_url(
	'https://example.com/',
	TranslationUrl::canonicalize( 'https://example.com' ),
	'A missing path must become the root slash'
);

// --- Scheme: http and https collapse to https ---
assert_same_url(
	'https://example.com/about',
	TranslationUrl::canonicalize( 'http://example.com/about' ),
	'http must be normalised to https'
);
assert_same_url(
	TranslationUrl::canonicalize( 'https://example.com/about' ),
	TranslationUrl::canonicalize( 'http://example.com/about' ),
	'http and https must produce the same canonical URL'
);

// --- Host is lower-cased, path case is preserved ---
assert_same_url(
	'https://example.com/About',
	TranslationUrl::canonicalize( 'https://Example.COM/About' ),
	'The host must be lower-cased and the path case preserved'
);

// --- lang parameter present is stripped; absent is unchanged ---
assert_same_url(
	'https://example.com/about',
	TranslationUrl::canonicalize( 'https://example.com/about?lang=de' ),
	'A lone lang parameter must be stripped'
);
assert_same_url(
	TranslationUrl::canonicalize( 'https://example.com/about' ),
	TranslationUrl::canonicalize( 'https://example.com/about?lang=de' ),
	'A lang parameter must not change the canonical URL'
);

// --- lang combined with other query params: only lang is removed ---
assert_same_url(
	'https://example.com/shop?foo=bar',
	TranslationUrl::canonicalize( 'https://example.com/shop?foo=bar&lang=de' ),
	'Only lang must be stripped when other query params are present'
);
assert_same_url(
	'https://example.com/shop?foo=bar',
	TranslationUrl::canonicalize( 'https://example.com/shop?lang=de&foo=bar' ),
	'lang must be stripped regardless of its position in the query'
);
assert_same_url(
	'https://example.com/shop?foo=bar&baz=qux',
	TranslationUrl::canonicalize( 'https://example.com/shop/?foo=bar&lang=de&baz=qux' ),
	'Non-lang query params and a trailing slash must be handled together'
);

// --- The fragment is dropped ---
assert_same_url(
	'https://example.com/about',
	TranslationUrl::canonicalize( 'https://example.com/about/#section' ),
	'A fragment must be dropped'
);

// --- A page in a language folder is keyed as its source page ---
\TranslateWP\Components\Options::update(
	'languages.list',
	[
		'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
		'de'    => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
	]
);
assert_same_url(
	'https://example.com/about',
	TranslationUrl::canonicalize( 'https://example.com/de/about/' ),
	'A language folder must be dropped, so a translated address keys the same page as its source'
);
assert_same_url(
	'https://example.com/',
	TranslationUrl::canonicalize( 'https://example.com/de/' ),
	'The translated home page keys the home page'
);
assert_same_url(
	'https://example.com/design',
	TranslationUrl::canonicalize( 'https://example.com/design/' ),
	'A page whose address only starts like a language slug keeps its path'
);
\TranslateWP\Components\Options::update( 'languages.list', [] );

// --- An input with no host yields an empty string ---
assert_same_url( '', TranslationUrl::canonicalize( '' ), 'An empty URL yields an empty canonical URL' );
assert_same_url( '', TranslationUrl::canonicalize( '/relative/path' ), 'A host-less URL yields an empty canonical URL' );

// --- The function is idempotent ---
$once = TranslationUrl::canonicalize( 'https://example.com/shop/?lang=de&foo=bar#top' );
assert_same_url( $once, TranslationUrl::canonicalize( $once ), 'Canonicalisation must be idempotent' );

// --- is_indexable_url: ordinary page URLs are indexable ---
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/about' ), 'A plain page URL must be indexable' );
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/shop?foo=bar' ), 'A page URL with an unrelated query param must be indexable' );
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/' ), 'The root URL must be indexable' );

// --- is_indexable_url: a file is never a page ---
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/pakalpojumi/undefined/locales/fr/translation.json' ), 'A JSON file a script fetched must not be indexable' );
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/wp-content/uploads/brochure.pdf' ), 'A media file must not be indexable' );
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/robots.txt' ), 'robots.txt must not be indexable' );
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/app.webmanifest' ), 'A web app manifest must not be indexable' );
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/wp-content/themes/t/assets/lang.properties' ), 'A long file extension is still a file' );
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/about.html' ), 'An .html page must stay indexable' );
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/index.php?page_id=4' ), 'A .php page must stay indexable' );
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/v2.0/' ), 'A dotted directory is not a file' );

// --- is_indexable_url: each blocked query param rejects the URL ---
foreach ( [
	'customize_changeset_uuid',
	'customize_theme',
	'customize_messenger_channel',
	'preview',
	'preview_id',
	'preview_nonce',
	'elementor-preview',
	'et_fb',
	'vc_editable',
	'fl_builder',
	'wp_customize',
] as $blocked_param ) {
	assert_true(
		! TranslationUrl::is_indexable_url( 'https://example.com/about?' . $blocked_param . '=1' ),
		"A URL carrying {$blocked_param} must not be indexable"
	);
}

// --- is_indexable_url: the blocked param can ride alongside other params ---
assert_true(
	! TranslationUrl::is_indexable_url( 'https://example.com/about?foo=bar&customize_changeset_uuid=abc-123' ),
	'A blocked param combined with other query params must still reject the URL'
);

// --- is_indexable_url: wp-admin and wp-login paths are rejected ---
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/wp-admin/' ), 'wp-admin must not be indexable' );
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/wp-admin/customize.php' ), 'A path under wp-admin must not be indexable' );
assert_true( ! TranslationUrl::is_indexable_url( 'https://example.com/wp-login.php' ), 'wp-login must not be indexable' );

// --- is_indexable_url: a similarly-named path is not caught by the prefix check ---
assert_true( TranslationUrl::is_indexable_url( 'https://example.com/wp-admin-notice' ), 'A path that only shares the wp-admin prefix must stay indexable' );

// --- is_indexable_url: an unparseable URL is not indexable ---
assert_true( ! TranslationUrl::is_indexable_url( 'http:///bad' ), 'An unparseable URL must not be indexable' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationUrlTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslationUrlTest: all assertions passed\n";
