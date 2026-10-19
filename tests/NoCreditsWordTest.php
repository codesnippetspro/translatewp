<?php

declare(strict_types=1);

// A user-facing count of consumption is labelled "Usage"; the word
// "credits" is never used. Swept like EmDashSweepTest over every user-facing
// source, comments included, so a translator note cannot reintroduce it.

$roots = [
	dirname( __DIR__ ) . '/src',
	dirname( __DIR__ ) . '/Components',
	dirname( __DIR__ ) . '/Functionality',
	dirname( __DIR__ ) . '/Entities',
	dirname( __DIR__ ) . '/Includes',
	dirname( __DIR__ ) . '/Utils',
	dirname( __DIR__ ) . '/blocks',
	dirname( __DIR__ ) . '/languages',
	dirname( __DIR__ ) . '/translatewp.php',
	dirname( __DIR__ ) . '/readme.txt',
];

$files = [];
foreach ( $roots as $root ) {
	if ( is_dir( $root ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && false === strpos( $file->getPathname(), '__tests__' ) ) {
				$files[] = $file->getPathname();
			}
		}
	} elseif ( is_file( $root ) ) {
		$files[] = $root;
	}
}

$found = [];
foreach ( $files as $file ) {
	foreach ( file( $file ) ?: [] as $number => $line ) {
		if ( preg_match( '/\bcredits?\b/i', $line ) ) {
			$found[] = $file . ':' . ( $number + 1 );
		}
	}
}

// The sweep must be able to fail: the pattern catches the word it guards.
if ( ! preg_match( '/\bcredits?\b/i', 'You have 40 Credits left' ) ) {
	fwrite( STDERR, "NoCreditsWordTest: the pattern no longer matches the word\n" );
	exit( 1 );
}

if ( [] !== $found ) {
	fwrite( STDERR, 'NoCreditsWordTest: the word "credits" found in ' . implode( ', ', $found ) . "\n" );
	exit( 1 );
}

echo 'NoCreditsWordTest: no user-facing source says "credits" (' . count( $files ) . " files)\n";
