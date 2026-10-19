<?php

declare(strict_types=1);

use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Entities\Scripts\NetworkAdmin;
use TranslateWP\Entities\Styles\FrontendShared;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$connected_options = [
	'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'secret-site-token' ],
	'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ],
];

// --- Connected + setup ended + languages: bundle enqueues ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $script->conditions(), 'Connected site with setup ended must enqueue the public bundle' );

// --- Connected + wizard restarted + languages: bundle keeps enqueuing ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => false, 'restarted' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $script->conditions(), 'A connected site with the wizard restarted must keep enqueuing the public bundle' );

// --- Unconnected: bundle must NOT enqueue ---
wp_test_reset( [ 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ] ] );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === $script->conditions(), 'Unconnected site must not enqueue the public bundle' );

// --- Setup not ended: bundle must NOT enqueue ---
wp_test_reset( $connected_options );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === $script->conditions(), 'Setup not ended must not enqueue the public bundle' );

// --- Frontend context carries the connected flag ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$data   = $script->frontend_data();
assert_true( false !== strpos( $data, '"connected":true' ), 'Frontend context must carry connected=true, got ' . $data );

wp_test_reset( [ 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ] ] );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$data   = $script->frontend_data();
assert_true( false !== strpos( $data, '"connected":false' ), 'Frontend context must carry connected=false, got ' . $data );

// --- Every visitor learns whether the site is Orbital, nothing more of the Pro state ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$GLOBALS['wp_test_caps'] = [];
$data = ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->frontend_data();
assert_true( false !== strpos( $data, '"orbital":false' ) && false === strpos( $data, '"pro":' ), 'A Terrestrial site tells visitors orbital=false and no Pro details, got ' . $data );
set_transient( 'translatewp_pro_eligibility', [ 'entitled' => true, 'plan' => 'orbital' ], 60 );
$data = ( new TranslateWP( 'translatewp', TRANSLATEWP_VERSION ) )->frontend_data();
assert_true( false !== strpos( $data, '"orbital":true' ) && false === strpos( $data, '"pro":' ), 'An Orbital site tells visitors orbital=true and no Pro details, got ' . $data );

// --- Frontend context carries the language the address names ---
wp_test_reset( $connected_options + [] );
\TranslateWP\Components\Options::update( 'languages.list', [
	'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
	'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
] );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$script = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false !== strpos( $script->frontend_data(), '"requested_locale":""' ), 'A plain address names no translation language' );
\TranslateWP\Components\LanguageUrl::set_requested_locale( 'de' );
assert_true( false !== strpos( $script->frontend_data(), '"requested_locale":"de"' ), 'An address in the German folder tells the runtime so, got ' . $script->frontend_data() );
\TranslateWP\Components\LanguageUrl::set_requested_locale( '' );

// --- Frontend context carries canManage from current_user_can(manage_options) ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$GLOBALS['wp_test_caps'] = [];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$data                    = $script->frontend_data();
assert_true( false !== strpos( $data, '"canManage":false' ), 'A visitor must carry canManage=false, got ' . $data );

update_option( 'translatewp_settings_revision', 7 );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$data                    = $script->frontend_data();
assert_true( false !== strpos( $data, '"canManage":true' ), 'A user who can manage_options must carry canManage=true, got ' . $data );
assert_true( false !== strpos( $data, '"settings_revision":7' ), 'A manager receives the settings revision' );
assert_true( false !== strpos( $data, '"pro":{"entitled":false' ), 'A manager receives the pro entitlement, got ' . $data );
assert_true( false !== strpos( $data, '"upgrade_url":"' ), 'A manager receives an upgrade url, got ' . $data );
assert_true( false !== strpos( $data, '"installed":false' ), 'A manager receives whether Pro is installed, got ' . $data );
assert_true( false !== strpos( $data, '"active":false' ), 'A manager receives whether Pro is active, got ' . $data );
assert_true( false !== strpos( $data, '"install_url":"' ) && false !== strpos( $data, 'admin.php?page=translatewp"' ), 'A manager receives the install url to the TranslateWP Dashboard, got ' . $data );
$GLOBALS['wp_test_caps'] = [];
$data = $script->frontend_data();
assert_true( false === strpos( $data, 'settings_revision' ), 'A visitor receives no settings revision' );
assert_true( false === strpos( $data, '"pro"' ), 'A visitor receives no pro entitlement, got ' . $data );
assert_true( false === strpos( $data, 'install_url' ) && false === strpos( $data, '"installed"' ), 'A visitor receives no Pro install state, got ' . $data );
\TranslateWP\Components\Options::update( 'languages.switcher.floating_position', [ 'anchor' => 'bottom-left', 'x' => 10, 'y' => 20 ] );
$data = $script->get_frontend_options();
assert_true( 'bottom-left' === $data['languages']['switcher']['floating_position']['anchor'], 'A visitor receives the saved switcher position' );

// --- The shared front-end stylesheet follows the visitor bundle's gate ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$style = new FrontendShared( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $style->conditions(), 'A connected, set-up site must enqueue the shared front-end stylesheet' );

wp_test_reset( [ 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ] ] );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$style = new FrontendShared( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === $style->conditions(), 'An unpaired site must not send visitors the shared front-end stylesheet' );

wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'secret-site-token' ] ] );
$style = new FrontendShared( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === $style->conditions(), 'A site that never finished setup must not send visitors the shared front-end stylesheet' );

// --- An unconfigured site: a manager gets the bundle with a setup notice, a visitor gets nothing ---
$unconfigured = [];
wp_test_reset( $unconfigured );
$GLOBALS['wp_test_caps'] = [];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
$style                   = new FrontendShared( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === $script->conditions() && false === $style->conditions(), 'A visitor on an unconfigured site must be sent no bundle and no stylesheet' );
assert_true( false === strpos( $script->frontend_data(), 'setup_notice' ), 'A visitor must never receive the setup notice' );

$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
assert_true( true === $script->conditions() && true === $style->conditions(), 'A manager on an unconfigured site must get the bundle and its stylesheet' );
$data = $script->frontend_data();
assert_true( false !== strpos( $data, '"setup_notice":{"state":"setup"' ), 'A manager on an unconfigured site receives the setup notice, got ' . $data );
assert_true( false !== strpos( $data, 'admin.php?page=translatewp"' ), 'The setup notice links to onboarding, got ' . $data );
ob_start();
$script->browser_notranslate_meta();
assert_true( '' === (string) ob_get_clean(), 'A site that translates nothing must not tell browsers to stop translating' );

// --- A disconnected site that finished setup: the notice links to reconnect ---
$disconnected = [ 'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ] ];
wp_test_reset( $disconnected );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $script->conditions(), 'A manager on a disconnected site must get the bundle' );
$data = $script->frontend_data();
assert_true( false !== strpos( $data, '"setup_notice":{"state":"reconnect"' ), 'A manager on a disconnected site receives the reconnect notice, got ' . $data );
assert_true( false !== strpos( $data, 'admin.php?page=translatewp-settings#connection"' ), 'The reconnect notice links to Settings > Connection, got ' . $data );
$GLOBALS['wp_test_caps'] = [];
assert_true( false === $script->conditions(), 'A visitor on a disconnected site must be sent no bundle' );

// --- A manager sees the translated site before setup ends, once languages and credentials exist ---
$previewable = [
	'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'secret-site-token' ],
	'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ], 'de' => [ 'locale' => 'de', 'active' => true, 'default' => false ] ] ],
];
wp_test_reset( $previewable );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $script->conditions(), 'A manager during setup gets the bundle' );
assert_true( false === strpos( $script->frontend_data(), 'setup_notice' ), 'The manager carries no setup notice, so the runtime translates the page' );

$GLOBALS['wp_test_caps'] = [];
assert_true( false === $script->conditions(), 'A visitor is served nothing before setup ends' );

wp_test_reset( [ 'languages' => $previewable['languages'] ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false !== strpos( $script->frontend_data(), 'setup_notice' ), 'A site with no credentials shows the manager the setup notice instead' );

// --- A working site carries no setup notice, for a manager either ---
wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$script                  = new TranslateWP( 'translatewp', TRANSLATEWP_VERSION );
assert_true( false === strpos( $script->frontend_data(), 'setup_notice' ), 'A working site carries no setup notice' );

// --- Network bundle only loads on its top-level Network Admin screen ---
wp_test_reset( [] );
$GLOBALS['wp_test_multisite']     = true;
$GLOBALS['wp_test_network_admin'] = true;
$GLOBALS['wp_test_caps']          = [ 'manage_network_options' ];
$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => 'toplevel_page_translatewp-network-settings-network' ];
$network_script = new NetworkAdmin( 'translatewp', TRANSLATEWP_VERSION );
assert_true( true === $network_script->conditions(), 'Network bundle must load on the TranslateWP Network Admin screen' );

$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => 'settings_page_translatewp-network-settings' ];
assert_true( false === $network_script->conditions(), 'Network bundle must not preserve the former Settings screen alias' );
$GLOBALS['wp_test_current_screen'] = (object) [ 'id' => 'toplevel_page_translatewp-network-settings' ];
$GLOBALS['wp_test_caps'] = [];
assert_true( false === $network_script->conditions(), 'Network bundle must require network settings capability' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ScriptConditionsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ScriptConditionsTest: all assertions passed\n";
