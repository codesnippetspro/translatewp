<?php

declare(strict_types=1);

// Seeing translations is not a role matter: every visitor and every role is
// served, whatever an older role list stored in the settings says.

use TranslateWP\Components\Audience;
use TranslateWP\Components\Options;
use TranslateWP\Entities\Scripts\TranslateWP;

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

function ready_site( bool $pro, array $visible_roles, int $user_id, array $user_roles, array $caps = [] ): void {
	wp_test_reset( [
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
		'languages' => [ 'list' => [ [ 'locale' => 'en', 'default' => true, 'active' => true ], [ 'locale' => 'de', 'active' => true ] ] ],
		'setup'     => [ 'ended' => true ],
	] );
	Options::update( 'translations.settings', [ 'visible_roles' => $visible_roles ] );
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => $pro ? 'pro' : 'free', 'entitled' => $pro ], 3600 );
	wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => $pro );
	$GLOBALS['wp_test_current_user_id']    = $user_id;
	$GLOBALS['wp_test_current_user_roles'] = $user_roles;
	$GLOBALS['wp_test_caps']               = $caps;
}

// --- Pro, editors only ---
ready_site( true, [ 'editor' ], 0, [] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'A visitor is served whatever role list is stored' );
ready_site( true, [ 'editor' ], 7, [ 'subscriber' ] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'A subscriber is served whatever role list is stored' );
ready_site( true, [ 'editor' ], 7, [ 'editor' ] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'An editor is served' );
ready_site( true, [ 'editor' ], 7, [ 'subscriber' ], [ 'manage_options' ] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'Whoever manages the plugin is always served' );
assert_true( '' === TranslateWP::manager_setup_state(), 'A served site has no manager setup state' );

// --- Pro, no role chosen: everyone ---
ready_site( true, [], 0, [] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'An empty list serves everyone' );

// --- Free: the stored list has no effect ---
ready_site( false, [ 'editor' ], 0, [] );
assert_true( true === TranslateWP::visitor_runtime_ready(), 'Without Pro a stored role list is ignored' );

// --- a crawler is nobody's role: during a soft launch it gets the original page ---
ready_site( true, [ 'editor' ], 0, [] );
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
\TranslateWP\Components\LanguageUrl::set_requested_locale( 'de' );
$crawler_property = new ReflectionProperty( \TranslateWP\Functionality\CrawlerResponse::class, 'applies' );
$crawler_property->setAccessible( true );
$crawler_property->setValue( null, null );
assert_true( true === \TranslateWP\Functionality\CrawlerResponse::is_active(), 'A crawler is served a translated page whatever role list is stored' );
ready_site( true, [], 0, [] );
$crawler_property->setValue( null, null );
assert_true( true === \TranslateWP\Functionality\CrawlerResponse::is_active(), 'With no role restriction the same crawler request is served (as with one stored)' );
\TranslateWP\Components\LanguageUrl::set_requested_locale( '' );
unset( $_SERVER['HTTP_USER_AGENT'] );

// --- the helper itself ---
ready_site( true, [ 'editor', 'nope', 'author' ], 0, [] );
assert_true( [ 'editor', 'author' ] === Audience::visible_roles(), 'Unknown roles are dropped when read' );
assert_true( isset( Audience::role_names()['subscriber'] ), 'The site roles are offered by slug' );

// --- the admin app reads the roles from the plugin ---
ready_site( true, [], 0, [] );
$admin = new \TranslateWP\Functionality\Endpoints\Admin( 'translatewp', TRANSLATEWP_VERSION );
$roles = $admin->get_roles( null );
assert_true( is_array( $roles ) && 'Editor' === ( $roles['editor'] ?? null ), 'get-roles returns slug => name' );
$registered = array_map( static fn( $route ) => $route->getPath(), $admin->define_endpoints() );
assert_true( in_array( 'get-roles', $registered, true ), 'get-roles is a registered admin route' );

if ( $failures > 0 ) {
	fwrite( STDERR, "VisibleRolesTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "VisibleRolesTest: all assertions passed\n";
