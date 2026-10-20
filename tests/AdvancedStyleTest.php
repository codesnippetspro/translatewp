<?php

declare(strict_types=1);

// Audience, length and cultural adaptation are Pro style controls: they ride
// with the AI settings only when Pro unlocks, and only when set to something
// other than their defaults.

use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationAiSettings;

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

function ai_settings( bool $pro, array $style ): array {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );
	Options::update( 'translations.style', $style );
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $pro ? 'pro' : 'free', 'entitled' => $pro ], 3600 );
	wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => $pro );

	return TranslationAiSettings::build();
}

$set = [ 'audience' => '  Small business owners  ', 'length' => 'shorter', 'cultural_adaptation' => 'adapt' ];

$pro = ai_settings( true, $set );
assert_true( 'Small business owners' === ( $pro['audience'] ?? null ), 'With Pro the audience goes out trimmed' );
assert_true( 'shorter' === ( $pro['length'] ?? null ), 'With Pro the length goes out' );
assert_true( 'adapt' === ( $pro['cultural_adaptation'] ?? null ), 'With Pro the cultural adaptation goes out' );

$free = ai_settings( false, $set );
assert_true( ! array_key_exists( 'audience', $free ) && ! array_key_exists( 'length', $free ) && ! array_key_exists( 'cultural_adaptation', $free ), 'Without Pro none of the three is sent' );

$defaults = ai_settings( true, [ 'audience' => '', 'length' => 'match', 'cultural_adaptation' => 'keep' ] );
assert_true( ! array_key_exists( 'audience', $defaults ) && ! array_key_exists( 'length', $defaults ) && ! array_key_exists( 'cultural_adaptation', $defaults ), 'Defaults are not sent, so the fingerprint of an untouched site does not change' );

$bad = ai_settings( true, [ 'audience' => str_repeat( 'x', 300 ), 'length' => 'huge', 'cultural_adaptation' => 'maybe' ] );
assert_true( 200 === strlen( $bad['audience'] ?? '' ), 'The audience is cut at 200 characters' );
assert_true( ! array_key_exists( 'length', $bad ) && ! array_key_exists( 'cultural_adaptation', $bad ), 'An unknown choice is dropped' );

// Neutral is a tone every site may use; a saved Formal keeps going out as it was.
assert_true( 'neutral' === ( ai_settings( false, [ 'tone' => 'neutral' ] )['tone'] ?? null ), 'Without Pro the Neutral tone goes out' );
assert_true( 'formal' === ( ai_settings( false, [ 'tone' => 'formal' ] )['tone'] ?? null ), 'A saved Formal tone keeps going out, so its translations are not redone' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AdvancedStyleTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "AdvancedStyleTest: all assertions passed\n";
