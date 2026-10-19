<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationContext;

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

function assert_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false !== strpos( $haystack, $needle ), $message . ' (missing "' . $needle . '" in: ' . $haystack . ')' );
}

function ends_with( string $haystack, string $needle ): bool {
	return '' === $needle || $needle === substr( $haystack, -strlen( $needle ) );
}

$dance_options = [
	'translations' => [
		'context' => [
			'text_context' => 'This is a sample dance school; "Salle" refers to a room, never a brand name.',
		],
	],
];

// --- Full block: site title, tagline, category, page, user text last ---
wp_test_reset( $dance_options );
$GLOBALS['wp_test_bloginfo'] = [
	'name'        => 'École de Danse Lumière',
	'description' => 'La danse pour toute la famille',
];
update_option( 'default_category', 5 );
$GLOBALS['wp_test_cat_names'][5] = 'Dance';

$block = TranslationContext::build( [ 'Grande salle' ] );
assert_contains( 'Site: École de Danse Lumière', $block, 'Block must carry the site title' );
assert_contains( 'Tagline: La danse pour toute la famille', $block, 'Block must carry the tagline' );
assert_contains( 'Category: Dance', $block, 'Block must carry the primary category' );
assert_contains( 'Page: Grande salle', $block, 'Block must carry the page title' );
assert_true(
	ends_with( $block, 'This is a sample dance school; "Salle" refers to a room, never a brand name.' ),
	'The user context text must come last, got: ' . $block
);

// --- Deterministic: identical inputs produce identical output ---
$again = TranslationContext::build( [ 'Grande salle' ] );
assert_true( $block === $again, 'The context block must be deterministic' );

// --- Several distinct page titles are listed and de-duplicated ---
$multi = TranslationContext::build( [ 'Grande salle', 'Petite salle', 'Grande salle' ] );
assert_contains( 'Pages: Grande salle; Petite salle', $multi, 'Multiple distinct page titles must be listed once each' );

// --- No page titles: the block omits the Page line ---
$no_page = TranslationContext::build();
assert_true( false === strpos( $no_page, 'Page' ), 'A block with no page titles must omit the Page line' );
assert_contains( 'Site: École de Danse Lumière', $no_page, 'Site title still present without page titles' );

// --- Uncategorized default category is treated as "not set" ---
wp_test_reset( [] );
$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'A Site' ];
update_option( 'default_category', 1 );
$GLOBALS['wp_test_cat_names'][1] = 'Uncategorized';
$no_category = TranslationContext::build();
assert_true( false === strpos( $no_category, 'Category:' ), 'The built-in Uncategorized default must not be sent as a category' );

// --- Empty tagline and no category: only the site line ---
wp_test_reset( [] );
$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'Bare Site', 'description' => '' ];
$bare = TranslationContext::build();
assert_true( 'Site: Bare Site' === $bare, 'A bare site yields only the site line, got: ' . $bare );

// --- The primary category is filterable ---
wp_test_reset( [] );
$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'Filtered Site' ];
add_filter(
	'translatewp_context_primary_category',
	static function () {
		return 'Yoga';
	}
);
$filtered = TranslationContext::build();
assert_contains( 'Category: Yoga', $filtered, 'The primary category filter must override the default' );

// --- The block is capped at 2000 characters, cleanly ---
wp_test_reset(
	[
		'translations' => [
			'context' => [
				'text_context' => str_repeat( 'word ', 1000 ),
			],
		],
	]
);
$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'Long Site' ];
$capped = TranslationContext::build();
assert_true( mb_strlen( $capped ) <= TranslationContext::MAX_CONTEXT_LENGTH, 'The block must be capped at the max length, got ' . mb_strlen( $capped ) );
assert_contains( 'Site: Long Site', $capped, 'The site line survives truncation at the front' );
assert_true( $capped === rtrim( $capped ), 'A cleanly truncated block has no trailing whitespace' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationContextTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslationContextTest: all assertions passed\n";
