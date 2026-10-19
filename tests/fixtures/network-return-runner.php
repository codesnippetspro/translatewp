<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/wp-test-stubs.php';

$scenario_path = (string) ( $argv[1] ?? '' );
$result_path   = (string) ( $argv[2] ?? '' );
if ( '' === $scenario_path || '' === $result_path || ! is_file( $scenario_path ) ) {
	fwrite( STDERR, "usage: network-return-runner.php <scenario.json> <result.json>\n" );
	exit( 2 );
}

$scenario = json_decode( (string) file_get_contents( $scenario_path ), true );
if ( ! is_array( $scenario ) ) {
	fwrite( STDERR, "scenario must be a JSON object\n" );
	exit( 2 );
}

wp_test_reset( [] );
$GLOBALS['wp_test_multisite']    = ! empty( $scenario['multisite'] );
$GLOBALS['wp_test_current_blog'] = (int) ( $scenario['current_blog'] ?? 1 );
$GLOBALS['wp_test_caps']         = (array) ( $scenario['caps'] ?? [] );
$GLOBALS['wp_test_sites']        = (array) ( $scenario['sites'] ?? [] );
$GLOBALS['wp_test_exchange_fail'] = ! empty( $scenario['exchange_fail'] );
$GLOBALS['wp_test_exchange_reused_hosts'] = (array) ( $scenario['exchange_reused_hosts'] ?? [] );
$GLOBALS['wp_test_site_options']  = (array) ( $scenario['site_options'] ?? [] );

$state = (string) ( $scenario['state'] ?? '' );
if ( '' !== $state && ! array_key_exists( 'state_valid', $scenario ) ) {
	$key = 'translatewp_connect_state_' . hash( 'sha256', $state );
	$GLOBALS['wp_test_transients'][ $key ]           = [
		'user_id'    => 1,
		'only_hosts' => (array) ( $scenario['only_hosts'] ?? [] ),
	];
	$GLOBALS['wp_test_transient_expirations'][ $key ] = time() + 900;
}

$_GET['state'] = $state;
$_GET['code']  = (string) ( $scenario['code'] ?? '' );
$_GET['codes'] = (string) ( $scenario['codes'] ?? '' );

register_shutdown_function(
	static function () use ( $result_path ): void {
		file_put_contents(
			$result_path,
			wp_json_encode(
				[
					'options_store'  => $GLOBALS['wp_test_options_store'],
					'exchange_posts' => $GLOBALS['wp_test_exchange_posts'],
					'redirects'      => $GLOBALS['wp_test_redirects'],
					'site_options'   => $GLOBALS['wp_test_site_options'],
					'site_transients' => $GLOBALS['wp_test_site_transients'] ?? [],
					'current_blog'   => get_current_blog_id(),
				]
			)
		);
	}
);

SiteConnection::handle_network_return();
