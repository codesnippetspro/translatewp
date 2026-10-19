<?php

declare(strict_types=1);

$roots = [
	dirname( __DIR__ ) . '/src',
	dirname( __DIR__ ) . '/Components',
	dirname( __DIR__ ) . '/Functionality',
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
	} else {
		$files[] = $root;
	}
}

foreach ( $files as $file ) {
	foreach ( file( $file ) ?: [] as $line ) {
		if ( preg_match( '/^\s*(?:\/\/|\/\*|\*|\*\/)/', $line ) ) {
			continue;
		}

		if ( preg_match( '/—|&mdash;|&#8212;|&#x2014;|\\\\u2014/i', $line ) ) {
			fwrite( STDERR, "EmDashSweepTest: em dash found in {$file}\n" );
			exit( 1 );
		}
	}
}

echo "EmDashSweepTest: all user-facing sources are clean\n";
