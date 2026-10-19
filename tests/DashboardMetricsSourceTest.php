<?php

declare(strict_types=1);

// The dashboard route answers from the service's usage, the index queue and
// the Lexicon. Nothing on the site records token figures of its own, and no
// per-model token breakdown is sent to the browser.

use TranslateWP\Entities\CPTs\Metrics;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset( [ 'languages' => [ 'list' => [ [ 'locale' => 'de', 'name' => 'German' ] ] ] ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$admin    = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$response = $admin->dashboard_metrics( new WP_REST_Request( 'GET', '/translatewp/v1/admin/dashboard-metrics', [], [], '' ) );

assert_true( is_array( $response ) && ! array_key_exists( 'modelUsage', $response ), 'The dashboard route must send no per-model token breakdown' );
foreach ( [ 'translationsToday', 'languageUsage', 'recentContent', 'recentLexicon', 'cacheHitsToday', 'cacheHitRatio' ] as $key ) {
	assert_true( array_key_exists( $key, (array) $response ), "The dashboard route must still send {$key}" );
}

assert_true( ! method_exists( Metrics::class, 'record' ), 'The site must have no recorder of its own token figures' );
assert_true( ! method_exists( Metrics::class, 'get_dashboard_data' ), 'The dashboard must not read site-recorded token figures' );

// The post type stays registered so data an older version stored is still
// found and removed by the uninstall inventory.
( new Metrics() )->register();
assert_true( 'translatewp_metric' === Metrics::POST_TYPE, 'The metric post type keeps its name for the cleanup inventory' );

if ( $failures > 0 ) {
	fwrite( STDERR, "DashboardMetricsSourceTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "DashboardMetricsSourceTest: all assertions passed\n";
