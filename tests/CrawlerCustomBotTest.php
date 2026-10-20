<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Admin;

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

function seo_update_request( array $data ) {
	return new class( $data ) {
		private array $data;

		public function __construct( array $data ) {
			$this->data = $data;
		}

		public function get_param( string $key ) {
			if ( 'option' === $key ) {
				return 'languages.seo';
			}
			if ( 'data' === $key ) {
				return $this->data;
			}

			return null;
		}
	};
}

$languages = [
	'en-us' => [ 'locale' => 'en-us', 'slug' => 'en-us', 'active' => true, 'default' => true ],
	'es-es' => [ 'locale' => 'es-es', 'slug' => 'es-es', 'active' => true, 'default' => false ],
];

function save_bots( array $bots ): array {
	$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
	$admin->update_data( seo_update_request( [ 'ai_crawlers' => [ 'bots' => $bots ] ] ) );

	return Options::get( 'languages.seo.ai_crawlers.bots', [] );
}

// --- A valid custom crawler joins the same policy, defaulting to allow ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots( [ 'MyCrawler' => [ 'default' => 'allow', 'languages' => [] ] ] );
assert_true( isset( $stored['MyCrawler'] ), 'A valid custom crawler must be stored in the same bots map' );
assert_true( 'allow' === ( $stored['MyCrawler']['default'] ?? null ), 'A custom crawler keeps its decision' );

// --- A custom crawler can be denied ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots( [ 'MyCrawler' => [ 'default' => 'deny', 'languages' => [] ] ] );
assert_true( 'deny' === ( $stored['MyCrawler']['default'] ?? null ), 'A custom crawler can be denied' );

// --- Trim: surrounding whitespace is removed from the stored name ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots( [ '  SpacedBot  ' => [ 'default' => 'allow', 'languages' => [] ] ] );
assert_true( isset( $stored['SpacedBot'] ) && ! isset( $stored['  SpacedBot  '] ), 'A custom name is trimmed before storage' );

// --- Empty and whitespace-only names are refused ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots( [ '' => [ 'default' => 'allow' ], '   ' => [ 'default' => 'allow' ] ] );
assert_true( ! isset( $stored[''] ) && 1 !== count( $stored ), 'Empty or whitespace-only names are refused' );
assert_true( [] === $stored, 'No empty custom crawler is stored' );

// --- Over-long names are refused ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$too_long = str_repeat( 'a', 129 );
$stored   = save_bots( [ $too_long => [ 'default' => 'allow' ] ] );
assert_true( ! isset( $stored[ $too_long ] ) && [] === $stored, 'A User-Agent over 128 characters is refused' );

// --- A 128-character name is accepted at the boundary ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$at_cap = str_repeat( 'b', 128 );
$stored = save_bots( [ $at_cap => [ 'default' => 'allow' ] ] );
assert_true( isset( $stored[ $at_cap ] ), 'A User-Agent of exactly 128 characters is accepted' );

// --- Anything outside an RFC 9309 product token is refused ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots(
	[
		"Evil\nDisallow: /" => [ 'default' => 'allow' ],
		"Tab\tBot"          => [ 'default' => 'allow' ],
		"Carriage\rReturn"  => [ 'default' => 'allow' ],
		'Hash#Bot'          => [ 'default' => 'allow' ],
		':LeadingColon'     => [ 'default' => 'allow' ],
		'*'                 => [ 'default' => 'allow' ],
		'Google*'           => [ 'default' => 'allow' ],
		'bad bot'           => [ 'default' => 'allow' ],
		'a<b'               => [ 'default' => 'allow' ],
		'"x"'               => [ 'default' => 'allow' ],
		'$'                 => [ 'default' => 'allow' ],
		'-leading'          => [ 'default' => 'allow' ],
		'.leading'          => [ 'default' => 'allow' ],
	]
);
assert_true( [] === $stored, 'A wildcard, space, quote, structural or non-alphanumeric-leading name is refused' );

// --- A real product token with a leading digit, dots, hyphens and underscores is accepted ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots(
	[
		'Meta-ExternalAgent' => [ 'default' => 'allow', 'languages' => [] ],
		'cohere-ai'          => [ 'default' => 'deny', 'languages' => [] ],
	]
);
assert_true( isset( $stored['Meta-ExternalAgent'] ), 'Meta-ExternalAgent is a valid product token and is accepted' );
assert_true( isset( $stored['cohere-ai'] ), 'cohere-ai is a valid product token and is accepted' );

// --- Every listed crawler name passes the allowlist ---
foreach ( [ 'GPTBot', 'ClaudeBot', 'Claude-Web', 'PerplexityBot', 'Google-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'Applebot-Extended' ] as $listed ) {
	wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
	$stored = save_bots( [ $listed => [ 'default' => 'allow', 'languages' => [] ] ] );
	assert_true( isset( $stored[ $listed ] ), "Listed crawler {$listed} must pass the allowlist" );
}

// --- A duplicate of a listed crawler, in any case, does not add a second row ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots(
	[
		'GPTBot' => [ 'default' => 'deny', 'languages' => [] ],
		'gptbot' => [ 'default' => 'allow', 'languages' => [] ],
	]
);
assert_true( 1 === count( array_filter( array_keys( $stored ), static fn( $k ): bool => 'gptbot' === strtolower( (string) $k ) ) ), 'A case-variant duplicate of a listed bot is collapsed to one row' );
assert_true( isset( $stored['GPTBot'] ), 'The listed crawler keeps its canonical name' );

// --- A duplicate custom crawler, in any case, does not add a second row ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
$stored = save_bots(
	[
		'DupeBot' => [ 'default' => 'allow', 'languages' => [] ],
		'dupebot' => [ 'default' => 'deny', 'languages' => [] ],
	]
);
assert_true( 1 === count( array_filter( array_keys( $stored ), static fn( $k ): bool => 'dupebot' === strtolower( (string) $k ) ) ), 'A case-variant duplicate custom crawler is collapsed to one row' );

// --- Removal: a save that omits a crawler drops it (not kept by the deep merge) ---
wp_test_reset( [ 'languages' => [ 'list' => $languages ] ] );
save_bots(
	[
		'KeepBot'   => [ 'default' => 'allow', 'languages' => [] ],
		'RemoveBot' => [ 'default' => 'deny', 'languages' => [] ],
	]
);
$stored = save_bots( [ 'KeepBot' => [ 'default' => 'allow', 'languages' => [] ] ] );
assert_true( isset( $stored['KeepBot'] ), 'A crawler that stays is kept' );
assert_true( ! isset( $stored['RemoveBot'] ), 'A crawler left out of the save is removed' );

if ( $failures > 0 ) {
	fwrite( STDERR, "CrawlerCustomBotTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "CrawlerCustomBotTest: all assertions passed\n";
