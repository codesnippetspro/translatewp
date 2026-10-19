<?php

declare(strict_types=1);

/**
 * The shipped code must carry no decision-register identifiers or internal
 * lane/slice/tier taxonomy: maintainers read the register as the source of
 * truth, and none of it should reach a wp.org reader or a visitor's page. The
 * intent behind a line lives in the register; the code is self-documenting.
 *
 * The count is a baseline, not zero, because the existing references are
 * removed by a separate register-driven cleanup. It only goes DOWN: a NEW
 * reference pushes the count up and fails here, and lowering the baseline is a
 * deliberate edit that records the cleanup's progress. tests/ and src/ keep
 * their references (they do not ship); the visitor <style> compiles into
 * build/, so build/ is checked too whenever a build is present.
 */

$root = dirname( __DIR__ );
$pattern = '/' . json_decode( (string) file_get_contents( $root . '/scripts/decision-reference-pattern.json' ), true ) . '/i';

// The bundle include list minus third-party vendor (its references are not
// ours) and minus tests/ and src/ (which do not ship).
$source_paths = [ 'translatewp.php', 'index.php', 'readme.txt', 'Components', 'Entities', 'Functionality', 'Includes', 'Utils' ];

const SOURCE_BASELINE = 0;
const BUILD_BASELINE  = 0;

$collect = static function ( array $entries ) use ( $root ): array {
	$files = [];
	foreach ( $entries as $entry ) {
		$path = $root . '/' . $entry;
		if ( is_dir( $path ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$files[] = $file->getPathname();
				}
			}
		} elseif ( is_file( $path ) ) {
			$files[] = $path;
		}
	}
	return $files;
};

$count = static function ( array $files, string $pattern, array &$by_file ) use ( $root ): int {
	$total = 0;
	foreach ( $files as $file ) {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			continue;
		}
		$hits = preg_match_all( $pattern, $contents );
		if ( $hits > 0 ) {
			$total += $hits;
			$by_file[ str_replace( $root . '/', '', $file ) ] = $hits;
		}
	}
	return $total;
};

$report = static function ( string $label, int $total, int $baseline, array $by_file ): bool {
	if ( $total === $baseline ) {
		return true;
	}
	fwrite( STDERR, "DecisionReferenceSweepTest: {$label} carries {$total} references, expected {$baseline}.\n" );
	if ( $total > $baseline ) {
		fwrite( STDERR, "A new decision identifier or lane/slice/tier reference reached shipped code. Remove it; its intent belongs in the register.\n" );
	} else {
		fwrite( STDERR, "References were removed - lower the {$label} baseline to {$total} to record the progress.\n" );
	}
	arsort( $by_file );
	foreach ( $by_file as $file => $hits ) {
		fwrite( STDERR, sprintf( "  %3d  %s\n", $hits, $file ) );
	}
	return false;
};

$ok = true;

$source_by_file = [];
$source_total   = $count( $collect( $source_paths ), $pattern, $source_by_file );
$ok = $report( 'shipped source', $source_total, SOURCE_BASELINE, $source_by_file ) && $ok;

// src/ and tests/ do not ship, but a reference there is the same leak waiting
// for the next compile or copy; the pattern is held to zero there too.
$dev_by_file = [];
$dev_total   = $count( $collect( [ 'src', 'tests', 'scripts', '.github', '.distignore', 'CONTEXT.md' ] ), $pattern, $dev_by_file );
$ok = $report( 'development source', $dev_total, 0, $dev_by_file ) && $ok;

// The visitor <style> compiles into build/, so a reference in a src stylesheet
// only surfaces there. The build is not committed, so it must be built before
// this runs (the CI Verification loop and the release pipeline both do); a
// missing build is a loud failure, never a silent skip that lets a compiled
// leak through unchecked.
if ( ! is_dir( $root . '/build' ) ) {
	fwrite( STDERR, "DecisionReferenceSweepTest: build/ is absent - run `yarn build` before composer test:php so the compiled output is checked.\n" );
	exit( 1 );
}
$build_by_file = [];
$build_total   = $count( $collect( [ 'build' ] ), $pattern, $build_by_file );
$ok = $report( 'compiled build', $build_total, BUILD_BASELINE, $build_by_file ) && $ok;

if ( ! $ok ) {
	exit( 1 );
}

echo 'DecisionReferenceSweepTest: shipped code carries only its known references, none new' . "\n";
