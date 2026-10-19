<?php

declare(strict_types=1);

// The per-page "exclude from translation" setting in the editor: a side
// meta box on every public post type, saved only for a user who may edit
// the page and only with its nonce.

use TranslateWP\Components\PageExclusion;
use TranslateWP\Functionality\Admin\PageExclusionMetaBox;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function seed_page( array $extra = [] ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_inserted_posts'] = [ array_merge( [ 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish' ], $extra ) ];
	$GLOBALS['wp_test_post_type_map']  = [ 7 => 'page' ];
	$GLOBALS['wp_test_caps']           = [ 'edit_post' ];
	$_POST                             = [];
}

function rendered_box( PageExclusionMetaBox $box ): string {
	ob_start();
	$box->render( (object) [ 'ID' => 7 ] );
	return (string) ob_get_clean();
}

seed_page();
$box = new PageExclusionMetaBox( 'translatewp', '1.0.0' );

// --- The box is offered on public post types, in the side column ---
$box->register( 'page' );
$definition = end( $GLOBALS['wp_test_meta_box_definitions'] );
assert_true( 'translatewp-exclude' === ( $definition['id'] ?? '' ) && 'page' === ( $definition['screen'] ?? '' ) && 'side' === ( $definition['context'] ?? '' ), 'The exclusion box is added to the page editor side column, got ' . wp_json_encode( $definition ) );
$boxes_before = count( $GLOBALS['wp_test_meta_box_definitions'] );
$box->register( 'attachment' );
assert_true( count( $GLOBALS['wp_test_meta_box_definitions'] ) === $boxes_before, 'Media items get no exclusion box' );

// --- It shows the saved state ---
$html = rendered_box( $box );
assert_true( false !== strpos( $html, 'name="' . PageExclusionMetaBox::FIELD . '"' ) && false === strpos( $html, 'checked' ), 'An open page shows the box unchecked, got ' . $html );
assert_true( false !== strpos( $html, 'Exclude from translation' ), 'The box names what it does' );

// --- Saving with the box ticked excludes the page ---
$_POST = [ PageExclusionMetaBox::NONCE => 'test-nonce', PageExclusionMetaBox::FIELD => '1' ];
$box->save( 7 );
assert_true( PageExclusion::is_excluded( 7 ), 'Ticking the box excludes the page' );
assert_true( false !== strpos( rendered_box( $box ), 'checked' ), 'An excluded page shows the box ticked' );

// --- Saving with it unticked includes the page again ---
$_POST = [ PageExclusionMetaBox::NONCE => 'test-nonce' ];
$box->save( 7 );
assert_true( ! PageExclusion::is_excluded( 7 ), 'Unticking the box includes the page again' );

// --- A save without the nonce, or by a user who cannot edit the page, changes nothing ---
$_POST = [ PageExclusionMetaBox::FIELD => '1' ];
$box->save( 7 );
assert_true( ! PageExclusion::is_excluded( 7 ), 'A save without the nonce changes nothing' );

$_POST                   = [ PageExclusionMetaBox::NONCE => 'test-nonce', PageExclusionMetaBox::FIELD => '1' ];
$GLOBALS['wp_test_caps'] = [];
$box->save( 7 );
assert_true( ! PageExclusion::is_excluded( 7 ), 'A user who cannot edit the page changes nothing' );

// --- A protected page says why it is not translated ---
seed_page( [ 'post_password' => 'secret' ] );
assert_true( false !== strpos( rendered_box( $box ), 'Password-protected and private pages are not translated' ), 'A protected page explains that it is left out by the site setting' );
\TranslateWP\Components\Options::update( 'translations.settings', [ PageExclusion::PROTECTED_SETTING => true ] );
assert_true( false === strpos( rendered_box( $box ), 'Password-protected and private pages are not translated' ), 'Switched on, the note is gone' );
$_POST = [];

if ( $failures > 0 ) {
	fwrite( STDERR, "PageExclusionMetaBoxTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "PageExclusionMetaBoxTest: all assertions passed\n";
