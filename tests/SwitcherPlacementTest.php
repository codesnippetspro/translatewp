<?php

declare(strict_types=1);

use TranslateWP\Components\SwitcherPlacement;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

// A placement is a reproducible description, never
// viewport coordinates; a page placement overrides the site-wide one and
// hands back to it when removed.
$failures = 0;
function check_placement( bool $value, string $message ): void {
	global $failures;
	if ( ! $value ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [] );

// A zone stored by an earlier version still reads; the editor no longer offers one.
check_placement( [ 'kind' => 'zone', 'zone' => 'footer' ] === SwitcherPlacement::sanitize( [ 'kind' => 'zone', 'zone' => 'footer' ] ), 'A stored zone is still read' );
check_placement( null === SwitcherPlacement::sanitize( [ 'kind' => 'zone', 'zone' => 'toolbar' ] ), 'An unknown zone is refused' );
foreach ( [ 'before', 'after', 'left', 'right' ] as $side ) {
	$sided = [ 'kind' => 'anchor', 'selector' => '#site-footer', 'position' => $side ];
	check_placement( $sided === SwitcherPlacement::sanitize( $sided ), "An anchor on the {$side} side is kept" );
}
foreach ( [ 'prepend', 'append' ] as $legacy ) {
	$stored = [ 'kind' => 'anchor', 'selector' => '#site-footer', 'position' => $legacy ];
	check_placement( $stored === SwitcherPlacement::sanitize( $stored ), "A stored {$legacy} anchor is still read" );
}
check_placement( 'floating' === SwitcherPlacement::anchor_fallback(), 'The anchor fallback shows the floating switcher by default' );
SwitcherPlacement::save_anchor_fallback( 'none' );
check_placement( 'none' === SwitcherPlacement::anchor_fallback(), 'The anchor fallback can be set to no switcher' );
SwitcherPlacement::save_anchor_fallback( 'sideways' );
check_placement( 'none' === SwitcherPlacement::anchor_fallback(), 'An unknown fallback value is ignored' );
SwitcherPlacement::save_anchor_fallback( 'floating' );

$anchor = [ 'kind' => 'anchor', 'selector' => 'body > main > article:nth-of-type(2)', 'position' => 'before' ];
check_placement( $anchor === SwitcherPlacement::sanitize( $anchor ), 'An unrestricted anchor keeps its CSS path and position' );
check_placement( null === SwitcherPlacement::sanitize( [ 'kind' => 'anchor', 'selector' => 'a[href="x"]', 'position' => 'before' ] ), 'An attribute selector is refused' );
check_placement( null === SwitcherPlacement::sanitize( [ 'kind' => 'anchor', 'selector' => '#hero', 'position' => 'inside' ] ), 'An unknown anchor position is refused' );
check_placement( null === SwitcherPlacement::sanitize( [ 'kind' => 'pixels', 'x' => 10, 'y' => 20 ] ), 'Screen coordinates are never a placement' );
check_placement( null === SwitcherPlacement::sanitize( 'footer' ), 'A bare string is not a placement' );

SwitcherPlacement::save_site_placement( [ 'kind' => 'zone', 'zone' => 'header' ] );
check_placement( [ 'kind' => 'zone', 'zone' => 'header' ] === SwitcherPlacement::site_placement(), 'A site-wide placement lives in the switcher settings' );
SwitcherPlacement::save_site_placement( null );
check_placement( null === SwitcherPlacement::site_placement(), 'A removed site-wide placement is gone' );

$page_id  = (int) wp_insert_post(
	[
		'post_type'   => 'page',
		'post_title'  => 'Placed page',
		'post_status' => 'publish',
	]
);
$other_id = (int) wp_insert_post(
	[
		'post_type'   => 'page',
		'post_title'  => 'Other page',
		'post_status' => 'publish',
	]
);
SwitcherPlacement::save_page_placement( $page_id, [ 'kind' => 'zone', 'zone' => 'sidebar' ] );
check_placement( [ 'kind' => 'zone', 'zone' => 'sidebar' ] === SwitcherPlacement::page_placement( $page_id ), 'A page placement is stored on the post' );
check_placement( null === SwitcherPlacement::page_placement( $other_id ), 'Another page has no placement' );
SwitcherPlacement::save_page_placement( $page_id, null );
check_placement( null === SwitcherPlacement::page_placement( $page_id ), 'A removed page placement hands back to the site-wide value' );
check_placement( null === SwitcherPlacement::page_placement( 0 ), 'No post means no page placement' );

if ( $failures > 0 ) {
	exit( 1 );
}
echo "SwitcherPlacementTest OK\n";
