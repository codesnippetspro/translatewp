<?php

declare(strict_types=1);

/**
 * WordPress.org reads the minimum PHP and WordPress versions from the plugin
 * header and the readme; Composer enforces its own PHP constraint in the
 * shipped autoloader's platform check. All of them must state the same minimum.
 */

$root  = dirname( __DIR__ );
$fail  = 0;
$check = static function ( bool $ok, string $message ) use ( &$fail ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "PlatformRequirementsTest: {$message}\n" );
		$fail++;
	}
};

$header = (string) file_get_contents( $root . '/translatewp.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$read   = static function ( string $text, string $field ): string {
	return 1 === preg_match( '/^\s*\*?\s*' . preg_quote( $field, '/' ) . ':\s*([0-9.]+)\s*$/mi', $text, $match ) ? $match[1] : '';
};

$php_header = $read( $header, 'Requires PHP' );
$php_readme = $read( $readme, 'Requires PHP' );
$wp_header  = $read( $header, 'Requires at least' );
$wp_readme  = $read( $readme, 'Requires at least' );

$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$lock     = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true );

$check( '' !== $php_header && $php_header === $php_readme, "the plugin header ({$php_header}) and the readme ({$php_readme}) must require the same PHP" );
$check( '' !== $wp_header && $wp_header === $wp_readme, "the plugin header ({$wp_header}) and the readme ({$wp_readme}) must require the same WordPress" );
$check( '>=' . $php_header === ( $composer['require']['php'] ?? '' ), 'composer.json must require php >=' . $php_header . ', got ' . wp_json_encode_safe( $composer['require']['php'] ?? null ) );
$check( '>=' . $php_header === ( $lock['platform']['php'] ?? '' ), 'composer.lock must record php >=' . $php_header . ', got ' . wp_json_encode_safe( $lock['platform']['php'] ?? null ) );
$check( ( $lock['content-hash'] ?? '' ) === composer_content_hash( $composer ), 'composer.lock is out of date with composer.json; run composer update --lock' );

function wp_json_encode_safe( $value ): string {
	return (string) json_encode( $value );
}

/** Composer's own content hash of the keys that decide the lock file. */
function composer_content_hash( array $composer ): string {
	$relevant = array_intersect_key(
		$composer,
		array_flip( [ 'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra' ] )
	);
	if ( isset( $composer['config']['platform'] ) ) {
		$relevant['config']['platform'] = $composer['config']['platform'];
	}
	ksort( $relevant );

	return md5( (string) json_encode( $relevant ) );
}

if ( $fail > 0 ) {
	exit( 1 );
}

echo "PlatformRequirementsTest: all assertions passed\n";
