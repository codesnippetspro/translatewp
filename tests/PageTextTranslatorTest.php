<?php

declare(strict_types=1);

// Translating the text of a rendered page from a source -> translation map,
// and saying whether every piece of text was covered.

use TranslateWP\Components\PageTextTranslator;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_same( $expected, $actual, string $message ): void {
	global $failures;
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n  expected: " . var_export( $expected, true ) . "\n  got:      " . var_export( $actual, true ) . "\n" );
		$failures++;
	}
}

$map = [
	'Contact'               => 'Kontakt',
	'Call us on'            => 'Rufen Sie uns an unter',
	'or write to us.'       => 'oder schreiben Sie uns.',
	'Opening hours & more'  => 'Öffnungszeiten & mehr',
	'Read more'             => 'Weiterlesen',
];

// --- Every text is replaced, markup and spacing are kept ---
$result = PageTextTranslator::translate( "<h1 class=\"t\">Contact</h1>\n<p>  Call us on <b>+44 20 7946 0000</b> or write to us.</p>", $map );
assert_same( "<h1 class=\"t\">Kontakt</h1>\n<p>  Rufen Sie uns an unter <b>+44 20 7946 0000</b> oder schreiben Sie uns.</p>", $result['html'], 'Text is replaced in place, with its markup and surrounding spaces' );
assert_same( 0, $result['missing'], 'A fully covered page misses nothing' );
assert_same( 3, $result['translated'], 'Three texts were translated' );

// --- Text with no letters needs no translation ---
$result = PageTextTranslator::translate( '<p>Contact</p><span>2026</span><i> – </i><b>+44 20</b>', $map );
assert_same( 0, $result['missing'], 'Numbers and punctuation are not missing translations' );

$result = PageTextTranslator::translate( '<p>Contact</p><a href="mailto:hello@example.com">hello@example.com</a><span>https://example.com/shop</span><i>www.example.com</i>', $map );
assert_same( 0, $result['missing'], 'An email address or a web address needs no translation and is not missing' );
assert_same( 1, $result['translated'], 'Only the word was translated' );

// --- Entities are matched decoded and written back encoded ---
$result = PageTextTranslator::translate( '<h2>Opening hours &amp; more</h2>', $map );
assert_same( '<h2>Öffnungszeiten &amp; mehr</h2>', $result['html'], 'An encoded source text matches its decoded map entry and stays encoded' );

// --- A text matches whatever its inner whitespace ---
$result = PageTextTranslator::translate( "<p>Call   us\n on</p>", $map );
assert_same( '<p>Rufen Sie uns an unter</p>', $result['html'], 'Whitespace differences inside a text do not prevent a match' );

// --- One uncovered text makes the page incomplete ---
$result = PageTextTranslator::translate( '<h1>Contact</h1><p>This sentence has no translation.</p>', $map );
assert_same( 1, $result['missing'], 'A text with no translation is counted as missing' );

// --- Text the browser runtime never translates is left alone and not counted ---
$untouched = '<script>var Contact = "Contact";</script><style>.Contact{}</style><code>Contact</code><noscript>Contact</noscript>'
	. '<div class="x translatewp-no-translate"><p>Not for translation</p></div>'
	. '<p translate="no">Not this one</p><p data-no-translate>Nor this</p><!-- Contact --><textarea>Contact</textarea>';
$result = PageTextTranslator::translate( $untouched . '<p>Read more</p>', $map );
assert_same( $untouched . '<p>Weiterlesen</p>', $result['html'], 'Scripts, styles, code, comments and opted-out elements are untouched' );
assert_same( 0, $result['missing'], 'Skipped text is not missing' );

// --- An opted-out element ends where it closes ---
$result = PageTextTranslator::translate( '<div class="translatewp-no-translate"><div>Read more</div></div><p>Read more</p>', $map );
assert_same( '<div class="translatewp-no-translate"><div>Read more</div></div><p>Weiterlesen</p>', $result['html'], 'Text after an opted-out element is translated again' );

// --- The document title is translated when known and never counted as missing ---
$result = PageTextTranslator::translate( '<title>Contact</title><title>Unknown title</title>', $map );
assert_same( '<title>Kontakt</title><title>Unknown title</title>', $result['html'], 'A known title is translated; an unknown one stays' );
assert_same( 0, $result['missing'], 'A title is never a missing translation' );

// --- The attributes a reader sees or hears are translated when known, and never counted as missing ---
$result = PageTextTranslator::translate(
	'<img src="a.png" alt="Contact" class="Contact"><button aria-label="Read more" title="Unknown tip">Read more</button><input placeholder="Read more" name="Contact">',
	$map
);
assert_same(
	'<img src="a.png" alt="Kontakt" class="Contact"><button aria-label="Weiterlesen" title="Unknown tip">Weiterlesen</button><input placeholder="Weiterlesen" name="Contact">',
	$result['html'],
	'alt, aria-label, title and placeholder are translated when known; other attributes never are'
);
assert_same( 0, $result['missing'], 'An attribute with no translation is not a missing translation' );
$result = PageTextTranslator::translate(
	'<img src="a.png" alt="Contact"><button aria-label="Read more">Read more</button>',
	$map,
	array_values( array_diff( PageTextTranslator::TRANSLATED_ATTRIBUTES, [ 'alt' ] ) )
);
assert_same(
	'<img src="a.png" alt="Contact"><button aria-label="Weiterlesen">Weiterlesen</button>',
	$result['html'],
	'With alt left out of the attribute list, alt text stays as written while the other attributes translate'
);

$result = PageTextTranslator::translate( '<div class="translatewp-no-translate"><img alt="Contact"></div><code><img alt="Contact"></code>', $map );
assert_same( '<div class="translatewp-no-translate"><img alt="Contact"></div><code><img alt="Contact"></code>', $result['html'], 'Attributes inside opted-out or skipped elements are untouched' );

// --- An empty map translates nothing and reports the text as missing ---
$result = PageTextTranslator::translate( '<p>Contact</p>', [] );
assert_same( '<p>Contact</p>', $result['html'], 'With no translations the page is unchanged' );
assert_same( 1, $result['missing'], 'With no translations the text is missing' );

if ( $failures > 0 ) {
	fwrite( STDERR, "PageTextTranslatorTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "PageTextTranslatorTest: all assertions passed\n";
