<?php

declare(strict_types=1);

// Each TranslateWP surface, with and without the grant. A granted editor
// reaches the translation settings, the Lexicon and the switcher settings and
// nothing else; the same editor without the grant reaches nothing; the
// connection, setup, usage, indexing, the export and the grant itself stay
// with administrators.

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

const ADMIN  = 1;
const EDITOR = 7;

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
		ADMIN  => [ 'manage_options', 'edit_pages' ],
		EDITOR => [ 'edit_pages' ],
	];
	$GLOBALS['wp_test_users']     = [
		(object) [ 'ID' => ADMIN, 'display_name' => 'Ada', 'user_email' => 'ada@example.com', 'roles' => [ 'administrator' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => EDITOR, 'display_name' => 'Ed', 'user_email' => 'ed@example.com', 'roles' => [ 'editor' ], 'blog_ids' => [ 1 ] ],
	];
}

function acting_as( int $user_id ): void {
	$GLOBALS['wp_test_current_user'] = $user_id;
	$GLOBALS['wp_test_caps']         = $GLOBALS['wp_test_user_caps'][ $user_id ];
}

/** Every REST route of the plugin's admin side, as "file route" => whether the current user is let in. */
function reachable_routes(): array {
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
			$reached[ $class . ' ' . $route->getMethod() . ' ' . $route->getPath() ] = true === $callback( $request );
		}
	}
	return $reached;
}

const GRANTED_ROUTES = [
	'Admin GET get-post-types',
	'Admin GET get-roles',
	'Admin GET get-data',
	'Admin POST update-data',
	'Admin POST create-lexicon-term',
	'Admin POST update-lexicon-term',
	'Admin POST delete-lexicon-term',
	'Admin GET get-lexicon-terms',
	'Admin GET settings-revision',
	'Admin POST switcher-position',
	'LexiconCandidates POST lexicon-candidates/scan',
	'LexiconCandidates GET lexicon-candidates/scan',
	'LexiconCandidates GET lexicon-candidates',
	'LexiconCandidates POST lexicon-candidates/action',
	'KeepWarmEndpoints GET stale-translations',
];

// --- an administrator reaches every route ---
seed();
acting_as( ADMIN );
$routes = reachable_routes();
assert_true( count( $routes ) > 40 && [] === array_keys( $routes, false, true ), 'an administrator reaches every admin route, missing ' . wp_json_encode( array_keys( $routes, false, true ) ) );

// --- an editor without the grant reaches none ---
acting_as( EDITOR );
assert_true( [] === array_keys( reachable_routes(), true, true ), 'an editor without the grant reaches no route, got ' . wp_json_encode( array_keys( reachable_routes(), true, true ) ) );

// --- a granted editor reaches the translation settings, the Lexicon and the switcher settings, and nothing else ---
acting_as( ADMIN );
Access::set_granted( [ EDITOR ] );
acting_as( EDITOR );
$reached = array_keys( reachable_routes(), true, true );
sort( $reached );
$expected = GRANTED_ROUTES;
sort( $expected );
assert_true( $expected === $reached, 'a granted editor reaches exactly the granted routes; extra ' . wp_json_encode( array_values( array_diff( $reached, $expected ) ) ) . ' missing ' . wp_json_encode( array_values( array_diff( $expected, $reached ) ) ) );
foreach ( [ 'Connect', 'Management', 'Quota', 'Indexing', 'Scanning', 'Transparency', 'AccessGrants' ] as $class ) {
	assert_true( [] === array_filter( $reached, static fn( string $route ): bool => 0 === strpos( $route, $class . ' ' ) ), "{$class} stays with administrators" );
}

// --- inside the settings routes, a granted editor is held to the settings the grant covers ---
$admin = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', TRANSLATEWP_VERSION );
$read  = static fn( string $option ) => $admin->get_data( new WP_REST_Request( 'GET', '', [ 'option' => $option ] ) );
$write = static fn( string $option, array $data ) => $admin->update_data( new WP_REST_Request( 'POST', '', [ 'option' => $option, 'data' => $data ] ) );

foreach ( [ 'translations.settings', 'translations.context', 'translations.style', 'languages.switcher', 'languages.list' ] as $option ) {
	assert_true( ! is_wp_error( $read( $option ) ), "a granted editor reads {$option}" );
}
foreach ( [ 'advanced', 'languages.seo', 'setup' ] as $option ) {
	$answer = $read( $option );
	assert_true( is_wp_error( $answer ) && 403 === ( $answer->get_error_data()['status'] ?? 0 ), "a granted editor does not read {$option}" );
}

assert_true( ! is_wp_error( $write( 'translations.settings', [ 'auto_translate' => false ] ) ) && false === Options::get( 'translations.settings.auto_translate', true ), 'a granted editor changes a translation setting' );
assert_true( ! is_wp_error( $write( 'languages.switcher', [ 'offset' => 40 ] ) ) && 40 === (int) Options::get( 'languages.switcher.offset', 0 ), 'a granted editor changes a switcher setting' );
foreach ( [ 'advanced' => [ 'delete_on_uninstall' => true ], 'languages.seo' => [ 'include_x_default' => false ], 'languages.list' => [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ] ] ] as $option => $data ) {
	$answer = $write( $option, $data );
	assert_true( is_wp_error( $answer ) && 403 === ( $answer->get_error_data()['status'] ?? 0 ), "a granted editor does not change {$option}" );
}
assert_true( true !== Options::get( 'advanced.delete_on_uninstall', false ), 'the delete-data rule is untouched by a granted editor' );
assert_true( 2 === count( (array) Options::get( 'languages.list', [] ) ), 'the languages are untouched by a granted editor' );

// --- the admin screen tells its app what the user is, and hands the export only to an administrator ---
$data = ( new \TranslateWP\Entities\Scripts\Admin( 'translatewp', TRANSLATEWP_VERSION ) )->context_data();
assert_true( false !== strpos( $data, '"can_administer":false' ) && false !== strpos( $data, '"export_url":""' ), 'a granted editor gets no administrator screen data, got ' . $data );
acting_as( ADMIN );
$data = ( new \TranslateWP\Entities\Scripts\Admin( 'translatewp', TRANSLATEWP_VERSION ) )->context_data();
assert_true( false !== strpos( $data, '"can_administer":true' ) && false === strpos( $data, '"export_url":""' ), 'an administrator gets the administrator screen data' );

// --- the menu: a granted editor gets the Lexicon and the Settings; an administrator gets everything ---
acting_as( EDITOR );
$GLOBALS['access_test_menus'] = [];
( new \TranslateWP\Functionality\Admin\Menus( 'translatewp', TRANSLATEWP_VERSION ) )->add_admin_menu();
$menus = $GLOBALS['access_test_menus'];
assert_true( [ 'translatewp' => Access::SETTINGS, 'translatewp-settings' => Access::SETTINGS, 'translatewp-lexicon' => Access::LEXICON ] === $menus, 'a granted editor gets the menu with the Settings, where it opens, and the Lexicon only, got ' . wp_json_encode( $menus ) );
assert_true( [ 'translatewp' ] === ( $GLOBALS['access_test_removed'] ?? [] ), 'the Dashboard entry is taken out of a granted editor\'s menu' );
$denied = false;
try {
	( new \TranslateWP\Functionality\Admin\Menus( 'translatewp', TRANSLATEWP_VERSION ) )->render_admin_page();
} catch ( \Throwable $refusal ) {
	$denied = true;
}
assert_true( $denied, 'the Dashboard opened by address is refused to a granted editor' );

acting_as( ADMIN );
$GLOBALS['access_test_menus'] = [];
( new \TranslateWP\Functionality\Admin\Menus( 'translatewp', TRANSLATEWP_VERSION ) )->add_admin_menu();
$menus = $GLOBALS['access_test_menus'];
assert_true( 'manage_options' === ( $menus['translatewp'] ?? '' ) && 'manage_options' === ( $menus['translatewp-seo'] ?? '' ), 'the Dashboard and SEO Tools stay with administrators, got ' . wp_json_encode( $menus ) );
assert_true( Access::LEXICON === ( $menus['translatewp-lexicon'] ?? '' ) && Access::SETTINGS === ( $menus['translatewp-settings'] ?? '' ), 'the Lexicon and the Settings ask for the capability administrators hold by default' );

// --- the grant itself: an administrator lists, grants and withdraws ---
$grants = new \TranslateWP\Functionality\Endpoints\AccessGrants( 'translatewp', TRANSLATEWP_VERSION );
$listed = $grants->managers( new WP_REST_Request( 'GET', '' ) );
assert_true( [ [ 'id' => EDITOR, 'name' => 'Ed', 'email' => 'ed@example.com', 'role' => 'editor', 'granted' => true ] ] === $listed['users'], 'the grant list names the users who can edit pages, got ' . wp_json_encode( $listed ) );
$saved = $grants->save_managers( new WP_REST_Request( 'POST', '', [ 'granted' => [] ] ) );
assert_true( false === $saved['users'][0]['granted'] && [] === Access::granted_ids(), 'an administrator withdraws the grant' );
acting_as( EDITOR );
assert_true( [] === array_keys( reachable_routes(), true, true ), 'after the grant is withdrawn the editor reaches nothing again' );
assert_true( is_wp_error( $grants->save_managers( new WP_REST_Request( 'POST', '', [ 'granted' => 'all' ] ) ) ), 'a malformed grant is refused' );

// --- the front end: the manager tools follow the same capability ---
$source = (string) file_get_contents( dirname( __DIR__ ) . '/Entities/Scripts/TranslateWP.php' );
assert_true( false !== strpos( $source, "+ self::manager_flags()" ) && false !== strpos( $source, "'canManage' => \$access::can_manage_translations()" ), 'the front-end manager flags follow the capabilities' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AccessSurfacesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AccessSurfacesTest: all assertions passed\n";
