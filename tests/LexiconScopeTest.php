<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationAiSettings;
use TranslateWP\Entities\CPTs\Lexicon;

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

wp_test_reset( [] );
$GLOBALS['wp_test_lexicon_posts'] = [
	10 => [ 'title' => 'zāle', 'content' => 'the dance hall' ],
	20 => [ 'title' => 'notebook', 'content' => 'a sketchbook' ],
	30 => [ 'title' => 'brand', 'content' => 'keep' ],
];
$GLOBALS['wp_test_lexicon_meta'] = [
	10 => [ Lexicon::LANGUAGES_META_KEY => [ 'de' ] ],
	30 => [ Lexicon::LANGUAGES_META_KEY => [] ],
];

$all = Lexicon::get_all();
assert_true( 3 === count( $all ) && [ 'de' ] === $all[0]['languages'] && [] === $all[1]['languages'], 'every term carries its language scope, an unscoped term an empty list, got ' . wp_json_encode( $all ) );

$german = Lexicon::terms_for_language( 'de' );
assert_true( [ [ 'term' => 'zāle', 'description' => 'the dance hall' ], [ 'term' => 'notebook', 'description' => 'a sketchbook' ], [ 'term' => 'brand', 'description' => 'keep' ] ] === $german, 'German receives the German term and the unscoped terms, got ' . wp_json_encode( $german ) );

$italian = Lexicon::terms_for_language( 'it' );
assert_true( [ [ 'term' => 'notebook', 'description' => 'a sketchbook' ], [ 'term' => 'brand', 'description' => 'keep' ] ] === $italian, 'Italian receives only the unscoped terms, got ' . wp_json_encode( $italian ) );

$settings = TranslationAiSettings::build( 'it' );
assert_true( $italian === $settings['lexicon_terms'], 'the settings for a language carry that language\'s terms' );
$browser = TranslationAiSettings::build();
assert_true( isset( $browser['lexicon_terms'][0]['languages'] ), 'the browser copy keeps every term with its scope' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LexiconScopeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LexiconScopeTest: all assertions passed\n";
