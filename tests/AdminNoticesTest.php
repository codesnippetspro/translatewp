<?php

declare(strict_types=1);

use TranslateWP\Components\AdminNotices;
use TranslateWP\Components\PluginCoexistence;
use TranslateWP\Components\TranslationProRetry;
use TranslateWP\Functionality\Admin\NoticeDismiss;
use TranslateWP\Functionality\CronFallback;

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

function capture( callable $render ): string {
	ob_start();
	$render();
	return (string) ob_get_clean();
}

wp_test_reset( [] );
if ( ! defined( 'POLYLANG_VERSION' ) ) {
	define( 'POLYLANG_VERSION', '3.0' );
}

$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$_GET                    = [ 'page' => 'translatewp' ];

// Every dismissible TranslateWP notice carries the WordPress X ("dismissible").
$coexistence = capture( [ PluginCoexistence::class, 'render_admin_notice' ] );
assert_true( str_contains( $coexistence, 'is-dismissible' ), 'the other-plugin notice carries is-dismissible, got ' . $coexistence );
assert_true( str_contains( $coexistence, 'data-translatewp-dismiss="' . PluginCoexistence::DISMISS_ACTION . '"' ), 'the other-plugin notice names the action that remembers its X' );
assert_true( ! str_contains( $coexistence, '<button' ), 'the other-plugin notice has no separate Dismiss button' );
assert_true( ! str_contains( $coexistence, 'translatewp_deactivate_other_plugin' ), 'a user who cannot deactivate plugins is offered no deactivation' );

update_option( 'active_plugins', [ 'polylang/polylang.php' ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options', 'deactivate_plugins' ];
$with_action             = capture( [ PluginCoexistence::class, 'render_admin_notice' ] );
assert_true( str_contains( $with_action, 'action=translatewp_deactivate_other_plugin' ) && str_contains( $with_action, 'Deactivate Polylang' ), 'a user who can deactivate plugins is offered the one-click deactivation, got ' . $with_action );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

set_transient( TranslationProRetry::NOTICE_TRANSIENT, true, 300 );
$pro = capture( [ TranslationProRetry::class, 'render_notice' ] );
assert_true( str_contains( $pro, 'is-dismissible' ), 'the Pro-setting notice carries is-dismissible' );

// The late-cron notice stays on TranslateWP screens and with users who manage the plugin.
$cron = new CronFallback( 'translatewp', '0.0.0' );
$late = new ReflectionProperty( CronFallback::class, 'announced_late' );
$late->setAccessible( true );
$late->setValue( $cron, true );

$shown = capture( [ $cron, 'render_cron_late_notice' ] );
assert_true( str_contains( $shown, 'scheduled tasks are running late' ), 'the late-cron notice shows on a TranslateWP screen, got ' . $shown );
assert_true( str_contains( $shown, 'is-dismissible' ) && str_contains( $shown, 'data-translatewp-dismiss="' . CronFallback::DISMISS_ACTION . '"' ), 'the late-cron notice carries a remembered X' );

$_GET = [ 'page' => 'plugins' ];
assert_true( '' === capture( [ $cron, 'render_cron_late_notice' ] ), 'the late-cron notice is not shown outside TranslateWP screens' );
$_GET = [];
assert_true( '' === capture( [ $cron, 'render_cron_late_notice' ] ), 'the late-cron notice is not shown on a wp-admin screen without a page' );

$_GET                    = [ 'page' => 'translatewp' ];
$GLOBALS['wp_test_caps'] = [ 'edit_posts' ];
assert_true( '' === capture( [ $cron, 'render_cron_late_notice' ] ), 'the late-cron notice is not shown to users without manage_options' );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

// An X dismissal of the late-cron notice is remembered for this user only.
$cron->handle_dismiss();
assert_true( '' === capture( [ $cron, 'render_cron_late_notice' ] ), 'an X dismissal of the late-cron notice is remembered' );
$GLOBALS['wp_test_current_user'] = 2;
assert_true( str_contains( capture( [ $cron, 'render_cron_late_notice' ] ), 'running late' ), 'another user still sees the late-cron notice' );
$GLOBALS['wp_test_current_user'] = 1;

// An X dismissal of the other-plugin notice is remembered, and only a manager can make it.
$GLOBALS['wp_test_caps']      = [ 'edit_posts' ];
$GLOBALS['wp_test_json_sent'] = [];
PluginCoexistence::handle_ajax_dismiss();
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
assert_true( str_contains( capture( [ PluginCoexistence::class, 'render_admin_notice' ] ), 'Polylang' ), 'a user without manage_options cannot dismiss the other-plugin notice' );
assert_true( [ 'error' ] === array_column( $GLOBALS['wp_test_json_sent'], 'type' ), 'the refused dismissal answers with an error' );

PluginCoexistence::handle_ajax_dismiss();
assert_true( in_array( PluginCoexistence::DISMISS_ACTION, $GLOBALS['wp_test_ajax_referers'] ?? [], true ), 'the X dismissal checks its nonce' );
assert_true( '' === capture( [ PluginCoexistence::class, 'render_admin_notice' ] ), 'an X dismissal of the other-plugin notice is remembered' );

// The X is wired on TranslateWP screens only.
$GLOBALS['wp_test_inline_scripts'] = [];
$_GET                              = [ 'page' => 'plugins' ];
NoticeDismiss::enqueue_script();
assert_true( [] === $GLOBALS['wp_test_inline_scripts'], 'the dismiss script is not added outside TranslateWP screens' );
$_GET = [ 'page' => 'translatewp-settings' ];
NoticeDismiss::enqueue_script();
$script = implode( '', array_column( $GLOBALS['wp_test_inline_scripts'], 'data' ) );
assert_true( 'common' === ( $GLOBALS['wp_test_inline_scripts'][0]['handle'] ?? '' ) && str_contains( $script, 'data-translatewp-dismiss' ) && str_contains( $script, 'admin-ajax.php' ), 'the dismiss script posts the X to admin-ajax on TranslateWP screens' );

// Both dismiss actions are registered for logged-in ajax.
new \TranslateWP\Functionality\Admin\PluginCoexistence( 'translatewp', '0.0.0' );
new NoticeDismiss( 'translatewp', '0.0.0' );
$registered = array_column( $GLOBALS['wp_test_action_registrations'], 'tag' );
assert_true( in_array( 'wp_ajax_' . PluginCoexistence::DISMISS_ACTION, $registered, true ), 'the other-plugin X is registered as an ajax action' );
assert_true( in_array( 'wp_ajax_' . CronFallback::DISMISS_ACTION, $registered, true ), 'the late-cron X is registered as an ajax action' );
assert_true( in_array( 'admin_enqueue_scripts', $registered, true ), 'the dismiss script is enqueued with the admin scripts' );
unset( $registered );
$_GET = [];

// The notice class carries the shared markup.
assert_true( str_contains( AdminNotices::open( 'notice-warning', 'x_action' ), 'data-nonce="nonce-x_action"' ), 'a dismissible notice carries the nonce for its action' );

if ( $failures > 0 ) {
	echo "AdminNoticesTest: {$failures} failure(s)\n";
	exit( 1 );
}

echo "AdminNoticesTest: all assertions passed\n";
