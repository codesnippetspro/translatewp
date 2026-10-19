<?php

declare(strict_types=1);

// Password-protected and private pages are left out of translation unless
// the site switches them on, and a page excluded in the editor is always
// left out. This file covers the rule itself, the context scan and the
// visitor bundle; the spine and the re-warm are covered beside their units.

use TranslateWP\Components\Options;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Entities\Styles\FrontendShared;
use TranslateWP\Functionality\Endpoints\Scanning;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

const OPEN_PAGE      = 1;
const PASSWORD_PAGE  = 2;
const PRIVATE_PAGE   = 3;
const EXCLUDED_PAGE  = 4;

function seed_site( bool $translate_protected = false ): void {
	wp_test_reset(
		[
			'site'      => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ],
			'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ],
			'setup'     => [ 'ended' => true ],
		]
	);
	if ( $translate_protected ) {
		Options::update( 'translations.settings', [ PageExclusion::PROTECTED_SETTING => true ] );
	}

	$GLOBALS['wp_test_inserted_posts'] = [
		[ 'ID' => OPEN_PAGE, 'post_type' => 'page', 'post_content' => 'Open text.', 'post_status' => 'publish' ],
		[ 'ID' => PASSWORD_PAGE, 'post_type' => 'page', 'post_content' => 'Members text.', 'post_status' => 'publish', 'post_password' => 'secret' ],
		[ 'ID' => PRIVATE_PAGE, 'post_type' => 'page', 'post_content' => 'Staff text.', 'post_status' => 'private' ],
		[ 'ID' => EXCLUDED_PAGE, 'post_type' => 'page', 'post_content' => 'Excluded text.', 'post_status' => 'publish', 'meta_input' => [ PageExclusion::META_KEY => '1' ] ],
	];
	$GLOBALS['wp_test_posts']['page']  = [ OPEN_PAGE, PASSWORD_PAGE, PRIVATE_PAGE, EXCLUDED_PAGE ];
	$GLOBALS['wp_test_post_statuses']  = [ PRIVATE_PAGE => 'private' ];
	$GLOBALS['wp_test_post_type_map']  = [ OPEN_PAGE => 'page', PASSWORD_PAGE => 'page', PRIVATE_PAGE => 'page', EXCLUDED_PAGE => 'page' ];
}

function scanned_samples(): array {
	( new Scanning( 'translatewp', TRANSLATEWP_VERSION ) )->run_scan();
	$request = end( $GLOBALS['wp_test_remote_posts'] );
	$samples = is_array( $request ) ? (array) ( $request['body']['samples'] ?? [] ) : [];
	sort( $samples );

	return $samples;
}

function bundle_loads_on( int $page_id ): bool {
	$GLOBALS['wp_test_is_singular']       = true;
	$GLOBALS['wp_test_queried_object_id'] = $page_id;
	$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
	$style  = new FrontendShared( 'translatewp', TRANSLATEWP_VERSION );

	return $script->conditions() && $style->conditions();
}

// --- The rule, with default settings ---
seed_site();
assert_true( ! PageExclusion::protected_pages_translated(), 'Protected and private pages are off by default' );
assert_true( ! PageExclusion::is_excluded( OPEN_PAGE ), 'An open page is translated' );
assert_true( PageExclusion::is_excluded( PASSWORD_PAGE ), 'A password-protected page is left out by default' );
assert_true( PageExclusion::is_excluded( PRIVATE_PAGE ), 'A private page is left out by default' );
assert_true( PageExclusion::is_excluded( EXCLUDED_PAGE ), 'A page excluded in the editor is left out' );
assert_true( ! PageExclusion::is_excluded( 0 ), 'No page, nothing to exclude' );

// --- The context scan sends none of the three ---
assert_true( [ 'Open text.' ] === scanned_samples(), 'The scan must send only the open page, got ' . wp_json_encode( scanned_samples() ) );

// --- The visitor bundle loads on the open page only, for a manager too ---
foreach ( [ [], [ 'manage_options' ] ] as $caps ) {
	$GLOBALS['wp_test_caps'] = $caps;
	$who                     = $caps ? 'a signed-in manager' : 'a visitor';
	assert_true( bundle_loads_on( OPEN_PAGE ), "The bundle loads on an open page for {$who}" );
	assert_true( ! bundle_loads_on( PASSWORD_PAGE ), "The bundle must not load on a password-protected page for {$who}" );
	assert_true( ! bundle_loads_on( PRIVATE_PAGE ), "The bundle must not load on a private page for {$who}" );
	assert_true( ! bundle_loads_on( EXCLUDED_PAGE ), "The bundle must not load on an excluded page for {$who}" );
}
$GLOBALS['wp_test_caps'] = [];

// A listing is no single page: the bundle loads.
$GLOBALS['wp_test_is_singular']       = false;
$GLOBALS['wp_test_queried_object_id'] = 0;
assert_true( ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->conditions(), 'The bundle loads on a listing' );

// --- With protected and private pages switched on ---
seed_site( true );
assert_true( PageExclusion::protected_pages_translated(), 'The site setting switches protected and private pages on' );
assert_true( ! PageExclusion::is_excluded( PASSWORD_PAGE ) && ! PageExclusion::is_excluded( PRIVATE_PAGE ), 'Protected and private pages translate once switched on' );
assert_true( PageExclusion::is_excluded( EXCLUDED_PAGE ), 'The per-page exclusion holds whatever the site setting says' );
assert_true( [ 'Members text.', 'Open text.', 'Staff text.' ] === scanned_samples(), 'Switched on, the scan reads protected and private pages but never the excluded one, got ' . wp_json_encode( scanned_samples() ) );
assert_true( bundle_loads_on( PASSWORD_PAGE ) && bundle_loads_on( PRIVATE_PAGE ), 'Switched on, the bundle loads on protected and private pages' );
assert_true( ! bundle_loads_on( EXCLUDED_PAGE ), 'Switched on, the bundle still does not load on an excluded page' );

// --- The editor setting is a registered, protected post meta ---
seed_site();
PageExclusion::register_meta();
$registered = $GLOBALS['wp_test_registered_post_meta']['page'][ PageExclusion::META_KEY ] ?? null;
assert_true( is_array( $registered ), 'The exclusion is registered as post meta for pages' );
assert_true( true === ( $registered['show_in_rest'] ?? null ) && 'boolean' === ( $registered['type'] ?? '' ) && true === ( $registered['single'] ?? null ), 'The exclusion is a single boolean the editor can save' );
$GLOBALS['wp_test_caps'] = [];
assert_true( false === call_user_func( $registered['auth_callback'], false, PageExclusion::META_KEY, OPEN_PAGE ), 'A user who cannot edit the page cannot change its exclusion' );
$GLOBALS['wp_test_caps'] = [ 'edit_post' ];
assert_true( true === call_user_func( $registered['auth_callback'], false, PageExclusion::META_KEY, OPEN_PAGE ), 'A user who can edit the page can change its exclusion' );

if ( $failures > 0 ) {
	fwrite( STDERR, "PageExclusionTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "PageExclusionTest: all assertions passed\n";
