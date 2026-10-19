<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationWhitespace;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$parity_cases = [
	[ '   ', '', false ],
	[ "\xc2\xa0", '', false ],
	[ "\xc2\xa0\xc2\xa0", '', false ],
	[ ' Hello ', 'Hello', true ],
	[ "\xc2\xa0Hello", 'Hello', true ],
	[ "Hello\nworld", "Hello\nworld", true ],
];

foreach ( $parity_cases as $case ) {
	[ $input, $expected_trim, $expected_translatable ] = $case;
	assert_true(
		$expected_trim === TranslationWhitespace::trim( $input ),
		'Trim mismatch for parity case'
	);
	assert_true(
		$expected_translatable === TranslationWhitespace::has_translatable_text( $input ),
		'Translatable mismatch for parity case'
	);
}

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationWhitespaceParityTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "TranslationWhitespaceParityTest ok\n" );
