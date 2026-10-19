<?php

declare(strict_types=1);

// The admin menu shows the TranslateWP mark, on the site and on the network.

use TranslateWP\Components\BrandIcon;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function brand_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		++$failures;
	}
}

$icon = BrandIcon::menu_icon();
brand_check( 0 === strpos( $icon, 'data:image/svg+xml;base64,' ), 'the menu icon is an inline SVG' );
$svg = (string) base64_decode( substr( $icon, strlen( 'data:image/svg+xml;base64,' ) ), true );
brand_check( false !== strpos( $svg, 'viewBox="0 0 20 20"' ), 'the menu icon is the 20 px mark' );
brand_check( false !== strpos( $svg, 'fill="black"' ) && false === strpos( $svg, 'stroke=' ), 'it is drawn with fills only, so WordPress recolours it with the menu' );

foreach ( [ 'Functionality/Admin/Menus.php', 'Functionality/Admin/MultisiteSettingsPage.php' ] as $file ) {
	$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
	brand_check( false !== strpos( $source, 'BrandIcon::menu_icon()' ) && false === strpos( $source, 'dashicons-admin-site-alt3' ), "{$file} uses the TranslateWP mark" );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "BrandMenuIconTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "BrandMenuIconTest ok\n" );
