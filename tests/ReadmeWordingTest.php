<?php

declare(strict_types=1);

// What the public readme must and must not say for 1.0.

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

if ( isset( $options['release'] ) ) {
	$sections = explode( '== Changelog ==', $readme );
	$changelog = $sections[1] ?? '';
	preg_match_all( '/^=\s*([^=\r\n]+?)\s*=$/m', $changelog, $entries );
	assert_true( 2 === count( $sections ), 'the readme must contain one changelog' );
	assert_true( [ (string) $options['release'] ] === ( $entries[1] ?? [] ), 'the changelog must contain only the requested release' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "ReadmeWordingTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ReadmeWordingTest: all assertions passed\n";
