<?php

declare(strict_types=1);

use TranslateWP\Functionality\Endpoints\Management;
use TranslateWP\Components\Options;

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
$management = new Management( 'translatewp', TRANSLATEWP_VERSION );

// --- languages advance persists resume_step=translation, not languages ---
$request = new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [
	'step'             => 'languages',
	'resume_step'      => 'translation',
	'default_language' => 'zh-hans',
	'languages'        => [ 'zh-hans', 'en-us' ],
	'context'          => 'Updated shop context',
] );
$management->save_step( $request );
assert_true( 'Updated shop context' === Options::get( 'translations.context.text_context', '' ), 'Warm-up must start with the chosen context, not change its fingerprints at Finish' );
assert_true( 'translation' === Options::get( 'setup.step', '' ), 'Advancing languages must resume at translation, got ' . Options::get( 'setup.step', '' ) );

$languages_list = Options::get( 'languages.list', [] );
assert_true( is_array( $languages_list ) && isset( $languages_list['zh-hans'] ), 'Languages list must persist zh-hans' );
assert_true( 'zh' === $languages_list['zh-hans']['slug'], 'zh-hans slug must be zh, got ' . ( $languages_list['zh-hans']['slug'] ?? 'none' ) );

// --- translation advance persists resume_step=context ---
$request = new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [
	'step'          => 'translation',
	'resume_step'   => 'context',
	'auto_translate' => false,
] );
$management->save_step( $request );
assert_true( 'context' === Options::get( 'setup.step', '' ), 'Advancing translation must resume at context, got ' . Options::get( 'setup.step', '' ) );

$translation_settings = Options::get( 'translations.settings', [] );
assert_true( is_array( $translation_settings ) && false === ( $translation_settings['auto_translate'] ?? true ), 'auto_translate must persist false' );

// --- context advance persists resume_step=connect (final step) ---
$request = new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [
	'step'        => 'context',
	'resume_step' => 'connect',
	'context'     => 'marketing',
] );
$management->save_step( $request );
assert_true( 'connect' === Options::get( 'setup.step', '' ), 'Advancing context must resume at connect, got ' . Options::get( 'setup.step', '' ) );
assert_true( 'marketing' === Options::get( 'translations.context.text_context', '' ), 'context must persist marketing' );

// --- missing resume_step falls back to the completed step ---
$request = new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [
	'step' => 'languages',
] );
$management->save_step( $request );
assert_true( 'languages' === Options::get( 'setup.step', '' ), 'Missing resume_step must fall back to the completed step' );

// --- the style and tone step saves the chosen presets and resumes at languages ---
$request = new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [
	'step'        => 'style-tone',
	'resume_step' => 'languages',
	'style'       => 'technical',
	'tone'        => 'formal',
] );
$management->save_step( $request );
assert_true( 'technical' === Options::get( 'translations.style.type', '' ), 'The style and tone step must save the chosen style' );
assert_true( 'formal' === Options::get( 'translations.style.tone', '' ), 'The style and tone step must save the chosen tone' );
assert_true( 'languages' === Options::get( 'setup.step', '' ), 'Advancing style and tone must resume at languages' );

// --- a value that is no preset changes nothing ---
$request = new WP_REST_Request( 'POST', '/translatewp/v1/management/save-step', [
	'step'  => 'style-tone',
	'style' => 'custom',
	'tone'  => '<b>loud</b>',
] );
$management->save_step( $request );
assert_true( 'technical' === Options::get( 'translations.style.type', '' ), 'A style that is no preset must not be saved' );
assert_true( 'formal' === Options::get( 'translations.style.tone', '' ), 'A tone that is no preset must not be saved' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SaveStepResumeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SaveStepResumeTest: all assertions passed\n";
