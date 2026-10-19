<?php

declare(strict_types=1);

// Comments, menus and widgets can be left untranslated: the visitor runtime
// is told which areas to skip, from the stored translation settings.

use TranslateWP\Components\Options;
use TranslateWP\Entities\Scripts\TranslateWP;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function excluded_areas( ?array $settings ): array {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );
	if ( null !== $settings ) {
		Options::update( 'translations.settings', $settings );
	}

	$options = ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->get_frontend_options();

	return $options['extraction_options']['excludedAreas'];
}

assert_true( [] === excluded_areas( null ), 'By default every area is translated, so nothing is excluded' );
assert_true( [] === excluded_areas( [ 'translate_comments' => true, 'translate_menus' => true, 'translate_widgets' => true ] ), 'Every area on is an empty list' );
assert_true( [ 'comments' ] === excluded_areas( [ 'translate_comments' => false ] ), 'Comments switched off alone' );
assert_true( [ 'comments', 'menus', 'widgets' ] === excluded_areas( [ 'translate_comments' => false, 'translate_menus' => false, 'translate_widgets' => false ] ), 'All three off, in a fixed order' );
assert_true( [ 'comments' ] === excluded_areas( [ 'translate_comments' => '0', 'translate_widgets' => '1' ] ), 'Stored strings are read as booleans' );

if ( $failures > 0 ) {
	fwrite( STDERR, "PageAreasTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "PageAreasTest: all assertions passed\n";
