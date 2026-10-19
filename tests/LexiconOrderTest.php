<?php

declare(strict_types=1);

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

// Insertion order is deliberately NOT id order: without an explicit orderby
// the returned list would follow insertion order and rotate the fingerprint.
wp_test_reset( [] );
$GLOBALS['wp_test_lexicon_posts'] = [
	30 => [ 'title' => 'Brand C', 'content' => 'third' ],
	10 => [ 'title' => 'Brand A', 'content' => 'first' ],
	20 => [ 'title' => 'Brand B', 'content' => 'second' ],
];
$GLOBALS['wp_test_lexicon_meta'] = [
	10 => [ Lexicon::LANGUAGES_META_KEY => [ 'es' ] ],
	20 => [ Lexicon::LANGUAGES_META_KEY => [ 'es' ] ],
	30 => [ Lexicon::LANGUAGES_META_KEY => [ 'es' ] ],
];

$all = Lexicon::get_all();
assert_true( [ 10, 20, 30 ] === array_column( $all, 'id' ), 'get_all must return lexicon posts in ascending ID order, got ' . wp_json_encode( array_column( $all, 'id' ) ) );
assert_true( [ 'Brand A', 'Brand B', 'Brand C' ] === array_column( $all, 'term' ), 'get_all must keep term order aligned with ID order' );

$from_language = Lexicon::from_language( 'es' );
assert_true( [ 10, 20, 30 ] === array_column( $from_language, 'id' ), 'from_language must return lexicon posts in ascending ID order, got ' . wp_json_encode( array_column( $from_language, 'id' ) ) );

$first  = wp_json_encode( Lexicon::get_all() );
$second = wp_json_encode( Lexicon::get_all() );
assert_true( $first === $second, 'Repeated reads must produce identical order (stable fingerprint input)' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LexiconOrderTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LexiconOrderTest: all assertions passed\n";
