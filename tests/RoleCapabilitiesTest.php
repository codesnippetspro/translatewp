<?php

declare(strict_types=1);

// Who can manage translations, per role: four capabilities a site admin gives
// per role, on top of the per-user grant, with network defaults;
// administrators hold all four; visitors see translations whatever is set.

use TranslateWP\Components\Access;
use TranslateWP\Components\Options;

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

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $slug ) {
		$GLOBALS['access_test_menus'][ $slug ] = $capability;
	}
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) {
		$GLOBALS['access_test_removed'][] = $slug;
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug ) {
		$GLOBALS['access_test_menus'][ $slug ] = $capability;
	}
}

const ADMIN    = 1;
const EDITOR   = 7;
const AUTHOR   = 8;
const SUBSCRIB = 9;
const NAMED    = 10;

function seed(): void {
	wp_test_reset(
		[
			'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
			'setup'     => [ 'ended' => true ],
			'languages' => [
				'list'     => [
					'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
					'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				],
				'switcher' => [ 'offset' => 24 ],
			],
		]
	);
	$GLOBALS['wp_test_user_caps'] = [
		ADMIN    => [ 'manage_options', 'edit_pages' ],
		EDITOR   => [ 'edit_pages' ],
		AUTHOR   => [ 'edit_posts' ],
		SUBSCRIB => [ 'read' ],
		NAMED    => [ 'edit_pages' ],
	];
	$GLOBALS['wp_test_users']     = [
		(object) [ 'ID' => ADMIN, 'display_name' => 'Ada', 'user_email' => 'ada@example.com', 'roles' => [ 'administrator' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => EDITOR, 'display_name' => 'Ed', 'user_email' => 'ed@example.com', 'roles' => [ 'editor' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => AUTHOR, 'display_name' => 'Aud', 'user_email' => 'aud@example.com', 'roles' => [ 'author' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => SUBSCRIB, 'display_name' => 'Sue', 'user_email' => 'sue@example.com', 'roles' => [ 'subscriber' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => NAMED, 'display_name' => 'Nia', 'user_email' => 'nia@example.com', 'roles' => [ 'shop_manager' ], 'blog_ids' => [ 1 ] ],
	];
}

function acting_as( int $user_id ): void {
	$GLOBALS['wp_test_current_user']       = $user_id;
	$GLOBALS['wp_test_current_user_id']    = $user_id;
	$GLOBALS['wp_test_caps']               = $GLOBALS['wp_test_user_caps'][ $user_id ];
	foreach ( $GLOBALS['wp_test_users'] as $user ) {
		if ( $user->ID === $user_id ) {
			$GLOBALS['wp_test_current_user_roles'] = $user->roles;
		}
	}
}

/**
 * Gives the editor role exactly the listed capabilities on this site.
 *
 * @param string[] $keys Capability keys of the role map.
 */
function editor_holds( array $keys ): void {
	$map = [];
	foreach ( [ 'edit_translations', 'retranslate', 'manage_settings', 'manage_lexicon' ] as $key ) {
		$map[ $key ] = [ 'editor' => in_array( $key, $keys, true ) ];
	}
	Access::set_role_map( $map );
}

/** Every permission-checked admin route, as "Class METHOD path" => whether the current user is let in. */
function reachable(): array {
	$request = new WP_REST_Request( 'POST', '', [], [ 'X-WP-Nonce' => 'test-nonce' ] );
	$reached = [];
	foreach ( [ 'Admin', 'LexiconCandidates', 'KeepWarmEndpoints', 'AccessGrants', 'Connect', 'Management', 'Quota', 'Indexing', 'Scanning', 'Transparency' ] as $class ) {
		$name     = '\\TranslateWP\\Functionality\\Endpoints\\' . $class;
		$endpoint = new $name( 'translatewp', TRANSLATEWP_VERSION );
		foreach ( $endpoint->define_endpoints() as $route ) {
			$callback = $route->getPermissionCallback();
			// Routes with their own signed or public check are not admin surfaces.
			if ( ! is_array( $callback ) || 1 !== preg_match( '/^(admin|translations|edit|retranslate|settings|lexicon|lexicon_or_edit|settings_data)_(write_)?permissions_check$/', (string) $callback[1] ) ) {
				continue;
			}
			if ( true === $callback( $request ) ) {
				$reached[] = $class . ' ' . $route->getMethod() . ' ' . $route->getPath();
			}
		}
	}
	sort( $reached );
	return $reached;
}

/**
 * The listed routes, sorted, for comparing with reachable().
 *
 * @param string[] ...$sets Lists of routes.
 */
function routes( array ...$sets ): array {
	$all = array_values( array_unique( array_merge( ...$sets ) ) );
	sort( $all );
	return $all;
}

const EDIT_ROUTES        = [ 'Admin POST create-lexicon-term', 'Admin GET get-lexicon-terms', 'Admin GET get-lexicon-categories' ];
const RETRANSLATE_ROUTES = [ 'Admin GET retranslation-estimate', 'Indexing POST retranslate-page', 'Indexing POST retranslate-all', 'KeepWarmEndpoints POST apply-lexicon-now', 'KeepWarmEndpoints POST apply-localizer-now' ];
const SETTINGS_ROUTES    = [ 'Admin GET get-post-types', 'Admin GET get-roles', 'Admin GET get-data', 'Admin POST update-data', 'Admin GET settings-revision', 'Admin POST switcher-position', 'KeepWarmEndpoints GET stale-translations' ];
const LEXICON_ROUTES     = [ 'Admin POST create-lexicon-term', 'Admin POST update-lexicon-term', 'Admin POST delete-lexicon-term', 'Admin GET get-lexicon-terms', 'Admin GET get-lexicon-categories', 'Admin GET get-data', 'KeepWarmEndpoints GET stale-translations', 'LexiconCandidates POST lexicon-candidates/scan', 'LexiconCandidates GET lexicon-candidates/scan', 'LexiconCandidates GET lexicon-candidates', 'LexiconCandidates POST lexicon-candidates/action' ];

/**
 * Whether the current user may read a settings path through get-data.
 *
 * @param string $path The option path.
 */
function reads( string $path ): bool {
	$admin  = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', TRANSLATEWP_VERSION );
	$result = $admin->get_data( new WP_REST_Request( 'GET', '', [ 'option' => $path ] ) );
	return ! is_wp_error( $result );
}

/**
 * Whether the current user may write a settings path through update-data.
 *
 * @param string $path The option path.
 * @param mixed  $data The value.
 */
function writes( string $path, $data ): bool {
	$admin  = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', TRANSLATEWP_VERSION );
	$result = $admin->update_data( new WP_REST_Request( 'POST', '', [ 'option' => $path, 'data' => $data ] ) );
	return ! is_wp_error( $result );
}

function menus(): array {
	$GLOBALS['access_test_menus']   = [];
	$GLOBALS['access_test_removed'] = [];
	( new \TranslateWP\Functionality\Admin\Menus( 'translatewp', TRANSLATEWP_VERSION ) )->add_admin_menu();
	$menus = $GLOBALS['access_test_menus'];
	// WordPress shows an entry only to a user holding its capability.
	$shown = array_filter( $menus, static fn( $cap ) => in_array( $cap, Access::CAPABILITIES, true ) ? Access::can( $cap ) : current_user_can( (string) $cap ) );
	return array_keys( $shown );
}

function page_flags(): array {
	$data = \TranslateWP\Entities\Scripts\TranslateWP::manager_flags();
	return [ $data['can'], $data['canManage'] ];
}

// --- administrators hold all four with nothing stored ---
seed();
acting_as( ADMIN );
foreach ( Access::CAPABILITIES as $cap ) {
	assert_true( Access::can( $cap ), "an administrator holds {$cap} with nothing stored" );
}
assert_true( Access::can_manage_translations(), 'an administrator is a full manager' );
assert_true( [ [ 'edit' => true, 'retranslate' => true, 'settings' => true, 'lexicon' => true ], true ] === page_flags(), 'an administrator gets every page flag' );

// --- an editor holds nothing until a capability is given to the role ---
acting_as( EDITOR );
assert_true( [] === reachable(), 'an editor with nothing given reaches no route' );
assert_true( [] === menus(), 'an editor with nothing given has no menu' );
assert_true( [ [ 'edit' => false, 'retranslate' => false, 'settings' => false, 'lexicon' => false ], false ] === page_flags(), 'an editor with nothing given has no page flag' );

// --- Edit translations only ---
editor_holds( [ 'edit_translations' ] );
assert_true( routes( EDIT_ROUTES ) === reachable(), 'Edit only reaches Add to Lexicon and the term and category lists, got ' . wp_json_encode( reachable() ) );
assert_true( [] === menus(), 'Edit only has no admin menu' );
assert_true( [ [ 'edit' => true, 'retranslate' => false, 'settings' => false, 'lexicon' => false ], false ] === page_flags(), 'Edit only: the edit flag, and canManage stays false' );
assert_true( ! in_array( 'Admin GET get-data', reachable(), true ) && ! in_array( 'Admin POST update-data', reachable(), true ), 'Edit only reaches no settings data' );

// --- Retranslate only ---
editor_holds( [ 'retranslate' ] );
assert_true( routes( RETRANSLATE_ROUTES ) === reachable(), 'Retranslate only reaches the retranslation routes, got ' . wp_json_encode( reachable() ) );
assert_true( [] === menus(), 'Retranslate only has no admin menu' );
assert_true( [ [ 'edit' => false, 'retranslate' => true, 'settings' => false, 'lexicon' => false ], false ] === page_flags(), 'Retranslate only: the retranslate flag' );

// --- Manage settings only ---
editor_holds( [ 'manage_settings' ] );
assert_true( routes( SETTINGS_ROUTES ) === reachable(), 'Settings only reaches the settings routes, got ' . wp_json_encode( reachable() ) );
assert_true( [ 'translatewp', 'translatewp-settings' ] === menus(), 'Settings only gets the menu with Settings, got ' . wp_json_encode( menus() ) );
assert_true( reads( 'translations.settings' ) && reads( 'translations.style' ) && reads( 'languages.switcher' ) && reads( 'languages.list' ), 'Settings reads the delegated settings and the language list' );
assert_true( writes( 'translations.style', [ 'tone' => 'friendly' ] ) && writes( 'languages.switcher', [ 'show_flag' => true ] ), 'Settings writes style (it retranslates; still Manage settings) and the switcher' );
assert_true( ! writes( 'languages.list', [] ) && ! writes( 'languages.seo', [ 'sitemaps' => false ] ) && ! writes( 'advanced', [ 'delete_on_uninstall' => true ] ), 'Settings writes neither the language list, SEO nor Advanced' );
assert_true( ! reads( 'advanced' ) && ! reads( 'site' ), 'Settings reads neither Advanced nor the connection' );

// --- Manage the Lexicon only ---
editor_holds( [ 'manage_lexicon' ] );
assert_true( routes( LEXICON_ROUTES ) === reachable(), 'Lexicon only reaches the Lexicon routes, got ' . wp_json_encode( reachable() ) );
assert_true( [ 'translatewp', 'translatewp-lexicon' ] === menus(), 'Lexicon only gets the menu with the Lexicon, got ' . wp_json_encode( menus() ) );
assert_true( reads( 'languages.list' ) && ! reads( 'translations.settings' ) && ! writes( 'translations.settings', [] ), 'Lexicon reads the language list and no settings' );

// --- the full manager the older bundles expect ---
editor_holds( [ 'edit_translations', 'manage_settings', 'retranslate' ] );
assert_true( Access::can_manage_translations() && true === page_flags()[1], 'Edit, Settings and Retranslate together make a full manager' );
foreach ( [ [ 'edit_translations', 'manage_settings' ], [ 'edit_translations', 'retranslate' ], [ 'manage_settings', 'retranslate', 'manage_lexicon' ] ] as $partial ) {
	editor_holds( $partial );
	assert_true( ! Access::can_manage_translations() && false === page_flags()[1], 'a partial set is not a full manager: ' . implode( ',', $partial ) );
}
$legacy = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === $legacy->translations_permissions_check(), 'the legacy check older Pro routes call refuses a partial set' );

// --- other roles are untouched by the editor's map ---
acting_as( AUTHOR );
assert_true( [] === reachable(), 'an author holds nothing the editor role was given' );

// --- the named-user grant: settings and Lexicon only ---
seed();
Access::set_granted( [ NAMED ] );
acting_as( NAMED );
assert_true( Access::can( Access::SETTINGS ) && Access::can( Access::LEXICON ), 'a named user manages settings and the Lexicon' );
assert_true( ! Access::can( Access::EDIT ) && ! Access::can( Access::RETRANSLATE ), 'a named user neither edits translations nor retranslates' );
assert_true( [ [ 'edit' => false, 'retranslate' => false, 'settings' => true, 'lexicon' => true ], false ] === page_flags(), 'a named user: settings and Lexicon flags, canManage false' );
assert_true( [ 'translatewp', 'translatewp-settings', 'translatewp-lexicon' ] === menus(), 'a named user gets Settings, where the menu opens, then the Lexicon' );

// --- sanitizing the role map ---
seed();
acting_as( ADMIN );
$kept = Access::set_role_map( [ 'edit_translations' => [ 'editor' => true, 'administrator' => true, 'ghost' => true, 'author' => 'yes' ], 'fly' => [ 'editor' => true ] ] );
assert_true( [ 'edit_translations' => [ 'editor' => true, 'author' => true ] ] === $kept, 'unknown roles, the administrator and unknown keys are dropped, got ' . wp_json_encode( $kept ) );

// --- the role card: administrators only ---
$grants = new \TranslateWP\Functionality\Endpoints\AccessGrants( 'translatewp', TRANSLATEWP_VERSION );
$saved  = $grants->save_managers( new WP_REST_Request( 'POST', '', [ 'roles' => [ 'manage_settings' => [ 'author' => true ] ] ] ) );
assert_true( true === ( Access::role_map()['manage_settings']['author'] ?? null ) && isset( $saved['roles'] ), 'an administrator saves the role map with the card' );
acting_as( EDITOR );
editor_holds( [ 'manage_settings', 'manage_lexicon', 'edit_translations', 'retranslate' ] );
assert_true( ! in_array( 'AccessGrants GET translation-managers', reachable(), true ) && ! in_array( 'AccessGrants POST translation-managers', reachable(), true ), 'the card stays with administrators even for a role holding all four' );

// --- visitors see translations whatever is stored ---
seed();
Options::update( 'translations.settings', [ 'visible_roles' => [ 'editor' ] ] );
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => true );
foreach ( [ SUBSCRIB, AUTHOR ] as $user ) {
	acting_as( $user );
	assert_true( \TranslateWP\Components\Audience::allows_current_user(), 'every role sees translations whatever visible_roles holds' );
}
$GLOBALS['wp_test_current_user_id'] = 0;
$GLOBALS['wp_test_current_user_roles'] = [];
$GLOBALS['wp_test_caps'] = [];
assert_true( \TranslateWP\Components\Audience::allows_current_user(), 'a signed-out visitor sees translations' );


// --- network defaults with a site's own value and a lock ---
/**
 * A network of three sites with the editor role mapped on the network.
 *
 * @param array $network The network default role map.
 */
function network( array $network ): void {
	seed();
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 2;
	$GLOBALS['wp_test_super_admin']  = false;
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://b.example.com' ],
	];
	update_site_option( 'translatewp_network_settings_defaults', [ Access::ROLE_MAP_OPTION => $network ] );
}

network( [ 'manage_settings' => [ 'editor' => true ] ] );
acting_as( EDITOR );
assert_true( Access::can( Access::SETTINGS ), 'a network default reaches a site in its default state' );
$GLOBALS['wp_test_options_store']['blog_2_access'] = [ 'manage_settings' => [ 'editor' => false ] ];
assert_true( ! Access::can( Access::SETTINGS ), "a site's own false overrides the network's true" );
network( [ 'manage_lexicon' => [ 'editor' => false ] ] );
$GLOBALS['wp_test_options_store']['blog_2_access'] = [ 'manage_lexicon' => [ 'editor' => true ] ];
acting_as( EDITOR );
assert_true( Access::can( Access::LEXICON ), "a site's own true overrides the network's false" );
$GLOBALS['wp_test_current_blog'] = 3;
assert_true( ! Access::can( Access::LEXICON ), "site 2's map grants nothing on site 3" );

network( [ 'manage_settings' => [ 'editor' => true ] ] );
\TranslateWP\Components\SettingsScope::set_group_state( 'role_permissions', \TranslateWP\Components\SettingsScope::STATE_LOCKED );
$GLOBALS['wp_test_options_store']['blog_2_access'] = [ 'manage_settings' => [ 'editor' => false ] ];
acting_as( EDITOR );
assert_true( Access::can( Access::SETTINGS ), 'a locked network value wins over the site' );
acting_as( ADMIN );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$locked = ( new \TranslateWP\Functionality\Endpoints\AccessGrants( 'translatewp', TRANSLATEWP_VERSION ) )->save_managers( new WP_REST_Request( 'POST', '', [ 'roles' => [ 'manage_settings' => [ 'editor' => false ] ] ] ) );
assert_true( is_wp_error( $locked ), 'a site admin cannot change a locked role map' );

network( [] );
Access::set_granted( [ NAMED ] );
acting_as( NAMED );
assert_true( Access::can( Access::SETTINGS ), 'a named grant holds on its own site' );
$GLOBALS['wp_test_current_blog'] = 3;
assert_true( ! Access::can( Access::SETTINGS ), 'a named grant never reaches another site' );

// --- the network page writes full maps ---
network( [] );
$GLOBALS['wp_test_current_blog'] = 1;
$GLOBALS['wp_test_super_admin']  = true;
$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
$saved = \TranslateWP\Components\NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'role_permissions', 'apply_to' => 'all', 'values' => [ 'access.manage_settings' => [ 'editor' ] ] ] );
$defaults = get_site_option( 'translatewp_network_settings_defaults', [] );
$written  = $defaults[ Access::ROLE_MAP_OPTION ]['manage_settings'] ?? null;
assert_true( 'scope-saved' === $saved && true === ( $written['editor'] ?? null ) && false === ( $written['author'] ?? null ) && ! isset( $written['administrator'] ), 'the network field writes a full map, ticked true and the other roles false, got ' . wp_json_encode( $written ) );
assert_true( null === \TranslateWP\Components\SettingsScope::group_for( 'translatewp_translations', 'settings', 'visible_roles' ) || 'audience' !== \TranslateWP\Components\SettingsScope::group_for( 'translatewp_translations', 'settings', 'visible_roles' ), 'the audience group is gone' );


// --- the page on screen, for retranslating it from edit mode ---
seed();
acting_as( EDITOR );
editor_holds( [ 'retranslate' ] );
$GLOBALS['wp_test_is_singular']         = true;
$GLOBALS['wp_test_queried_object_id']   = 12;
$GLOBALS['wp_test_permalinks']          = [ 12 => 'https://example.com/about/' ];
assert_true( [ 'type' => 'page', 'id' => 12, 'url' => 'https://example.com/about/' ] === \TranslateWP\Entities\Scripts\TranslateWP::page_object(), 'a user who retranslates gets the single page on screen' );
$GLOBALS['wp_test_is_singular'] = false;
assert_true( null === \TranslateWP\Entities\Scripts\TranslateWP::page_object(), 'no page object off a single post or page' );
$GLOBALS['wp_test_is_singular'] = true;
editor_holds( [ 'edit_translations' ] );
assert_true( null === \TranslateWP\Entities\Scripts\TranslateWP::page_object(), 'no page object for a user who does not retranslate' );
$GLOBALS['wp_test_is_singular'] = false;

if ( $failures > 0 ) {
	fwrite( STDERR, "RoleCapabilitiesTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "RoleCapabilitiesTest: all assertions passed\n";
