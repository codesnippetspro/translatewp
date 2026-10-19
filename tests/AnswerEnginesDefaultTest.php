<?php
declare( strict_types=1 );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

use TranslateWP\Components\NetworkScope;
use TranslateWP\Entities\Scripts\TranslateWP;

$failures = 0;
function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$languages = [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ];

// --- a site without a stored value lets answer engines index its translations ---
wp_test_reset( [ 'languages' => $languages ] );
$script  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$options = $script->get_frontend_options();
assert_true( true === ( $options['languages']['seo']['ai_crawlers']['preset_answer_engines'] ?? null ), 'A fresh site must have the answer-engines preset on' );

// --- a stored off value is kept ---
wp_test_reset( [ 'languages' => $languages + [ 'seo' => [ 'ai_crawlers' => [ 'preset_answer_engines' => false, 'bots' => [] ] ] ] ] );
$script  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$options = $script->get_frontend_options();
assert_true( false === ( $options['languages']['seo']['ai_crawlers']['preset_answer_engines'] ?? null ), 'A stored off preset must stay off' );

// --- the network field offers the same default ---
$field = null;
foreach ( NetworkScope::fields() as $group ) {
	foreach ( $group as $candidate ) {
		if ( is_array( $candidate ) && 'languages.seo.ai_crawlers.preset_answer_engines' === ( $candidate['key'] ?? null ) ) {
			$field = $candidate;
		}
	}
}
assert_true( is_array( $field ) && true === $field['default'], 'The network field for the preset must default to on' );

if ( $failures ) {
	fwrite( STDERR, "AnswerEnginesDefaultTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}
echo "AnswerEnginesDefaultTest: all assertions passed\n";
