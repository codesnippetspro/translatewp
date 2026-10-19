<?php

declare(strict_types=1);

// On the Plugins screens, while TranslateWP Pro is installed, TranslateWP's row
// offers no delete link and says to delete Pro first.

use TranslateWP\Functionality\Admin\PluginRow;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_BASENAME' ) ) {
	define( 'TRANSLATEWP_BASENAME', 'translate/translatewp.php' );
}

$failures = 0;
function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$link    = '<a href="plugins.php?action=delete-selected&amp;checked[]=translate%2Ftranslatewp.php" id="delete-translate" class="delete">Delete</a>';
$blocked = 'Delete<span class="screen-reader-text">You cannot delete this plugin as other plugins require it.</span>';
$row     = new PluginRow( 'translatewp', '1.0.0' );

// --- with Pro installed: no delete link, a visible reason (older WordPress gives a link, newer a disabled text) ---
wp_test_reset( [] );
wp_test_set_filter( 'translatewp_pro_installed_version', static fn() => '0.3.44' );
foreach ( [ $link, $blocked ] as $delete ) {
	$actions = $row->action_links( [ 'deactivate' => 'Deactivate', 'delete' => $delete ] );
	assert_true( ! isset( $actions['delete'] ) && false === strpos( implode( '', $actions ), 'delete-selected' ), 'no delete link while Pro is installed' );
	assert_true( false !== strpos( (string) ( $actions['translatewp_delete_pro_first'] ?? '' ), 'Delete TranslateWP Pro first' ), 'the row says to delete Pro first' );
	assert_true( 'Deactivate' === $actions['deactivate'], 'other actions are untouched' );
}
// --- both active: WordPress gives no delete action; the row still says what to do ---
$actions = $row->action_links( [ 'deactivate' => 'Deactivate' ] );
assert_true( ! isset( $actions['delete'] ) && false !== strpos( (string) ( $actions['translatewp_delete_pro_first'] ?? '' ), 'Delete TranslateWP Pro first' ), 'an active TranslateWP row says to delete Pro first' );
assert_true( 'Deactivate' === $actions['deactivate'], 'its Deactivate is untouched' );

// --- the same on the Network Admin plugins screen ---
wp_test_reset( [] );
wp_test_set_filter( 'translatewp_pro_installed_version', static fn() => '0.3.44' );
$GLOBALS['wp_test_filters_registered'] = [];
new PluginRow( 'translatewp', '1.0.0' );
foreach ( [ 'plugin_action_links_', 'network_admin_plugin_action_links_' ] as $prefix ) {
	$network = apply_filters( $prefix . TRANSLATEWP_BASENAME, [ 'deactivate' => 'Network Deactivate' ] );
	assert_true( false !== strpos( (string) ( $network['translatewp_delete_pro_first'] ?? '' ), 'Delete TranslateWP Pro first' ), "the {$prefix} row says to delete Pro first" );
}

// --- without Pro: the delete action is WordPress's own ---
wp_test_reset( [] );
wp_test_set_filter( 'translatewp_pro_installed_version', static fn() => '' );
$plain = $row->action_links( [ 'delete' => $link ] );
assert_true( $link === $plain['delete'] && ! isset( $plain['translatewp_delete_pro_first'] ), 'without Pro the delete link stays and nothing is added' );

if ( $failures > 0 ) {
	fwrite( STDERR, "PluginRowTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "PluginRowTest: all assertions passed\n";
