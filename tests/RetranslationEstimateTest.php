<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function translate_page( IndexQueue $queue, int $object_id, string $url, string $language ): void {
	$queue->enqueue(
		[
			[
				'object_type' => 'post',
				'object_id'   => $object_id,
				'url'         => $url,
				'page_title'  => 'Page ' . $object_id,
				'language'    => $language,
				'priority'    => 100,
			],
		]
	);
	$extract = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
	$queue->store_payload( (int) $extract[0]['id'], (string) wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => 'm' ] ] ) );
	$push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
	$queue->complete( (int) $push[0]['id'] );
}

function estimate(): array {
	$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );

	return $admin->retranslation_estimate( new WP_REST_Request( 'GET', '/translatewp/v1/admin/retranslation-estimate', [], [], '' ) );
}

// The figures are the site's own pages and translation languages,
// read from the index; no token figure and no call to the service.
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => 'secret-site-token' ] ] );
$GLOBALS['wp_test_caps']            = [ 'manage_options' ];
$GLOBALS['wp_test_remote_get_urls'] = [];

$queue = new IndexQueue();
translate_page( $queue, 101, 'https://example.com/a', 'de' );
translate_page( $queue, 101, 'https://example.com/a', 'fr' );
translate_page( $queue, 102, 'https://example.com/b', 'de' );
translate_page( $queue, 103, 'https://example.com/c', 'es' );
// A page still waiting in the queue is not yet translated, so it is not counted.
$queue->enqueue( [ [ 'object_type' => 'post', 'object_id' => 104, 'url' => 'https://example.com/d', 'page_title' => 'Page 104', 'language' => 'it', 'priority' => 100 ] ] );

$estimate = estimate();
assert_true( 3 === ( $estimate['pages'] ?? null ), 'Pages must be the distinct translated pages in the index, got ' . wp_json_encode( $estimate ) );
assert_true( 3 === ( $estimate['languages'] ?? null ), 'Languages must be the distinct translation languages in the index, got ' . wp_json_encode( $estimate ) );
assert_true( ! array_key_exists( 'tokens', $estimate ) && ! array_key_exists( 'tokensPerSegment', $estimate ), 'The estimate must carry no token figure, got ' . wp_json_encode( $estimate ) );
assert_true( [] === ( $GLOBALS['wp_test_remote_get_urls'] ?? [] ), 'The estimate must not call the service, called ' . wp_json_encode( $GLOBALS['wp_test_remote_get_urls'] ?? [] ) );

// An empty index: zero pages and zero languages.
wp_test_reset( [] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$estimate                = estimate();
assert_true( 0 === ( $estimate['pages'] ?? null ) && 0 === ( $estimate['languages'] ?? null ), 'An empty index must give zero pages and zero languages, got ' . wp_json_encode( $estimate ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "RetranslationEstimateTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "RetranslationEstimateTest: all assertions passed\n";
