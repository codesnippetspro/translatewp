<?php

declare(strict_types=1);

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

const TAGLINE = 'Translate WordPress with AI, guide its wording with your Lexicon, and add a language switcher. Translations follow changes to your content.';

$root   = dirname( __DIR__ );
$plugin = (string) file_get_contents( $root . '/translatewp.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );

preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', $plugin, $header );
assert_true( TAGLINE === trim( $header[1] ?? '' ), 'the plugin header description is the tagline, got ' . ( $header[1] ?? 'none' ) );

$lines = preg_split( '/\R/', $readme );
assert_true( TAGLINE === trim( $lines[11] ?? '' ), 'the readme short description is the tagline, got ' . ( $lines[11] ?? 'none' ) );
assert_true( strlen( TAGLINE ) <= 150, 'the short description fits the plugin directory limit' );

foreach ( [ 'No pre-translation', 'nothing is translated in advance', 'No pre-translation runs', 'no background sync jobs', 'nothing to pre-build', 'rather than stored as pre-translated copies', 'Real-time AI translation for WordPress' ] as $claim ) {
	assert_true( false === stripos( $readme, $claim ), 'the readme no longer claims: ' . $claim );
}
assert_true( false !== stripos( $readme, 'ahead' ), 'the readme says pages can be prepared ahead' );
assert_true( false === stripos( $readme, 'hosted' ), 'the readme never says hosted' );

if ( $failures > 0 ) {
	fwrite( STDERR, "TaglineTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TaglineTest: all assertions passed\n";
