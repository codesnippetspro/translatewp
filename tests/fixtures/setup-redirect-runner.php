<?php

declare(strict_types=1);

use TranslateWP\Functionality\Admin\Menus;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/wp-test-stubs.php';

$result_path = (string) ( $argv[1] ?? '' );
if ( '' === $result_path ) {
	fwrite( STDERR, "usage: setup-redirect-runner.php <result.json> <page>\n" );
	exit( 2 );
}

wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$_GET['page'] = (string) ( $argv[2] ?? '' );

register_shutdown_function(
	static function () use ( $result_path ): void {
		file_put_contents(
			$result_path,
			wp_json_encode( [ 'redirects' => $GLOBALS['wp_test_redirects'] ] )
		);
	}
);

( new Menus( 'translatewp', '0.0.0-test' ) )->redirect_to_setup();
