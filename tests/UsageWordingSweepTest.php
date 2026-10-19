<?php

declare(strict_types=1);

// Usage is stated as a share of the plan, never in tokens and never as
// credits. Every translatable string of the shipped set and every readme
// line is read; the connection token is a credential, not a usage figure.

$roots = [
	dirname( __DIR__ ) . '/src/apps/admin',
	dirname( __DIR__ ) . '/src/apps/setup',
	dirname( __DIR__ ) . '/src/apps/translatewp',
	dirname( __DIR__ ) . '/src/shared',
	dirname( __DIR__ ) . '/Components',
	dirname( __DIR__ ) . '/Functionality',
	dirname( __DIR__ ) . '/Entities',
	dirname( __DIR__ ) . '/readme.txt',
];

const TRANSLATABLE_CALL = '/\b(?:__|_e|_n|_x|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(/';
const USAGE_WORD        = '/\b(?:tokens?|credits?)\b/i';
const CREDENTIAL        = '/\bconnection token\b/i';

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

$found = [];
foreach ( $files as $file ) {
	$is_readme = 'readme.txt' === basename( $file );
	foreach ( file( $file ) ?: [] as $number => $line ) {
		if ( ! $is_readme && ( preg_match( '/^\s*(?:\/\/|\/\*|\*|\*\/)/', $line ) || ! preg_match( TRANSLATABLE_CALL, $line ) ) ) {
			continue;
		}

		$text = (string) preg_replace( CREDENTIAL, '', $line );
		if ( preg_match( USAGE_WORD, $text ) ) {
			$found[] = str_replace( dirname( __DIR__ ) . '/', '', $file ) . ':' . ( $number + 1 );
		}
	}
}

if ( [] !== $found ) {
	fwrite( STDERR, "UsageWordingSweepTest: token or credits wording found in\n  " . implode( "\n  ", $found ) . "\n" );
	exit( 1 );
}

echo "UsageWordingSweepTest: no token or credits wording in user-facing text\n";
