<?php

declare(strict_types=1);

$options = getopt( '', [ 'root:', 'release:' ] );
$root    = (string) ( $options['root'] ?? dirname( __DIR__ ) );

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$readme = (string) file_get_contents( $root . '/readme.txt' );
// The changelog records what earlier versions said; the rules are for what the readme says now.
$current = (string) substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) );

$fair_use = 'Every plan includes AI translation with a fair-use allowance per 30 days. When your account reaches its allowance, translated pages keep working for visitors and new translation pauses until the window moves on. On Orbital, add your own AI key to keep going.';

assert_true( false !== strpos( $current, $fair_use ), 'the readme must carry the fair-use wording word for word' );
assert_true( false === stripos( $current, 'without artificial limits' ), 'the readme must not say "without artificial limits"' );
assert_true( false === stripos( $current, 'not metered' ), 'the readme must not say usage is not metered: every plan has a fair-use allowance' );
assert_true( false === stripos( $current, 'woocommerce' ), 'the readme must make no WooCommerce claim' );
assert_true( false === stripos( $current, 'hosted' ), 'the readme must not say "hosted"' );
assert_true( false !== strpos( $current, 'https://translatewp.ai/terms' ), 'the service terms link must stay' );
assert_true( false !== strpos( $current, 'https://translatewp.ai/privacy' ), 'the privacy policy link must stay' );
assert_true( false !== strpos( $current, 'api.translatewp.ai' ), 'the disclosure of what is sent to the service must stay' );

$listing_copies = [ $current ];
if ( is_file( $root . '/Readme.md' ) ) {
	$listing_copies[] = explode( '## Changelog', (string) file_get_contents( $root . '/Readme.md' ) )[0];
}
foreach ( $listing_copies as $listing_copy ) {
	assert_true( 0 === preg_match( '/\btranslated\s+(?:(?:url|page)\s+|metadata\s+and\s+)?slugs?\b/i', $listing_copy ), 'the listing must not claim translated page slugs' );
	assert_true( false !== strpos( $listing_copy, 'translated metadata' ), 'the listing must retain the translated metadata feature' );
}

if ( isset( $options['release'] ) ) {
	$sections = explode( '== Changelog ==', $readme );
	$changelog = $sections[1] ?? '';
	preg_match_all( '/^=\s*([^=\r\n]+?)\s*=$/m', $changelog, $entries );
	assert_true( 2 === count( $sections ), 'the readme must contain one changelog' );
	$versions = $entries[1] ?? [];
	assert_true( ( $versions[0] ?? null ) === (string) $options['release'], 'the changelog must start with the requested release' );
	$previous = null;
	foreach ( $versions as $version ) {
		assert_true( 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ) && version_compare( $version, '1.0.0', '>=' ), 'the changelog must contain stable public releases only' );
		if ( null !== $previous ) {
			assert_true( version_compare( $previous, $version, '>' ), 'changelog releases must be unique and newest first' );
		}
		$previous = $version;
	}
}

if ( $failures > 0 ) {
	fwrite( STDERR, "ReadmeWordingTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ReadmeWordingTest: all assertions passed\n";
