<?php

declare(strict_types=1);

use TranslateWP\Components\PluginCoexistence;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$message}\n";
	}
}

// Each of the seven named plugins is detected when its signal is present.
$expected = [ 'WPML', 'Polylang', 'TranslatePress', 'Weglot', 'GTranslate', 'Loco Translate', 'MultilingualPress' ];
foreach ( $expected as $name ) {
	$detected = PluginCoexistence::detected(
		static function ( array $plugin ) use ( $name ): bool {
			return $plugin['name'] === $name;
		}
	);
	assert_true( [ $name ] === $detected, "only {$name} detected when only its signal is present" );
}

// Two active plugins are both named, in list order.
$two = PluginCoexistence::detected(
	static function ( array $plugin ): bool {
		return in_array( $plugin['name'], [ 'Weglot', 'WPML' ], true );
	}
);
assert_true( [ 'WPML', 'Weglot' ] === $two, 'both active plugins are detected in list order' );

// The no-warning case: nothing active, nothing detected, no message.
$none = PluginCoexistence::detected(
	static function (): bool {
		return false;
	}
);
assert_true( [] === $none, 'no plugin detected when no signal is present' );

// The real signal path: a defined constant marks its plugin active.
if ( ! defined( 'POLYLANG_VERSION' ) ) {
	define( 'POLYLANG_VERSION', '3.0' );
}
assert_true( in_array( 'Polylang', PluginCoexistence::detected(), true ), 'a defined plugin constant is detected by the real signal check' );
assert_true( ! in_array( 'WPML', PluginCoexistence::detected(), true ), 'a plugin whose signal is absent is not detected' );

// Loco Translate declares neither a version constant nor a loader class; its
// plugin functions are the signal it always loads.
if ( ! function_exists( 'loco_plugin_version' ) ) {
	function loco_plugin_version(): string {
		return '2.8.0';
	}
}
assert_true( in_array( 'Loco Translate', PluginCoexistence::detected(), true ), 'Loco Translate is detected by the function it declares' );

// The message names the plugins and states the coexistence risks.
$single = PluginCoexistence::message( [ 'WPML' ] );
assert_true( str_contains( $single, 'WPML' ), 'the message names the detected plugin' );
assert_true( str_contains( $single, 'mixed languages' ), 'the message states the mixed-languages risk' );
assert_true( str_contains( $single, 'keeps working' ), 'the message says TranslateWP keeps working' );

$multi = PluginCoexistence::message( [ 'WPML', 'Polylang' ] );
assert_true( str_contains( $multi, 'WPML, Polylang' ), 'the message names every detected plugin' );

// The guidance link opens the plugin's own explanation in Settings > Advanced.
assert_true( 'https://example.com/wp-admin/admin.php?page=translatewp-settings#advanced/other-translation-plugins' === PluginCoexistence::guidance_url(), 'the guidance link opens Settings > Advanced, where other translation plugins are explained, got ' . PluginCoexistence::guidance_url() );

// The notice can be dismissed for good, per user, until the set of detected plugins changes.
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$_GET                    = [ 'page' => 'translatewp' ];
$render                  = static function (): string {
	ob_start();
	PluginCoexistence::render_admin_notice();
	return (string) ob_get_clean();
};
$shown = $render();
assert_true( str_contains( $shown, 'Polylang' ), 'the notice is shown while not dismissed' );
assert_true( str_contains( $shown, 'data-translatewp-dismiss="' . PluginCoexistence::DISMISS_ACTION . '"' ), 'the notice X is saved on the server through its dismiss action' );
assert_true( str_contains( $shown, 'is-dismissible' ), 'the notice carries the WordPress X' );
PluginCoexistence::dismiss();
assert_true( '' === $render(), 'a dismissed notice stays hidden on the next page load' );
$GLOBALS['wp_test_current_user'] = 2;
assert_true( str_contains( $render(), 'Polylang' ), 'a dismissal belongs to the user who made it' );
$GLOBALS['wp_test_current_user'] = 1;
$GLOBALS['wp_test_user_meta'][1]['translatewp_coexistence_dismissed'] = PluginCoexistence::fingerprint( [ 'WPML' ] );
assert_true( str_contains( $render(), 'Polylang' ), 'the notice returns when the set of detected plugins changes' );
$_GET = [];

// --- One-click deactivation of the other plugin ---
if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( $plugins ): void {
		foreach ( (array) $plugins as $plugin ) {
			$GLOBALS['wp_test_deactivated_plugins'][] = $plugin;
		}
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', [] ), (array) $plugins ) ) );
	}
}

$GLOBALS['wp_test_user_meta']           = [];
$GLOBALS['wp_test_deactivated_plugins'] = [];
update_option( 'active_plugins', [ 'translate/translatewp.php', 'polylang/polylang.php', 'akismet/akismet.php' ] );

// The active plugin file of a detected plugin is found by its folder.
assert_true( [ 'Polylang' => 'polylang/polylang.php' ] === PluginCoexistence::deactivatable( [ 'Polylang' ] ), 'the detected plugin is matched to its active plugin file, got ' . wp_json_encode( PluginCoexistence::deactivatable( [ 'Polylang' ] ) ) );
assert_true( [] === PluginCoexistence::deactivatable( [ 'WPML' ] ), 'a detected plugin with no active plugin file offers nothing' );

// A user who can deactivate plugins is offered the action; another is not.
$_GET                    = [ 'page' => 'translatewp' ];
$GLOBALS['wp_test_caps'] = [ 'manage_options', 'deactivate_plugins' ];
$offered                 = $render();
assert_true( str_contains( $offered, 'Deactivate Polylang' ), 'a user who can deactivate plugins is offered the action, got ' . $offered );
assert_true( str_contains( $offered, 'action=' . PluginCoexistence::DEACTIVATE_ACTION ) && str_contains( $offered, '_wpnonce=' ), 'the action is a nonce-protected link' );
assert_true( ! str_contains( $offered, '<button' ), 'the action is a link, so the notice keeps its one X' );

$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$plain                   = $render();
assert_true( str_contains( $plain, 'Polylang' ) && ! str_contains( $plain, 'Deactivate Polylang' ), 'a user who cannot deactivate plugins is not offered the action' );

// The action deactivates that plugin and nothing else, then returns to the screen.
$GLOBALS['wp_test_caps']      = [ 'manage_options', 'deactivate_plugins' ];
$GLOBALS['wp_test_redirects'] = [];
$_GET                         = [ 'plugin' => 'polylang/polylang.php' ];
PluginCoexistence::handle_deactivate();
assert_true( [ 'polylang/polylang.php' ] === $GLOBALS['wp_test_deactivated_plugins'], 'the chosen plugin is deactivated, got ' . wp_json_encode( $GLOBALS['wp_test_deactivated_plugins'] ) );
assert_true( in_array( 'translate/translatewp.php', (array) get_option( 'active_plugins' ), true ), 'TranslateWP stays active' );
assert_true( 1 === count( $GLOBALS['wp_test_redirects'] ), 'the action returns to the admin screen' );

// A plugin that is not one of the detected translation plugins is never deactivated.
$GLOBALS['wp_test_deactivated_plugins'] = [];
update_option( 'active_plugins', [ 'translate/translatewp.php', 'polylang/polylang.php', 'akismet/akismet.php' ] );
$_GET = [ 'plugin' => 'akismet/akismet.php' ];
PluginCoexistence::handle_deactivate();
$_GET = [ 'plugin' => 'translate/translatewp.php' ];
PluginCoexistence::handle_deactivate();
assert_true( [] === $GLOBALS['wp_test_deactivated_plugins'], 'only a detected translation plugin can be deactivated, got ' . wp_json_encode( $GLOBALS['wp_test_deactivated_plugins'] ) );

// A user without the capability cannot run the action.
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$_GET                    = [ 'plugin' => 'polylang/polylang.php' ];
$refused                 = false;
try {
	PluginCoexistence::handle_deactivate();
} catch ( RuntimeException $error ) {
	$refused = true;
}
assert_true( $refused && [] === $GLOBALS['wp_test_deactivated_plugins'], 'a user who cannot deactivate plugins cannot run the action' );
$_GET = [];

if ( $failures > 0 ) {
	echo "PluginCoexistenceTest: {$failures} failure(s)\n";
	exit( 1 );
}

echo "PluginCoexistenceTest: all assertions passed\n";
