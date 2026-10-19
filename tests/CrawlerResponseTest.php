<?php

declare(strict_types=1);

// A crawler asking for a translated address gets the complete translation,
// or the original page with a canonical to the source and a Retry-After:
// never a partly translated page and never the browser runtime.

use TranslateWP\Components\CrawlerDetection;
use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Functionality\CrawlerResponse;

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

const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const FIREFOX   = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';

// --- Which requests come from a crawler ---
foreach ( [ GOOGLEBOT, 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'GPTBot/1.2', 'ClaudeBot/1.0', 'facebookexternalhit/1.1', 'DuckDuckBot/1.1', 'SomeNewCrawler/3.0 (+https://example.org/spider)' ] as $agent ) {
	assert_true( CrawlerDetection::is_crawler( $agent ), "A crawler must be recognised: {$agent}" );
}
foreach ( [ FIREFOX, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', '' ] as $agent ) {
	assert_true( ! CrawlerDetection::is_crawler( $agent ), "A browser must not be taken for a crawler: '{$agent}'" );
}

function seed_crawler_site( string $agent, string $locale ): CrawlerResponse {
	wp_test_reset(
		[
			'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
			'setup'     => [ 'ended' => true ],
			'languages' => [
				'list' => [
					'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
					'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				],
			],
		]
	);
	$page_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Contact', 'post_status' => 'publish' ] );
	$GLOBALS['wp_test_permalinks'][ $page_id ]                   = 'https://example.com/contact/';
	$GLOBALS['wp_test_url_to_postid']['https://example.com/contact/'] = $page_id;
	$GLOBALS['wp_test_queried_object_id']                         = $page_id;
	$GLOBALS['wp_test_is_singular']                               = true;
	$_SERVER['HTTP_USER_AGENT']                                   = $agent;
	$_SERVER['REQUEST_METHOD']                                    = 'GET';
	LanguageUrl::set_requested_locale( $locale );
	CrawlerResponse::reset();
	// The store keeps what it read for the life of a request; each case is a new request.
	TranslationStore::flush_read_cache();

	return new CrawlerResponse( 'translatewp', TRANSLATEWP_VERSION );
}

function store_translation( array $pairs, array $pending = [] ): void {
	$segments = [];
	$answers  = [];
	$index    = 0;
	foreach ( $pairs as $source => $translated ) {
		$hash              = 'h' . ( ++$index );
		$segments[]        = [ 'hash' => $hash, 'markerPayload' => '⟪item_0001⟫' . $source ];
		$answers[ $hash ]  = '⟪item_0001⟫' . $translated;
	}
	foreach ( $pending as $source ) {
		$segments[] = [ 'hash' => 'p' . ( ++$index ), 'markerPayload' => '⟪item_0001⟫' . $source ];
	}
	TranslationStore::record_pending( 'https://example.com/contact/', 0, 'de', 'en', $segments );
	TranslationStore::resolve_pending(
		'https://example.com/contact/',
		0,
		'de',
		static fn( string $locale, array $hashes ): array => array_intersect_key( $answers, array_flip( $hashes ) )
	);
}

const PAGE = '<html lang="de"><head><title>Contact</title><link rel="canonical" href="https://example.com/de/contact/" /></head>'
	. '<body><h1>Contact</h1><p>Call us today.</p></body></html>';

// --- A complete translation is served whole ---
$response = seed_crawler_site( GOOGLEBOT, 'de' );
store_translation( [ 'Contact' => 'Kontakt', 'Call us today.' => 'Rufen Sie uns heute an.' ] );
assert_true( $response->applies(), 'A crawler on a translated address gets the crawler response' );
$html = $response->render( PAGE );
assert_true( false !== strpos( $html, '<h1>Kontakt</h1><p>Rufen Sie uns heute an.</p>' ), 'A complete translation is served, got ' . $html );
assert_true( false !== strpos( $html, 'rel="canonical" href="https://example.com/de/contact/"' ), 'A translated page keeps the canonical of its own address' );
assert_true( ! isset( CrawlerResponse::sent_headers()['Retry-After'] ), 'A complete translation sends no Retry-After' );

// --- Alt text stays as written on a site without Pro; the other attributes translate ---
$response = seed_crawler_site( GOOGLEBOT, 'de' );
store_translation( [ 'Contact' => 'Kontakt', 'Call us today.' => 'Rufen Sie uns heute an.' ] );
$html = $response->render( str_replace( '<h1>Contact</h1>', '<img src="a.png" alt="Contact" title="Contact"><h1>Contact</h1>', PAGE ) );
assert_true( false !== strpos( $html, 'alt="Contact"' ), 'A Terrestrial crawler page keeps its alt text in the source language, got ' . $html );
assert_true( false !== strpos( $html, 'title="Kontakt"' ), 'The other reader-facing attributes still translate on a Terrestrial page' );

$response = seed_crawler_site( GOOGLEBOT, 'de' );
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => true );
store_translation( [ 'Contact' => 'Kontakt', 'Call us today.' => 'Rufen Sie uns heute an.' ] );
$html = $response->render( str_replace( '<h1>Contact</h1>', '<img src="a.png" alt="Contact"><h1>Contact</h1>', PAGE ) );
assert_true( false !== strpos( $html, 'alt="Kontakt"' ), 'An entitled Pro page translates its alt text, got ' . $html );
wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => false );

// --- One text without a translation: the original page, a canonical to the source, a Retry-After ---
$response = seed_crawler_site( GOOGLEBOT, 'de' );
store_translation( [ 'Contact' => 'Kontakt' ] );
$html = $response->render( PAGE );
assert_true( false !== strpos( $html, '<h1>Contact</h1><p>Call us today.</p>' ), 'An incomplete translation serves the original text, never part of it, got ' . $html );
assert_true( false === strpos( $html, 'Kontakt' ), 'No translated text appears in the original page' );
assert_true( false !== strpos( $html, '<link rel="canonical" href="https://example.com/contact/" />' ) && 1 === substr_count( $html, 'rel="canonical"' ), 'The original page has one canonical, to the source address, got ' . $html );
assert_true( false !== strpos( $html, '<html lang="en">' ), 'The original page states the language it is written in' );
assert_true( (string) CrawlerResponse::RETRY_AFTER_SECONDS === ( CrawlerResponse::sent_headers()['Retry-After'] ?? '' ), 'The original page sends Retry-After' );

// --- Segments still pending: the same original page ---
$response = seed_crawler_site( GOOGLEBOT, 'de' );
store_translation( [ 'Contact' => 'Kontakt', 'Call us today.' => 'Rufen Sie uns heute an.' ], [ 'A footer line still translating' ] );
$html = $response->render( PAGE );
assert_true( false !== strpos( $html, '<h1>Contact</h1>' ) && isset( CrawlerResponse::sent_headers()['Retry-After'] ), 'A page with segments still pending is served as the original with Retry-After' );

// --- Nothing stored at all: the original page ---
$response = seed_crawler_site( GOOGLEBOT, 'de' );
$html     = $response->render( PAGE );
assert_true( false !== strpos( $html, '<h1>Contact</h1>' ) && isset( CrawlerResponse::sent_headers()['Retry-After'] ), 'A page with no stored translation is served as the original with Retry-After' );

// --- The browser runtime is not sent to a crawler on a translated address ---
$response = seed_crawler_site( GOOGLEBOT, 'de' );
assert_true( false === ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->conditions(), 'A crawler on a translated address is sent no browser runtime' );

// --- A crawler on a plain address, and a person on a translated one, are not touched ---
$response = seed_crawler_site( GOOGLEBOT, '' );
assert_true( ! $response->applies(), 'A crawler on a plain address gets the normal page' );
assert_true( true === ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->conditions(), 'A crawler on a plain address still gets the runtime' );

$response = seed_crawler_site( FIREFOX, 'de' );
assert_true( ! $response->applies(), 'A person on a translated address keeps the browser-delivered translation' );
assert_true( true === ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->conditions(), 'A person on a translated address gets the runtime' );

LanguageUrl::set_requested_locale( '' );
unset( $_SERVER['HTTP_USER_AGENT'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "CrawlerResponseTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "CrawlerResponseTest: all assertions passed\n";
