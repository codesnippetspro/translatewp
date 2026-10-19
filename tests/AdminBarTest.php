<?php

declare(strict_types=1);

// TranslateWP in the toolbar: under the site name, on the site and in
// wp-admin, and under each site in My Sites, opening the admin page the user has there;
// no entry where the user has none.

use TranslateWP\Functionality\AdminBar;

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

const ADMIN  = 1;
const EDITOR = 7;

/**
 * A set-up site, with its own options on a network.
 *
 * @param int $blog_id The site.
 */
function set_up_site( int $blog_id ): void {
	$prefix = 1 === $blog_id ? '' : 'blog_' . $blog_id . '_';
	$GLOBALS['wp_test_options_store'][ $prefix . 'site' ]      = [ 'id' => '00000000-0000-4000-8000-00000000000' . $blog_id, 'token' => 'site-token' ];
	$GLOBALS['wp_test_options_store'][ $prefix . 'setup' ]     = [ 'ended' => true ];
	$GLOBALS['wp_test_options_store'][ $prefix . 'languages' ] = [
		'list' => [
			'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
			'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
		],
	];
}

/**
 * Acts as a user on a fresh single site, on the site's pages.
 *
 * @param int $user_id ADMIN or EDITOR.
 */
function fresh( int $user_id ): void {
	wp_test_reset( [] );
	set_up_site( 1 );
	$GLOBALS['wp_test_is_admin']           = false;
	$GLOBALS['wp_test_current_user']       = $user_id;
	$GLOBALS['wp_test_current_user_id']    = $user_id;
	$GLOBALS['wp_test_user_caps']          = [
		ADMIN  => [ 'manage_options', 'edit_pages' ],
		EDITOR => [ 'edit_pages' ],
	];
	$GLOBALS['wp_test_caps']               = $GLOBALS['wp_test_user_caps'][ $user_id ];
	$GLOBALS['wp_test_current_user_roles'] = ADMIN === $user_id ? [ 'administrator' ] : [ 'editor' ];
}

/**
 * The editor's role map on a site: the given keys true, the rest false.
 *
 * @param string[] $keys    Role map keys the editor role holds.
 * @param int      $blog_id The site.
 */
function editor_holds( array $keys, int $blog_id = 1 ): void {
	$map = [];
	foreach ( [ 'edit_translations', 'retranslate', 'manage_settings', 'manage_lexicon' ] as $key ) {
		$map[ $key ] = [ 'editor' => in_array( $key, $keys, true ) ];
	}
	$GLOBALS['wp_test_options_store'][ ( 1 === $blog_id ? '' : 'blog_' . $blog_id . '_' ) . 'access' ] = $map;
}

/** The site name entry's address, or null when there is none. */
function site_name_link(): ?string {
	$bar = new WP_Admin_Bar();
	$bar->add_node( [ 'id' => 'site-name', 'title' => 'Site' ] );
	( new AdminBar( 'translatewp', TRANSLATEWP_VERSION ) )->add_links( $bar );
	$node = $bar->get_node( 'site-name-translatewp' );
	if ( null !== $node ) {
		$group = $bar->get_node( AdminBar::SITE_NAME_GROUP );
		assert_true( AdminBar::SITE_NAME_GROUP === $node->parent && null !== $group && 'site-name' === $group->parent && ! empty( $group->group ), 'the entry sits in its own group under the site name, below the Appearance group' );
	}

	return null === $node ? null : (string) $node->href;
}

// --- under the site name ---
fresh( ADMIN );
assert_true( 'https://example.com/wp-admin/admin.php?page=translatewp' === site_name_link(), 'an administrator opens the Dashboard' );

fresh( EDITOR );
assert_true( null === site_name_link(), 'an editor with nothing given gets no entry' );
editor_holds( [ 'edit_translations' ] );
assert_true( null === site_name_link(), 'Edit translations alone has no admin page, so no entry' );
editor_holds( [ 'retranslate' ] );
assert_true( null === site_name_link(), 'Retranslate alone has no admin page, so no entry' );
editor_holds( [ 'manage_settings' ] );
assert_true( str_ends_with( (string) site_name_link(), 'page=translatewp-settings' ), 'Manage settings opens the Settings' );
editor_holds( [ 'manage_lexicon' ] );
assert_true( str_ends_with( (string) site_name_link(), 'page=translatewp-lexicon' ), 'Manage the Lexicon alone opens the Lexicon' );
editor_holds( [ 'manage_settings', 'manage_lexicon', 'edit_translations' ] );
assert_true( str_ends_with( (string) site_name_link(), 'page=translatewp-settings' ), 'with both, the Settings, where the menu opens' );

$GLOBALS['wp_test_options_store']['setup'] = [ 'ended' => false ];
unset( $GLOBALS['wp_test_options_store']['languages'] );
assert_true( null === site_name_link(), 'before setup a delegate has no admin page' );
fresh( ADMIN );
unset( $GLOBALS['wp_test_options_store']['languages'] );
assert_true( str_ends_with( (string) site_name_link(), 'page=translatewp' ), 'before setup an administrator still gets the entry, to the setup' );

fresh( ADMIN );
$GLOBALS['wp_test_is_admin'] = true;
assert_true( str_ends_with( (string) site_name_link(), 'page=translatewp' ), 'in wp-admin an administrator gets the same entry' );
fresh( EDITOR );
$GLOBALS['wp_test_is_admin'] = true;
editor_holds( [ 'manage_lexicon' ] );
assert_true( str_ends_with( (string) site_name_link(), 'page=translatewp-lexicon' ), 'in wp-admin a Lexicon-only user opens the Lexicon' );
editor_holds( [ 'edit_translations', 'retranslate' ] );
assert_true( null === site_name_link(), 'in wp-admin Edit and Retranslate alone still get no entry' );

fresh( ADMIN );
$GLOBALS['wp_test_current_user']       = 0;
$GLOBALS['wp_test_current_user_id']    = 0;
$GLOBALS['wp_test_caps']               = [];
$GLOBALS['wp_test_current_user_roles'] = [];
assert_true( null === site_name_link(), 'a visitor gets no entry' );
fresh( ADMIN );
$bar = new WP_Admin_Bar();
( new AdminBar( 'translatewp', TRANSLATEWP_VERSION ) )->add_links( $bar );
assert_true( [] === $bar->get_nodes(), 'a toolbar without the site name gets nothing' );

// --- My Sites on a network ---
fresh( EDITOR );
$GLOBALS['wp_test_multisite']    = true;
$GLOBALS['wp_test_current_blog'] = 2;
foreach ( [ 1, 2, 3, 4 ] as $blog_id ) {
	set_up_site( $blog_id );
}
update_site_option( 'translatewp_network_settings_defaults', [ 'translatewp_access' => [ 'manage_lexicon' => [ 'editor' => true ] ] ] );
editor_holds( [ 'edit_translations' ], 1 );
editor_holds( [ 'manage_settings' ], 2 );
$bar = new WP_Admin_Bar();
$bar->user->blogs = [ (object) [ 'userblog_id' => 1 ], (object) [ 'userblog_id' => 2 ], (object) [ 'userblog_id' => 3 ], (object) [ 'userblog_id' => 4 ] ];
foreach ( [ 1, 2, 3 ] as $blog_id ) {
	$bar->add_node( [ 'id' => 'blog-' . $blog_id, 'title' => 'Site ' . $blog_id ] );
}
$bar->add_node( [ 'id' => 'network-admin', 'title' => 'Network Admin' ] );
( new AdminBar( 'translatewp', TRANSLATEWP_VERSION ) )->add_links( $bar );
assert_true( null === $bar->get_node( 'blog-1-translatewp' ), "a site whose own map gives edit only gets no entry, the network's Lexicon default overridden" );
$two = $bar->get_node( 'blog-2-translatewp' );
assert_true( null !== $two && 'blog-2' === $two->parent && str_ends_with( (string) $two->href, 'page=translatewp-settings' ), "a site's own Manage settings opens its Settings" );
$three = $bar->get_node( 'blog-3-translatewp' );
assert_true( null !== $three && str_ends_with( (string) $three->href, 'page=translatewp-lexicon' ), "the network's Lexicon default reaches a site with no map of its own" );
assert_true( null === $bar->get_node( 'blog-4-translatewp' ), 'a site missing from My Sites gets nothing' );
assert_true( null === $bar->get_node( 'network-admin-translatewp' ), 'Network Admin gets the entry only for a network administrator' );
assert_true( 2 === get_current_blog_id() && [] === ( $GLOBALS['wp_test_blog_stack'] ?? [] ), 'each site is left again: the current site is restored, got ' . get_current_blog_id() . ' ' . wp_json_encode( $GLOBALS['wp_test_blog_stack'] ?? [] ) );

$GLOBALS['wp_test_caps'] = [ 'manage_network_options', 'manage_options' ];
$GLOBALS['wp_test_current_user_roles'] = [ 'administrator' ];
$bar = new WP_Admin_Bar();
$bar->user->blogs = [ (object) [ 'userblog_id' => 1 ] ];
$bar->add_node( [ 'id' => 'blog-1', 'title' => 'Site 1' ] );
$bar->add_node( [ 'id' => 'network-admin', 'title' => 'Network Admin' ] );
( new AdminBar( 'translatewp', TRANSLATEWP_VERSION ) )->add_links( $bar );
$network = $bar->get_node( 'network-admin-translatewp' );
assert_true( null !== $network && 'network-admin' === $network->parent && str_ends_with( (string) $network->href, 'admin.php?page=translatewp-network-settings' ), 'Network Admin gets the entry to the network settings' );
assert_true( str_ends_with( (string) ( $bar->get_node( 'blog-1-translatewp' )->href ?? '' ), 'page=translatewp' ), 'a network administrator opens each Dashboard' );

// --- WordPress 6.0 has no current_user_can_for_site ---
foreach ( [ 'Functionality/AdminBar.php', 'Components/Access.php', 'Functionality/Admin/MultisiteSettingsPage.php' ] as $file ) {
	$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
	assert_true( ! str_contains( $source, 'current_user_can_for_site' ) && ! str_contains( $source, 'current_user_can_for_blog' ), "{$file} reads each site by switching to it, which every supported WordPress has" );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "AdminBarTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "AdminBarTest ok\n" );
