<?php

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

use TranslateWP\Components\LexiconMatcher;

$failures = 0;
function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

class DegradedLexiconMatcher extends LexiconMatcher {
	protected static function capable(): bool {
		return false;
	}
}

$decomposed_e = "e\u{0301}";

// [ terms, source, expected, why ]
$rows = [
	[ [ 'WordPress' ], 'We love WORDPRESS sites.', true, 'ASCII matching ignores case' ],
	[ [ 'plugin' ], 'Install our Plugins today.', true, 'a plural ending is allowed on a term of four or more letters' ],
	[ [ 'plugin' ], "The plugin's settings.", true, 'a possessive is outside the word' ],
	[ [ 'plugin' ], 'Das Kundenplugin ist neu.', false, 'prefix compounds are not detected' ],
	[ [ 'plugin' ], 'A plug for the sink.', false, 'a shorter word is not the term' ],
	[ [ 'plugin' ], 'A pluginfestival.', false, 'more than three extra letters is another word' ],
	[ [ 'AI' ], 'Built with AI.', true, 'a short term matches as a whole word' ],
	[ [ 'AI' ], 'AI, data and more', true, 'a short term matches before punctuation' ],
	[ [ 'AI' ], 'Take AIM.', false, 'a short term allows no ending' ],
	[ [ 'AI' ], 'Check your MAIL.', false, 'a short term must start a word' ],
	[ [ 'AI' ], 'Model AI2 is out.', false, 'a short term allows no trailing digit' ],
	[ [ 'Straße' ], 'HAUPTSTRASSE 5 und die STRASSE', true, 'sharp s folds to ss' ],
	[ [ 'Strasse' ], 'Die Straße ist lang.', true, 'ss in the term matches a sharp s in the source' ],
	[ [ 'İstanbul' ], 'Welcome to İSTANBUL.', true, 'a dotted capital I folds the same on both sides' ],
	[ [ 'İstanbul' ], 'Welcome to istanbul.', true, 'a dotted capital I also matches a plain i' ],
	[ [ 'Café' ], 'Our caf' . $decomposed_e . ' opens at nine.', true, 'a composed term matches a decomposed source' ],
	[ [ 'caf' . $decomposed_e ], 'Our CAFÉ opens at nine.', true, 'a decomposed term matches a composed source' ],
	[ [ 'ｐｌｕｇｉｎ' ], 'A plugin.', true, 'full width letters fold to ASCII' ],
	[ [ 'Code Snippets' ], "Get Code\u{00A0}Snippets now.", true, 'an internal space matches a no-break space' ],
	[ [ 'Code Snippets' ], "Get Code\u{202F}Snippets now.", true, 'an internal space matches a narrow no-break space' ],
	[ [ 'Code Snippets' ], "Get Code\n\t  Snippets now.", true, 'an internal space matches a newline and a whitespace run' ],
	[ [ 'Code  Snippets' ], 'Get Code Snippets now.', true, 'a whitespace run in the term counts as one space' ],
	[ [ 'Code Snippets' ], 'Get ⟪item_0001⟫Code ⟪1⟫Snippets⟪2⟫ now.', true, 'marker tokens are deleted before matching' ],
	[ [ 'Code Snippets' ], 'Get CodeSnippets now.', false, 'a missing space is another word' ],
	[ [ 'Fern & Finch' ], 'Visit Fern &amp; Finch.', true, 'an entity in the source decodes' ],
	[ [ 'Fern &amp; Finch' ], 'Visit Fern & Finch.', true, 'an entity in the term decodes' ],
	[ [ 'C++' ], 'Written in C++11.', true, 'a punctuation edge has no boundary check' ],
	[ [ 'C++' ], 'Written in ObjC++.', false, 'a letter edge still needs a word start' ],
	[ [ '.NET' ], 'Built on ASP.NET today.', true, 'a leading dot has no boundary check' ],
	[ [ '.NET' ], 'Built on .Network.', false, 'a short letter end allows no ending' ],
	[ [ '翻訳' ], 'このプラグインは翻訳を自動化します', true, 'a Han term matches inside a sentence without spaces' ],
	[ [ '翻訳' ], 'このプラグインは自動化します', false, 'a Han term that is absent does not match' ],
	[ [ 'ภาษา' ], 'เราแปลภาษาไทยได้', true, 'a Thai term matches inside a sentence without spaces' ],
	[ [ 'e-mail' ], 'Send an E-Mail now.', true, 'a hyphenated term matches literally' ],
	[ [ 'e-mail' ], 'Send an email now.', false, 'a hyphen is literal, not optional' ],
	[ [ 'e-mail' ], 'Send an e mail now.', false, 'a hyphen does not match a space' ],
	[ [ 'price (net)' ], 'The PRICE (NET) is shown.', true, 'parentheses in a term are literal' ],
	[ [ 'price (net)' ], 'The price net is shown.', false, 'parentheses are not a regex group' ],
	[ [ 'a.b*c' ], 'Run a.b*c here.', true, 'dot and star in a term are literal' ],
	[ [ 'a.b*c' ], 'Run axbbbc here.', false, 'dot and star are not regex operators' ],
	[ [ 'x/y#z' ], 'Path x/y#z', true, 'delimiter characters in a term are literal' ],
	[ [], 'Anything at all.', false, 'an empty term list matches nothing' ],
	[ [ '', '   ', "\u{00A0}" ], 'Anything at all.', false, 'blank terms match nothing' ],
	[ [ 'plugin' ], '', false, 'an empty source matches nothing' ],
	[ [ 'plugin' ], "  \n\t ", false, 'a blank source matches nothing' ],
	[ [ 'alpha', 'beta', 'gamma' ], 'Only GAMMA rays here.', true, 'the last of several terms matches' ],
	[ [ 'alpha', 'beta', 'gamma' ], 'Only delta rays here.', false, 'none of several terms matches' ],
	[ [ 'Kunde' ], 'Unsere Kunden sind zufrieden.', true, 'a case ending is allowed' ],
];

foreach ( $rows as $index => [ $terms, $source, $expected, $why ] ) {
	$actual = LexiconMatcher::matches( LexiconMatcher::compile( $terms ), $source );
	assert_true( $expected === $actual, "row {$index}: {$why} (expected " . var_export( $expected, true ) . ')' );
}

assert_true( null === LexiconMatcher::compile( [] ), 'an empty list compiles to nothing' );
assert_true( ! LexiconMatcher::degraded(), 'the test runtime has mbstring and intl' );

// A long term against a large source returns quickly.
$long_term = str_repeat( 'a', 199 ) . 'b';
$compiled  = LexiconMatcher::compile( [ $long_term, str_repeat( 'ab ', 66 ) . 'x', 'plugin' ] );
$source    = str_repeat( 'a', 100000 ) . 'c b ' . str_repeat( 'ab ', 33000 );
$started   = microtime( true );
$found     = LexiconMatcher::matches( $compiled, $source );
$elapsed   = ( microtime( true ) - $started ) * 1000;
assert_true( false === $found, 'the large source holds none of the terms' );
assert_true( $elapsed < 50, sprintf( 'a 200 character term over a %d KB source takes %.1f ms, over 50 ms', (int) ( strlen( $source ) / 1024 ), $elapsed ) );

// Without mbstring or intl, a term with non-ASCII characters matches every source.
assert_true( DegradedLexiconMatcher::degraded(), 'the degraded seam reports degraded' );
$degraded = DegradedLexiconMatcher::compile( [ 'plugin', 'Straße' ] );
assert_true( DegradedLexiconMatcher::matches( $degraded, 'Nothing related here.' ), 'a non-ASCII term matches every source when degraded' );
assert_true( ! DegradedLexiconMatcher::matches( $degraded, '' ), 'a blank source still matches nothing when degraded' );
$ascii = DegradedLexiconMatcher::compile( [ 'plugin', "\u{00A0}" ] );
assert_true( DegradedLexiconMatcher::matches( $ascii, 'Our Plugins.' ), 'an ASCII term still matches when degraded' );
assert_true( ! DegradedLexiconMatcher::matches( $ascii, 'Nothing related here.' ), 'an ASCII term still misses when degraded' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LexiconMatcherTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LexiconMatcherTest: all assertions passed\n";
