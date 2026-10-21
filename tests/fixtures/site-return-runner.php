<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/wp-test-stubs.php';

$scenario_path = (string) ( $argv[1] ?? '' );
$result_path   = (string) ( $argv[2] ?? '' );
$scenario      = json_decode( (string) file_get_contents( $scenario_path ), true );
if ( ! is_array( $scenario ) ) {
	fwrite( STDERR, "scenario must be a JSON object\n" );
	exit( 2 );
}

wp_test_reset( [] );
$GLOBALS['wp_test_home_url']      = (string) ( $scenario['home_url'] ?? 'https://example.com' );
$GLOBALS['wp_test_admin_url']     = rtrim( $GLOBALS['wp_test_home_url'], '/' ) . '/wp-admin/';
$GLOBALS['wp_test_caps']          = (array) ( $scenario['caps'] ?? [ 'manage_options' ] );
$GLOBALS['wp_test_current_user']  = (int) ( $scenario['current_user'] ?? 1 );
$GLOBALS['wp_test_exchange_fail'] = ! empty( $scenario['exchange_fail'] );
$GLOBALS['wp_test_transients']    = (array) ( $scenario['transients'] ?? [] );
foreach ( array_keys( $GLOBALS['wp_test_transients'] ) as $key ) {
	$GLOBALS['wp_test_transient_expirations'][ $key ] = time() + 900;
}

$state = (string) ( $scenario['state'] ?? '' );
if ( array_key_exists( 'seed_state_for_user', $scenario ) ) {
	$GLOBALS['wp_test_transients'][ 'translatewp_connect_state_' . hash( 'sha256', $state ) ] = [ 'user_id' => (int) $scenario['seed_state_for_user'] ];
	$GLOBALS['wp_test_transient_expirations'][ 'translatewp_connect_state_' . hash( 'sha256', $state ) ] = time() + 900;
}

$_GET['state'] = $state;
$_GET['code']  = (string) ( $scenario['code'] ?? '' );
if ( isset( $scenario['error'] ) ) {
	$_GET['error'] = (string) $scenario['error'];
}

register_shutdown_function(
	static function () use ( $result_path ): void {
		file_put_contents(
			$result_path,
			wp_json_encode(
				[
					'exchange_posts' => $GLOBALS['wp_test_exchange_posts'],
					'redirects'      => $GLOBALS['wp_test_redirects'],
					'site'           => get_option( 'translatewp_site', [] ),
					'transients'     => $GLOBALS['wp_test_transients'],
				]
			)
		);
	}
);

SiteConnection::handle_return();
