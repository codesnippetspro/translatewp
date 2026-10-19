<?php
/**
 * uninstall.php does not boot the plugin (issue 54). WordPress includes the main
 * plugin file for register_uninstall_hook, which built the whole Loader inside the
 * delete-plugin request. With uninstall.php WordPress includes only that file.
 */

declare(strict_types=1);

require_once __DIR__ . '/uninstall-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$root = dirname( __DIR__ );

assert_true( is_file( $root . '/uninstall.php' ), 'uninstall.php is missing from the plugin root' );

$main = (string) file_get_contents( $root . '/translatewp.php' );
assert_true( false === strpos( $main, 'register_uninstall_hook' ), 'translatewp.php still registers an uninstall hook, so WordPress includes the main file on delete' );

$bundle = (string) file_get_contents( $root . '/scripts/bundle.mjs' );
assert_true( 1 === preg_match( "/BUNDLE_PATHS = \[[^\]]*'uninstall\.php'/s", $bundle ), 'scripts/bundle.mjs does not ship uninstall.php' );

if ( is_file( $root . '/uninstall.php' ) ) {
	// Without WP_UNINSTALL_PLUGIN the file must stop before touching data.
	$GLOBALS['wpdb']->seed( 'twp_index_item', 10 );
	$guard = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'define("ABSPATH", "/tmp/"); include ' . var_export( $root . '/uninstall.php', true ) . '; echo "reached";' ) . ' 2>&1' );
	assert_true( false === strpos( (string) $guard, 'reached' ), 'uninstall.php must exit when WP_UNINSTALL_PLUGIN is not defined' );

	// A plugin folder without vendor/autoload.php cannot clean up; that is logged, not silent.
	$empty_dir = sys_get_temp_dir() . '/twp-uninstall-' . getmypid() . '/';
	$log_file  = sys_get_temp_dir() . '/twp-uninstall-' . getmypid() . '.log';
	@mkdir( $empty_dir );
	$child = 'define("ABSPATH", "/tmp/"); define("WP_UNINSTALL_PLUGIN", "translate/translatewp.php");'
		. ' define("TRANSLATEWP_PATH", ' . var_export( $empty_dir, true ) . '); define("TRANSLATEWP_VERSION", "1.0.0");'
		. ' ini_set("error_log", ' . var_export( $log_file, true ) . '); include ' . var_export( $root . '/uninstall.php', true ) . '; echo "returned";';
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $child ) . ' 2>&1' );
	$log = is_file( $log_file ) ? (string) file_get_contents( $log_file ) : '';
	assert_true( false !== strpos( $out, 'returned' ), 'uninstall.php without vendor/autoload.php must return, got: ' . $out );
	assert_true( false !== strpos( $log, 'vendor/autoload.php' ), 'a missing vendor/autoload.php at uninstall must be logged, log: ' . $log );
	@unlink( $log_file );
	@rmdir( $empty_dir );

	// A connected site: the delete tells the service, and names the plugin
	// version although nothing but uninstall.php has been loaded.
	$GLOBALS['uninstall_test_options']['translatewp_site'] = [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ];
	assert_true( ! defined( 'TRANSLATEWP_VERSION' ), 'the test must reach uninstall.php without the version constant, as a delete request does' );

	define( 'WP_UNINSTALL_PLUGIN', 'translate/translatewp.php' );
	require $root . '/uninstall.php';

	preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/translatewp.php' ), $header );
	$disconnects = array_values( array_filter( $GLOBALS['uninstall_test_remote'], static fn ( array $call ): bool => false !== strpos( $call['url'], '/v1/site/disconnect' ) ) );
	assert_true( 1 === count( $disconnects ), 'deleting a connected site must send one disconnect, got ' . count( $disconnects ) );
	assert_true( '' !== ( $header[1] ?? '' ) && ( $header[1] ?? '' ) === ( $disconnects[0]['headers']['X-TranslateWP-Version'] ?? '' ), 'the disconnect must carry the plugin version from the plugin header, got ' . wp_json_encode( $disconnects[0]['headers']['X-TranslateWP-Version'] ?? null ) );
	assert_true( 'Bearer site-token' === ( $disconnects[0]['headers']['Authorization'] ?? '' ), 'the disconnect must carry the site token' );

	assert_true( 0 === did_action( 'translatewp_loaded' ), 'uninstall.php booted the plugin Loader' );
	assert_true( ! class_exists( \TranslateWP\Includes\Loader::class, false ), 'uninstall.php loaded the Loader class' );
	assert_true( 0 === $GLOBALS['wpdb']->count_type( 'twp_index_item' ), 'uninstall.php left queue rows behind' );
	assert_true( 1 === did_action( 'TranslateWP/cleanup' ), 'uninstall.php must still fire TranslateWP/cleanup' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "UninstallEntryTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "UninstallEntryTest ok\n" );
