<?php

declare(strict_types=1);

// Text the server translates itself, outside any page: each text is
// fingerprinted the way the service caches it, looked up, and anything the
// cache does not hold is sent in one translation job.

use TranslateWP\Components\TextTranslation;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxySettings;

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

function seed_site(): void {
	wp_test_reset(
		[
			'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
			'languages' => [
				'list' => [
					'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
					'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				],
			],
		]
	);
}

function hash_of( string $text ): string {
	return TranslationFingerprint::compute_hash(
		TextTranslation::MARKER . $text,
		TranslationProxySettings::default_locale(),
		'de',
		TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW,
		TranslationProxySettings::ai_settings( 'de' )
	);
}

// --- every text the cache holds comes back translated, without its marker ---
seed_site();
$asked  = [];
$lookup = static function ( string $locale, array $hashes ) use ( &$asked ) {
	$asked[] = [ $locale, $hashes ];
	return [
		hash_of( 'Thank you for your order' ) => TextTranslation::MARKER . 'Danke für Ihre Bestellung',
		hash_of( 'Subtotal:' )                => TextTranslation::MARKER . 'Zwischensumme:',
	];
};
$result = TextTranslation::lookup( [ 'Thank you for your order', 'Subtotal:', 'Thank you for your order', '  ' ], 'de', $lookup );
assert_true( [ 'Thank you for your order' => 'Danke für Ihre Bestellung', 'Subtotal:' => 'Zwischensumme:' ] === $result['map'], 'cached texts must come back translated, got ' . wp_json_encode( $result ) );
assert_true( [] === $result['missing'], 'nothing is missing when the cache holds every text' );
assert_true( 1 === count( $asked ) && 'de' === $asked[0][0] && 2 === count( $asked[0][1] ), 'each distinct text is looked up once' );

// --- a text the cache does not hold, or holds empty, is reported missing ---
seed_site();
$result = TextTranslation::lookup(
	[ 'Thank you for your order', 'Hi Ana,', 'Subtotal:' ],
	'de',
	static fn( string $locale, array $hashes ) => [ hash_of( 'Thank you for your order' ) => TextTranslation::MARKER . 'Danke', hash_of( 'Subtotal:' ) => TextTranslation::MARKER . ' ' ]
);
assert_true( [ 'Thank you for your order' => 'Danke' ] === $result['map'], 'only translated texts are in the map' );
assert_true( [ 'Hi Ana,', 'Subtotal:' ] === $result['missing'], 'untranslated texts must be reported missing, got ' . wp_json_encode( $result['missing'] ) );

// --- a failed lookup is an error, not an empty answer ---
seed_site();
$result = TextTranslation::lookup( [ 'Hi Ana,' ], 'de', static fn() => new WP_Error( 'http_request_failed', 'down' ) );
assert_true( is_wp_error( $result ), 'a failed lookup must be reported as an error' );

// --- more than a hundred texts are looked up in batches of a hundred ---
seed_site();
$sizes = [];
TextTranslation::lookup( array_map( static fn( int $n ) => 'Line ' . $n, range( 1, 130 ) ), 'de', static function ( string $locale, array $hashes ) use ( &$sizes ) {
	$sizes[] = count( $hashes );
	return [];
} );
assert_true( [ 100, 30 ] === $sizes, 'lookups go in batches of at most a hundred, got ' . wp_json_encode( $sizes ) );

// --- missing texts are sent as one job with one closed batch, fingerprinted for the language ---
seed_site();
$posts = [];
$post  = static function ( string $path, array $body ) use ( &$posts ) {
	$posts[] = [ $path, $body ];
	return '/v1/translation/jobs' === $path ? [ 'jobId' => 'job-1' ] : [ 'ok' => true ];
};
$sent = TextTranslation::request( [ 'Hi Ana,', 'Subtotal:', 'Hi Ana,' ], 'de', $post );
assert_true( true === $sent, 'a sent job must report success' );
assert_true( 2 === count( $posts ) && '/v1/translation/jobs' === $posts[0][0] && '/v1/translation/jobs/job-1/batches' === $posts[1][0], 'one job, then one batch, got ' . wp_json_encode( array_column( $posts, 0 ) ) );
assert_true( 'de' === $posts[0][1]['targetLang'] && TranslationProxySettings::default_locale() === $posts[0][1]['sourceLang'], 'the job names the source and target languages' );
$batch = $posts[1][1];
assert_true( true === $batch['closeInput'] && 2 === count( $batch['items'] ), 'the batch is closed and carries each distinct text once' );
assert_true( TextTranslation::MARKER . 'Hi Ana,' === $batch['items'][0]['markerPayload'] && hash_of( 'Hi Ana,' ) === $batch['items'][0]['cache']['hash'], 'each item carries its marker payload and its fingerprint' );
assert_true( ! isset( $batch['origin'] ), 'the batch is not marked as warm-up work: someone is waiting for it' );

// --- a refused job is an error and no batch is sent ---
seed_site();
$posts = [];
$sent  = TextTranslation::request( [ 'Hi Ana,' ], 'de', static function ( string $path ) use ( &$posts ) {
	$posts[] = $path;
	return new WP_Error( 'limit', 'refused' );
} );
assert_true( is_wp_error( $sent ) && [ '/v1/translation/jobs' ] === $posts, 'a refused job must stop before the batch' );

// --- nothing to send is a success with no request ---
seed_site();
$posts = [];
assert_true( true === TextTranslation::request( [], 'de', static function ( string $path ) use ( &$posts ) { $posts[] = $path; return []; } ) && [] === $posts, 'no texts means no job' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TextTranslationTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TextTranslationTest: all assertions passed\n";
