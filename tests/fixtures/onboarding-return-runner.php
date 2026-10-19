<?php

declare(strict_types=1);

use TranslateWP\Components\SiteConnection;
use TranslateWP\Functionality\Admin\Menus;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/wp-test-stubs.php';

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}

$result_path   = (string) ( $argv[1] ?? '' );
$scenario_path = (string) ( $argv[2] ?? '' );
if ( '' === $result_path ) {
	fwrite( STDERR, "usage: onboarding-return-runner.php <result.json> [scenario.json]\n" );
	exit( 2 );
}

$scenario = '' !== $scenario_path ? json_decode( (string) file_get_contents( $scenario_path ), true ) : [];
$scenario = is_array( $scenario ) ? $scenario : [];

wp_test_reset( (array) ( $scenario['options_store'] ?? [] ) );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$read_pages = (int) ( $scenario['read_pages'] ?? 0 );
if ( $read_pages > 0 ) {
	$queue = new \TranslateWP\Components\IndexQueue();
	for ( $index = 1; $index <= $read_pages; $index++ ) {
		$queue->enqueue_read( [ [ 'object_type' => 'page', 'object_id' => $index, 'url' => 'https://example.com/page-' . $index, 'priority' => 1 ] ] );
	}
	foreach ( $queue->claim_batch( \TranslateWP\Components\IndexQueue::STAGE_EXTRACT, $read_pages ) as $row ) {
		$queue->complete( (int) ( is_array( $row ) ? $row['id'] : $row ) );
	}
}

$state = str_repeat( 'a', 43 );
$key   = 'translatewp_connect_state_' . hash( 'sha256', $state );
$GLOBALS['wp_test_transients'][ $key ]           = [ 'user_id' => 1 ];
$GLOBALS['wp_test_transient_expirations'][ $key ] = time() + 900;
$_GET['state'] = $state;
$_GET['code']  = 'approved-connect-code';

register_shutdown_function(
	static function () use ( $result_path ): void {
		ob_start();
		( new Menus( 'translatewp', '0.0.0-test' ) )->render_admin_page();
		$admin_html = (string) ob_get_clean();

		file_put_contents(
			$result_path,
			wp_json_encode(
				[
					'options_store' => $GLOBALS['wp_test_options_store'],
					'redirects'     => $GLOBALS['wp_test_redirects'],
					'admin_html'    => $admin_html,
				]
			)
		);
	}
);

SiteConnection::handle_return();
