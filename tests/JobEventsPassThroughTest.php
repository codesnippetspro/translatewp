<?php

declare(strict_types=1);

use TranslateWP\Functionality\Endpoints\Transparency;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.0' );
}

$failures = 0;

class Job_Events_Test_Request {
	public function get_param( string $key ) {
		unset( $key );
		return null;
	}
}

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function job_events_from_service( array $events ): array {
	$GLOBALS['wp_test_remote_get_response'] = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( [ 'events' => $events ] ),
	];
	$result = ( new Transparency( 'translatewp', TRANSLATEWP_VERSION ) )->get_job_events( new Job_Events_Test_Request() );
	assert_true( ! is_wp_error( $result ), 'the job-events proxy returns data' );
	return is_array( $result ) ? ( $result['events'] ?? [] ) : [];
}

wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ] );

$base = [
	'id'         => 'e1',
	'siteId'     => 's1',
	'accountId'  => 'a1',
	'kind'       => 'provider_error',
	'occurredAt' => '2026-09-29T18:08:00Z',
	'reasonCode' => 'rate_limited',
	'message'    => 'The translation service is receiving too many requests right now. Wait a few minutes, then index the page again.',
	'pageUrl'    => 'https://example.com/about/',
	'targetLang' => 'de',
	'batchId'    => 'batch-1',
	'resolved'   => true,
	'detail'     => [ 'jobId' => 'job-1', 'status' => 429 ],
];

// --- the job-events proxy keeps reason code, page, language and resolved state ---
$events = job_events_from_service( [ $base ] );
assert_true( 1 === count( $events ), 'one event passes through, got ' . count( $events ) );
$event = $events[0] ?? [];
assert_true( 'rate_limited' === ( $event['reasonCode'] ?? null ), 'the reason code survives, got ' . wp_json_encode( $event['reasonCode'] ?? null ) );
assert_true( $base['message'] === ( $event['message'] ?? null ), 'the service message survives' );
assert_true( 'https://example.com/about/' === ( $event['pageUrl'] ?? null ), 'the page survives' );
assert_true( 'de' === ( $event['targetLang'] ?? null ), 'the language survives' );
assert_true( true === ( $event['resolved'] ?? null ), 'the resolved state survives as a boolean' );
assert_true( ! array_key_exists( 'detail', $event ), 'the internal detail stays with the service' );

// --- an unknown reason code passes as a sanitised key ---
$events = job_events_from_service( [ array_merge( $base, [ 'reasonCode' => 'future<b>code</b>!', 'message' => '<script>x</script>Plain words', 'resolved' => 'yes' ] ) ] );
$event  = $events[0] ?? [];
assert_true( 'futurebcodeb' === ( $event['reasonCode'] ?? null ), 'an unknown reason code is reduced to a key, got ' . wp_json_encode( $event['reasonCode'] ?? null ) );
assert_true( false === strpos( (string) ( $event['message'] ?? '' ), '<' ), 'the message carries no markup' );
assert_true( false === ( $event['resolved'] ?? null ), 'only a real true marks an event resolved' );

// --- an event from an older service without the fields still passes, with empty values ---
$events = job_events_from_service( [ [ 'id' => 'e2', 'siteId' => 's1', 'accountId' => 'a1', 'kind' => 'timeout', 'occurredAt' => '2026-09-29T18:08:00Z' ] ] );
$event  = $events[0] ?? [];
assert_true( 1 === count( $events ), 'an older event still passes' );
assert_true( null === ( $event['reasonCode'] ?? null ) && null === ( $event['pageUrl'] ?? null ) && false === ( $event['resolved'] ?? null ), 'missing fields read as null and not resolved' );

unset( $GLOBALS['wp_test_remote_get_response'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} assertion(s) failed.\n" );
	exit( 1 );
}

echo "JobEventsPassThroughTest passed.\n";
