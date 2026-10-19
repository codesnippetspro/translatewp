<?php

declare(strict_types=1);

// VersionConsistencyTest reads full versions, a beta included, against
// candidate roots built here; a stable release still refuses a beta.

$failures = 0;

function prerelease_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		++$failures;
	}
}

/**
 * A candidate root with the four version strings, and the exit code of the check run against it.
 *
 * @param array<string,string> $versions header, constant, package, stable.
 * @param string[]             $args     Extra arguments for the check.
 */
function run_version_check( array $versions, array $args = [] ): int {
	$root = sys_get_temp_dir() . '/twp-version-' . bin2hex( random_bytes( 6 ) );
	mkdir( $root );
	file_put_contents( $root . '/translatewp.php', "<?php\n/**\n * Plugin Name: TranslateWP\n * Version:           {$versions['header']}\n */\ndefine( 'TRANSLATEWP_VERSION', '{$versions['constant']}' );\n" );
	file_put_contents( $root . '/package.json', json_encode( [ 'version' => $versions['package'] ] ) );
	file_put_contents( $root . '/readme.txt', "=== TranslateWP ===\nStable tag: {$versions['stable']}\n" );
	$command = array_merge( [ PHP_BINARY, __DIR__ . '/VersionConsistencyTest.php', '--root', $root ], $args );
	exec( implode( ' ', array_map( 'escapeshellarg', $command ) ) . ' 2>/dev/null', $output, $code );
	foreach ( [ 'translatewp.php', 'package.json', 'readme.txt' ] as $file ) {
		unlink( $root . '/' . $file );
	}
	rmdir( $root );

	return $code;
}

$all = static fn( string $v ): array => [ 'header' => $v, 'constant' => $v, 'package' => $v, 'stable' => $v ];

prerelease_check( 0 === run_version_check( $all( '1.0.1-beta.1' ) ), 'a beta with all four strings equal passes' );
prerelease_check( 0 === run_version_check( $all( '1.0.1-beta.1' ), [ '--expected', '1.0.1-beta.1' ] ), 'a beta matching the requested beta passes' );
prerelease_check( 1 === run_version_check( $all( '1.0.1-beta.1' ), [ '--expected', '1.0.0' ] ), 'a beta never passes for the stable 1.0.0' );
prerelease_check( 1 === run_version_check( [ 'header' => '1.0.0', 'constant' => '1.0.0', 'package' => '1.0.0', 'stable' => '1.0.1-beta.1' ] ), 'a stable header with a beta stable tag disagrees' );
prerelease_check( 1 === run_version_check( [ 'header' => '1.0.1-beta.1', 'constant' => '1.0.1-beta.2', 'package' => '1.0.1-beta.1', 'stable' => '1.0.1-beta.1' ] ), 'two betas disagree' );
prerelease_check( 0 === run_version_check( $all( '1.0.0' ), [ '--expected', '1.0.0' ] ), 'the stable release still passes' );
prerelease_check( 1 === run_version_check( $all( '1.0.0-' ) ), 'a dangling pre-release dash is unreadable' );
prerelease_check( 1 === run_version_check( $all( '1.0.0 beta' ) ), 'a version with trailing words is unreadable' );

if ( $failures > 0 ) {
	fwrite( STDERR, "VersionConsistencyPrereleaseTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "VersionConsistencyPrereleaseTest ok\n" );
