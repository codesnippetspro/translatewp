<?php

declare(strict_types=1);

// The fixed wording of an email, with the values of one order taken out. Two
// orders give the same template, so its translation is made once.

use TranslateWP\Components\EmailTemplate;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$ana = [ 'Ana', 'Lopez', 'Ana Lopez', '1 High Street', 'London', 'SW1A 1AA', 'ana@example.com' ];
$bob = [ 'Bob', 'Marsh', 'Bob Marsh', '22 Mill Lane', 'Leeds', 'LS1 4AP', 'bob@example.com' ];

// --- two orders, one template ---
$first  = EmailTemplate::mask( "Just to let you know — we've received your order #4987, and it is now being processed:", $ana );
$second = EmailTemplate::mask( "Just to let you know — we've received your order #5120, and it is now being processed:", $bob );
assert_true( $first['template'] === $second['template'], 'the order number is a value, got ' . $first['template'] );
assert_true( "Just to let you know — we've received your order #{{1}}, and it is now being processed:" === $first['template'] && [ '4987' ] === $first['values'], 'the value is taken out in place, got ' . wp_json_encode( $first ) );

assert_true( EmailTemplate::mask( 'Hi Ana,', $ana )['template'] === EmailTemplate::mask( 'Hi Bob,', $bob )['template'], 'the customer name is a value' );
assert_true( 'Hi {{1}},' === EmailTemplate::mask( 'Hi Ana,', $ana )['template'], 'a name is taken out as a whole word, got ' . EmailTemplate::mask( 'Hi Ana,', $ana )['template'] );
assert_true( 'Banana bread for {{1}}' === EmailTemplate::mask( 'Banana bread for Ana', $ana )['template'], 'a value inside another word is left alone, got ' . EmailTemplate::mask( 'Banana bread for Ana', $ana )['template'] );

// --- the longest value wins, and each value keeps its place ---
$masked = EmailTemplate::mask( 'Ana Lopez ordered on October 6, 2026 at 14:05', $ana );
assert_true( '{{1}} ordered on October {{2}}, {{3}} at {{4}}' === $masked['template'] && [ 'Ana Lopez', '6', '2026', '14:05' ] === $masked['values'], 'full name, day, year and time are values; the month is wording, got ' . wp_json_encode( $masked ) );

// --- amounts, addresses and links are values ---
assert_true( '£ {{1}}' === EmailTemplate::mask( '£ 4.50', [] )['template'], 'an amount is a value, got ' . EmailTemplate::mask( '£ 4.50', [] )['template'] );
assert_true( 'Reset it here: {{1}}' === EmailTemplate::mask( 'Reset it here: https://example.com/my-account/lost-password/?key=abc&id=4', [] )['template'], 'a link is one value' );
assert_true( 'Write to {{1}}' === EmailTemplate::mask( 'Write to help@example.com', [] )['template'], 'an email address is one value' );

// --- a text that is only values is not wording ---
foreach ( [ 'Ana Lopez', '1 High Street', 'SW1A 1AA', '£ 4.50', '4987', 'ana@example.com', '( )' ] as $text ) {
	assert_true( ! EmailTemplate::has_wording( EmailTemplate::mask( $text, $ana )['template'] ), "\"{$text}\" has no wording to translate" );
}
assert_true( EmailTemplate::has_wording( EmailTemplate::mask( 'Subtotal:', $ana )['template'] ) && EmailTemplate::has_wording( EmailTemplate::mask( 'Hi Ana,', $ana )['template'] ), 'fixed wording is wording' );

// --- the values go back into the translation, in whatever order it puts them ---
assert_true( 'Hallo Ana,' === EmailTemplate::fill( 'Hallo {{1}},', [ 'Ana' ] ), 'a value goes back in its place' );
assert_true( '2026, 6. Oktober' === EmailTemplate::fill( '{{2}}, {{1}}. Oktober', [ '6', '2026' ] ), 'a translation may reorder the values' );
assert_true( null === EmailTemplate::fill( 'Hallo,', [ 'Ana' ] ), 'a translation that lost a value is not used' );
assert_true( null === EmailTemplate::fill( 'Hallo {{1}} {{2}},', [ 'Ana' ] ), 'a translation that invented a value is not used' );
assert_true( 'Zwischensumme:' === EmailTemplate::fill( 'Zwischensumme:', [] ), 'wording with no values comes back as it is' );

// --- a text that already looks like a value token is left out of translation ---
assert_true( ! EmailTemplate::has_wording( EmailTemplate::mask( '{{1}}', [] )['template'] ) || '{{1}}' !== EmailTemplate::mask( 'Use {{1}} here', [] )['template'], 'a source text holding a token must not be confused with a value' );

if ( $failures > 0 ) {
	fwrite( STDERR, "EmailTemplateTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "EmailTemplateTest: all assertions passed\n";
