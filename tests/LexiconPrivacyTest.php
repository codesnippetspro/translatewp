<?php

declare(strict_types=1);

use TranslateWP\Entities\CPTs\Lexicon;
use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Entities\Scripts\Admin as AdminScript;
use TranslateWP\Components\Access;
use TranslateWP\Components\CleanupInventory;
use TranslateWP\Components\Migrations;
use TranslateWP\Components\PageCachePurge;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.2' );
}

function register_taxonomy( $taxonomy, $object_type, $args = [] ) {
	$GLOBALS['lexicon_test_registered_taxonomies'][ $taxonomy ] = $args;
	return true;
}

function get_terms( $args = [] ) {
	return $GLOBALS['lexicon_test_categories'] ?? [];
}

$failures = 0;
function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [] );
$GLOBALS['lexicon_test_registered_taxonomies'] = [];
$GLOBALS['lexicon_test_categories'] = [ (object) [ 'term_id' => 19, 'name' => 'Brand', 'slug' => 'brand' ] ];
( new Lexicon() )->register();

$post_type = $GLOBALS['wp_test_registered_post_types'][ Lexicon::POST_TYPE ] ?? [];
$taxonomy = $GLOBALS['lexicon_test_registered_taxonomies'][ Lexicon::CATEGORY_TAXONOMY ] ?? [];
foreach ( [ 'public', 'publicly_queryable', 'exclude_from_search', 'show_in_rest', 'has_archive', 'rewrite', 'query_var' ] as $flag ) {
	$expected = 'exclude_from_search' === $flag;
	assert_true( $expected === ( $post_type[ $flag ] ?? null ), "Lexicon post type {$flag} prevents a public read" );
}
foreach ( [ 'public', 'show_in_rest', 'rewrite', 'query_var' ] as $flag ) {
	assert_true( false === ( $taxonomy[ $flag ] ?? null ), "Lexicon category {$flag} prevents a public read" );
}

$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$category_route = null;
foreach ( $admin->define_endpoints() as $route ) {
	if ( 'get-lexicon-categories' === $route->getPath() ) {
		$category_route = $route;
		break;
	}
}
assert_true( null !== $category_route, 'a private route supplies the Lexicon category picker' );

if ( null !== $category_route ) {
	$allowed = $category_route->getPermissionCallback();
	$GLOBALS['wp_test_current_user'] = 0;
	assert_true( false === $allowed(), 'anonymous visitors cannot read Lexicon categories' );

	$GLOBALS['wp_test_current_user'] = 1;
	$GLOBALS['wp_test_users'][1] = (object) [ 'ID' => 1, 'roles' => [ 'administrator' ] ];
	$GLOBALS['wp_test_user_caps'][1] = [ 'manage_options', 'edit_pages' ];
	$GLOBALS['wp_test_caps'] = $GLOBALS['wp_test_user_caps'][1];
	assert_true( true === $allowed(), 'an administrator can read Lexicon categories' );
	assert_true(
		[ [ 'id' => 19, 'name' => 'Brand', 'slug' => 'brand' ] ] === $admin->get_lexicon_categories( new WP_REST_Request( 'GET', '' ) ),
		'the authorized category picker receives its categories'
	);
}

wp_test_reset( [
	'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
	'languages' => [ 'list' => [ 'de' => [ 'locale' => 'de', 'active' => true, 'default' => false ] ] ],
] );
$GLOBALS['wp_test_lexicon_posts'] = [ 77 => [ 'title' => 'Private Brand', 'content' => 'Private usage note' ] ];
$GLOBALS['wp_test_lexicon_meta'] = [ 77 => [ Lexicon::LANGUAGES_META_KEY => [ 'de' ] ] ];
$visitor = ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->frontend_data();
assert_true( false !== strpos( $visitor, '"lexicon_terms":[]' ), 'visitor settings keep a valid empty Lexicon field' );
assert_true( false === strpos( $visitor, 'Private Brand' ) && false === strpos( $visitor, 'Private usage note' ), 'visitor markup never carries the term or description' );
$server = TranslationProxySettings::ai_settings( 'de' );
assert_true( [ [ 'term' => 'Private Brand', 'description' => 'Private usage note' ] ] === $server['lexicon_terms'], 'server translation still receives the scoped term' );

$GLOBALS['wp_test_current_user'] = 7;
$GLOBALS['wp_test_current_user_id'] = 7;
$GLOBALS['wp_test_current_user_roles'] = [ 'editor' ];
$GLOBALS['wp_test_users'] = [ (object) [ 'ID' => 7, 'roles' => [ 'editor' ] ] ];
$GLOBALS['wp_test_user_caps'][7] = [ 'edit_pages' ];
$GLOBALS['wp_test_caps'] = [ 'edit_pages' ];
Access::set_role_map( [ 'manage_settings' => [ 'editor' => true ] ] );
assert_true( Access::can( Access::SETTINGS ) && ! Access::can( Access::LEXICON ) && ! Access::can( Access::EDIT ), 'the fixture holds only Manage settings' );
$settings_data = ( new AdminScript( 'translatewp', TRANSLATEWP_VERSION ) )->context_data();
assert_true( false !== strpos( $settings_data, '"lexicon_terms":[]' ), 'a settings-only manager receives no Lexicon terms' );
assert_true( false === strpos( $settings_data, 'Private Brand' ) && false === strpos( $settings_data, 'Private usage note' ), 'a settings-only manager cannot read Lexicon text in admin markup' );
Access::set_role_map( [ 'manage_settings' => [ 'editor' => true ], 'manage_lexicon' => [ 'editor' => true ] ] );
assert_true( Access::can( Access::LEXICON ), 'the fixture now holds Manage the Lexicon' );
$lexicon_data = ( new AdminScript( 'translatewp', TRANSLATEWP_VERSION ) )->context_data();
assert_true( false !== strpos( $lexicon_data, 'Private Brand' ) && false !== strpos( $lexicon_data, 'Private usage note' ), 'a Lexicon manager retains terms for authorized editing' );

$purges = 0;
wp_test_reset( [] );
update_option( Migrations::VERSION_OPTION, '1.0.2' );
wp_test_set_filter( PageCachePurge::ACTION, static function () use ( &$purges ): void { ++$purges; } );
Migrations::maybe_run();
Migrations::maybe_run();
assert_true( 1 === $purges, 'an existing site purges page caches only once' );
assert_true( (bool) get_option( Migrations::LEXICON_PRIVATE_CACHE_PURGED_OPTION ), 'the one-off marker is stored' );

wp_test_reset( [] );
$purges = 0;
wp_test_set_filter( PageCachePurge::ACTION, static function () use ( &$purges ): void { ++$purges; } );
Migrations::seed_for_fresh_install();
Migrations::maybe_run();
assert_true( 0 === $purges, 'a fresh installation does not purge existing page caches' );

wp_test_reset( [] );
$purges = 0;
update_option( Migrations::VERSION_OPTION, '1.0.2' );
wp_test_set_filter( PageCachePurge::ACTION, static function () use ( &$purges ): void { ++$purges; } );
Migrations::seed_for_fresh_install();
Migrations::maybe_run();
assert_true( 1 === $purges, 'reactivating an existing site still purges its older public cache' );

wp_test_reset( [] );
$purges = 0;
wp_test_set_filter( PageCachePurge::ACTION, static function () use ( &$purges ): void { ++$purges; } );
Migrations::maybe_run();
Migrations::maybe_run();
assert_true( 0 === $purges, 'a subsite with no stored plugin version seeds the marker without purging' );

wp_test_reset( [] );
$purges = 0;
update_option( Migrations::VERSION_OPTION, '999.0.0' );
wp_test_set_filter( PageCachePurge::ACTION, static function () use ( &$purges ): void { ++$purges; } );
Migrations::maybe_run();
assert_true( 0 === $purges, 'running older plugin code after a newer version does not purge' );

wp_test_reset( [] );
$purges = 0;
update_option( Migrations::VERSION_OPTION, '1.0.2' );
wp_test_set_filter( PageCachePurge::ACTION, static function () use ( &$purges ): void {
	++$purges;
	throw new RuntimeException( 'cache hook failed' );
} );
try {
	Migrations::maybe_run();
} catch ( RuntimeException $exception ) {
	assert_true( 'cache hook failed' === $exception->getMessage(), 'the cache hook failure is visible' );
}
Migrations::maybe_run();
assert_true( 1 === $purges, 'a failing cache hook cannot trigger repeated whole-site purges' );
assert_true( in_array( Migrations::LEXICON_PRIVATE_CACHE_PURGED_OPTION, CleanupInventory::INFRASTRUCTURE_OPTIONS, true ), 'uninstall removes the one-off marker' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LexiconPrivacyTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LexiconPrivacyTest: all assertions passed\n";
