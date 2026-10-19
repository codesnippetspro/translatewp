<?php

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

use TranslateWP\Components\PlainText;

$failures = 0;
function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

assert_true( 'Roam & Relish' === PlainText::decode( 'Roam &amp; Relish' ), 'an ampersand entity decodes' );
assert_true( "Chef\u{2019}s table" === PlainText::decode( 'Chef&#8217;s table' ), 'a texturized apostrophe decodes' );
assert_true( '<b>bold</b>' === PlainText::decode( '&lt;b&gt;bold&lt;/b&gt;' ), 'decoding never adds markup of its own' );
assert_true( 'Roam & Relish' === PlainText::blog_name( (object) [ 'blogname' => 'Roam &amp; Relish' ] ), 'a blog name decodes' );
assert_true( '' === PlainText::blog_name( (object) [] ), 'a site without a name is empty' );

// Every title read in shipped code goes through PlainText, so an encoded title
// can never reach the admin app again.
$offenders = [];
foreach ( [ 'Components', 'Functionality', 'Entities', 'Includes' ] as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/' . $dir ) );
	foreach ( $iterator as $file ) {
		if ( 'php' !== $file->getExtension() || 'PlainText.php' === $file->getFilename() ) {
			continue;
		}
		$source = (string) file_get_contents( $file->getPathname() );
		foreach ( [ 'get_the_title(', "get_bloginfo( 'name' )", '->blogname', '->post_title', "get_option( 'blogname'" ] as $needle ) {
			foreach ( explode( "\n", $source ) as $number => $line ) {
				if ( false !== strpos( $line, $needle ) && false === strpos( $line, 'PlainText::' ) ) {
					$offenders[] = $dir . '/' . $file->getFilename() . ':' . ( $number + 1 ) . ' ' . trim( $line );
				}
			}
		}
	}
}
assert_true( [] === $offenders, "a title is read without PlainText:\n" . implode( "\n", $offenders ) );

// A title stored encoded by an older version still comes out plain everywhere it is read.
wp_test_reset( [] );
$queue = new TranslateWP\Components\IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 7, 'language' => 'lv', 'url' => 'https://example.com/learn/', 'page_title' => 'Roam &amp; Relish Kitchen School', 'priority' => 10 ] ] );
$rows = $queue->list_rows();
assert_true( [] !== $rows && 'Roam & Relish Kitchen School' === (string) ( $rows[0]['page_title'] ?? '' ), 'the queue hands back a stored title as plain text, got ' . wp_json_encode( array_column( $rows, 'page_title' ) ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "PlainTextTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "PlainTextTest: all assertions passed\n";
