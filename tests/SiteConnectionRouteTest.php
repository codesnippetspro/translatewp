<?php

declare(strict_types=1);

use PluboRoutes\Helpers\RegexHelperRoutes;
use PluboRoutes\Route\ActionRoute;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Functionality\Connect;

require dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) { unset( $key ); return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration ): bool { unset( $key, $value, $expiration ); return true; }
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) { unset( $parent, $slug ); return true; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		if ( is_string( $name ) && 0 === strpos( $name, 'translatewp_' ) ) {
			$key = substr( $name, strlen( 'translatewp_' ) );
			return $GLOBALS['wp_test_options_store'][ $key ] ?? $default;
		}
		return $default;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com:8443' . $path;
	}
}

$connect = new Connect( 'translatewp', '0.0.0-test' );
$routes  = $connect->register_routes( [] );

$return_route = null;

foreach ( $routes as $route ) {
	if ( $route instanceof ActionRoute && 'translatewp-connect-return' === $route->getName() ) {
		$return_route = $route;
		break;
	}
}

if ( ! $return_route instanceof ActionRoute ) {
	fwrite( STDERR, "Connect return ActionRoute not registered\n" );
	exit( 1 );
}

$return_url       = SiteConnection::return_url();
$advertised_path  = SiteConnection::advertised_return_path_from_url( $return_url );
$request_path     = SiteConnection::return_request_path_from_url( $return_url );
$registered_path  = $return_route->getPath();
$compiled_path    = ( new RegexHelperRoutes() )->cleanPath( $registered_path );
$compiled_regex   = '^' . $compiled_path . '$';

if ( 'translatewp-connect/return/' !== $advertised_path ) {
	fwrite( STDERR, "Advertised return URL path must keep trailing slash: {$advertised_path}\n" );
	exit( 1 );
}

if ( SiteConnection::RETURN_ROUTE_PATH !== $registered_path ) {
	fwrite( STDERR, "Registered route path must omit trailing slash: {$registered_path}\n" );
	exit( 1 );
}

if ( 1 !== preg_match( '#' . $compiled_regex . '#', $request_path ) ) {
	fwrite(
		STDERR,
		"Compiled regex {$compiled_regex} does not match WP-trimmed request path {$request_path}\n"
	);
	exit( 1 );
}

$host = SiteConnection::connect_host_from_return_url( $return_url );

if ( 'example.com:8443' !== $host ) {
	fwrite( STDERR, "Expected host:port from return URL, got {$host}\n" );
	exit( 1 );
}

if ( SiteConnection::connect_host() !== $host ) {
	fwrite( STDERR, "connect_host() must match host derived from return_url()\n" );
	exit( 1 );
}

$GLOBALS['wp_test_submenu_slugs'] = [];
$GLOBALS['wp_test_options_store']['setup'] = [ 'ended' => true ];
$GLOBALS['wp_test_options_store']['languages'] = [ 'list' => [ 'nl' => [ 'locale' => 'nl', 'slug' => 'nl', 'active' => true, 'default' => true ] ] ];
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $a, $b, $c, $d, $e, $f = '', $g = null ) { unset( $a, $b, $c, $d, $e, $f, $g ); return true; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $a, $b, $c, $d, $e, $f = null ) { $GLOBALS['wp_test_submenu_slugs'][] = $e; return true; }
}
if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent, $slug ) { unset( $parent, $slug ); return true; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return $text; }
}
$menus = new TranslateWP\Functionality\Admin\Menus( 'translatewp', '0.0.0-test' );
$menus->add_admin_menu();
if ( ! in_array( 'translatewp-settings', $GLOBALS['wp_test_submenu_slugs'], true ) ) {
	fwrite( STDERR, "Settings submenu slug must be registered\n" );
	exit( 1 );
}

fwrite( STDOUT, "SiteConnectionRouteTest ok\n" );
