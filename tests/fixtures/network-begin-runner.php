<?php

declare(strict_types=1);

use TranslateWP\Functionality\Admin\MultisiteSettingsPage;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.0.0-test' );
}

$scenario_path = (string) ( $argv[1] ?? '' );
$result_path   = (string) ( $argv[2] ?? '' );
if ( '' === $scenario_path || '' === $result_path || ! is_file( $scenario_path ) ) {
	fwrite( STDERR, "usage: network-begin-runner.php <scenario.json> <result.json>\n" );
	exit( 2 );
}

$scenario = json_decode( (string) file_get_contents( $scenario_path ), true );
if ( ! is_array( $scenario ) ) {
	exit( 2 );
}

wp_test_reset( [] );
$GLOBALS['wp_test_multisite']    = true;
$GLOBALS['wp_test_current_blog'] = 1;
$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
$GLOBALS['wp_test_sites']        = (array) ( $scenario['sites'] ?? [] );
foreach ( (array) ( $scenario['options_store'] ?? [] ) as $key => $value ) {
	$GLOBALS['wp_test_options_store'][ $key ] = $value;
}
$_POST = (array) ( $scenario['post'] ?? [] );

register_shutdown_function(
	static function () use ( $result_path ): void {
		file_put_contents( $result_path, wp_json_encode( [ 'redirects' => $GLOBALS['wp_test_redirects'] ] ) );
	}
);

$page = new MultisiteSettingsPage( 'translatewp', '0.0.0-test' );
$page->handle_begin();
