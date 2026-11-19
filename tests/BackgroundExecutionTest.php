<?php

declare(strict_types=1);

use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\Endpoints\Indexing;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

if ( in_array( '--litespeed', $argv, true ) ) {
	function litespeed_finish_request(): bool {
		$GLOBALS['finish_calls']++;
		return $GLOBALS['finish_success'];
	}
} else {
	function fastcgi_finish_request(): bool {
		$GLOBALS['finish_calls']++;
		return $GLOBALS['finish_success'];
	}
}
function wp_doing_cron(): bool {
	return ! empty( $GLOBALS['test_cron_request'] );
}
function check_background( bool $ok, string $message ): void {
	if ( ! $ok ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
function background_fixture(): CronFallback {
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ] ] );
	$GLOBALS['finish_calls'] = 0;
	$GLOBALS['finish_success'] = true;
	$GLOBALS['wp_test_is_admin'] = false;
	$GLOBALS['test_cron_request'] = false;
	$GLOBALS['wp_test_doing_ajax'] = false;
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
	set_transient( LoopbackKick::FAILURE_TRANSIENT, 'certificate', 300 );
	return new CronFallback();
}

$cron = background_fixture();
$units = 0;
wp_test_set_filter( QueueExecution::READ_HOOK, static function () use ( &$units ): void {
	++$units;
	$args = apply_filters( 'http_request_args', [ 'timeout' => 30 ] );
	check_background( $args['timeout'] > 0 && $args['timeout'] <= QueueExecution::REDUCED_SECONDS, 'Every reduced HTTP call is bounded by the remaining deadline' );
	$budget = QueueExecution::budget();
	check_background( null !== $budget, 'Reduced work has a shared deadline' );
	$deadline = new ReflectionProperty( $budget, 'deadline' );
	$deadline->setAccessible( true );
	$deadline->setValue( $budget, microtime( true ) - 1 );
	check_background( is_wp_error( apply_filters( 'pre_http_request', false ) ), 'An exhausted deadline refuses another HTTP call' );
} );
check_background( 0 === $units, 'No unit runs before the response is finished' );
$GLOBALS['finish_success'] = false;
$cron->run_after_flush();
check_background( 0 === $units, 'A failed finish function never runs queue work' );
$GLOBALS['finish_success'] = true;
$cron->run_after_flush();
check_background( 1 === $units, 'One unit runs after a successful finish function' );
check_background( null === QueueExecution::budget(), 'The deadline is cleared after the unit' );
$cron->run_after_flush();
check_background( 1 === $units, 'A second request inside the minute cannot run another unit' );
check_background( 1 === get_option( QueueExecution::PHASE_OPTION ), 'Only one phase advances in a run' );

$phases = [];
foreach ( [ QueueExecution::READ_HOOK, QueueExecution::PUSH_HOOK, QueueExecution::READINESS_HOOK, QueueExecution::STORE_HOOK ] as $hook ) {
	wp_test_set_filter( $hook, static function () use ( &$phases, $hook ): void { $phases[] = $hook; } );
}
for ( $i = 0; $i < 4; ++$i ) {
	delete_option( QueueExecution::LAST_REDUCED_OPTION );
	$cron->run_after_flush();
}
check_background( $phases === [ QueueExecution::PUSH_HOOK, QueueExecution::READINESS_HOOK, QueueExecution::STORE_HOOK, QueueExecution::READ_HOOK ], 'Reduced runs rotate one push, readiness, store and reader unit without starvation' );

foreach ( [ 'healthy', 'crawler', 'admin', 'ajax', 'cron' ] as $excluded ) {
	$cron = background_fixture();
	if ( 'healthy' === $excluded ) { LoopbackKick::acknowledge(); }
	if ( 'crawler' === $excluded ) { $_SERVER['HTTP_USER_AGENT'] = 'Googlebot'; }
	if ( 'admin' === $excluded ) { $GLOBALS['wp_test_is_admin'] = true; }
	if ( 'ajax' === $excluded ) { $GLOBALS['wp_test_doing_ajax'] = true; }
	if ( 'cron' === $excluded ) { $GLOBALS['test_cron_request'] = true; }
	if ( in_array( $excluded, [ 'ajax', 'cron' ], true ) ) {
		$GLOBALS['wp_test_scheduled'][ \TranslateWP\Functionality\ReadinessReconcile::HOOK ] = time() - 120;
		$cron->maybe_run_in_admin();
		$drains = array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $event ): bool => Indexer::DRAIN_HOOK === $event['tag'] );
		check_background( [] === $drains, 'Admin initialization does not run the page fallback during ' . $excluded );
	}
	$cron->run_after_flush();
	check_background( 0 === $GLOBALS['finish_calls'], $excluded . ' requests never finish early or run reduced work' );
	check_background( false === get_option( QueueExecution::LAST_REDUCED_OPTION, false ), $excluded . ' requests leave the reduced interval untouched' );
}


$cron = background_fixture();
$queue = new \TranslateWP\Components\IndexQueue();
$indexer = new Indexer( '', '', $queue );
$sync = new \TranslateWP\Functionality\TranslationStoreSync();
$readiness = new \TranslateWP\Functionality\ReadinessReconcile( '', '', $indexer );
$first_row = 0;
foreach ( [ 'a', 'b' ] as $letter ) {
	$id = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => ord( $letter ), 'url' => 'https://example.com/' . $letter . '/', 'page_title' => '', 'language' => 'it', 'priority' => 50,
		'payload' => wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫Shop', 'cache' => [ 'siteId' => '00000000-0000-4000-8000-000000000001', 'hash' => str_repeat( $letter, 64 ) ] ] ] ) ] );
	if ( 0 === $first_row ) { $first_row = (int) $id; }
}
update_option( QueueExecution::PHASE_OPTION, 1 );
$cron->run_after_flush();
$sent = array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $row ): bool => false !== strpos( $row['path'], '/batches' ) );
check_background( 1 === count( $sent ) && 1 === count( array_values( $sent )[0]['body']['items'] ), 'The registered reduced push worker sends exactly one queued page' );
$GLOBALS['wp_test_cache_lookup_items'][ str_repeat( 'a', 64 ) ] = '⟪item_0001⟫Negozio';
$GLOBALS['wp_test_job_states']['00000000-0000-4000-8000-000000000099'] = 'done';
delete_option( QueueExecution::LAST_REDUCED_OPTION );
$cron->run_after_flush();
check_background( $queue->is_translated( $first_row ), 'The registered reduced readiness worker settles the completed page' );
delete_option( QueueExecution::LAST_REDUCED_OPTION );
$cron->run_after_flush();
check_background( 'Negozio' === ( \TranslateWP\Components\TranslationStore::for_url( 'https://example.com/a/', 'it' )['Shop'] ?? '' ), 'The registered reduced store worker serves the completed translation without an admin screen' );

$cron = background_fixture();
delete_transient( LoopbackKick::FAILURE_TRANSIENT );
LoopbackKick::fire();
check_background( ! LoopbackKick::healthy() && ! LoopbackKick::unhealthy(), 'A nonblocking kick is not an acknowledgement and initially gets a grace interval' );
update_option( LoopbackKick::FIRST_DISPATCH_OPTION, time() - 61 );
check_background( LoopbackKick::unhealthy(), 'An unacknowledged kick eventually enables reduced mode' );
$indexer = new Indexer( '', '', null, null, false );
$endpoint = new Indexing( '', '', $indexer );
$token = 'test-token';
$timestamp = (string) time();
$nonce = str_repeat( 'a', 32 );
$key = hash( 'sha256', $token );
$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', "drain\n" . $timestamp . "\n" . $nonce, $key, true ) ), '+/', '-_' ), '=' );
$request = new WP_REST_Request( 'POST', '/translatewp/v1/indexing/drain-now', [ 'ts' => $timestamp, 'sig' => $signature, 'nonce' => $nonce, 'probe' => true ] );
check_background( $endpoint->drain_now_permissions_check( $request ), 'A health probe uses the existing signed permission boundary' );
check_background( [ 'healthy' => true ] === $endpoint->drain_now( $request ), 'An authenticated probe answers without draining' );
check_background( LoopbackKick::healthy(), 'Reaching the signed endpoint acknowledges the worker' );
check_background( ! $endpoint->drain_now_permissions_check( $request ), 'A probe signature cannot be replayed' );
$cron->run_after_flush();
check_background( 0 === $GLOBALS['finish_calls'], 'A recovered worker disables reduced mode immediately' );

$cron = background_fixture();
check_background( false !== strpos( CronFallback::notice_message(), 'certificate' ) && false !== strpos( CronFallback::notice_message(), 'reduced mode' ), 'The Dashboard names the certificate cause and reduced mode' );
set_transient( LoopbackKick::FAILURE_TRANSIENT, 'http_auth', 300 );
check_background( false !== strpos( CronFallback::notice_message(), 'HTTP authentication' ), 'HTTP authentication has its own visible explanation' );
set_transient( LoopbackKick::FAILURE_TRANSIENT, 'request_blocked', 300 );
check_background( false !== strpos( CronFallback::notice_message(), 'blocking the request' ), 'Blocked loopbacks have a visible explanation' );

$previous_database = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class() extends WP_Testwpdb {
	public function get_var( string $query ) { return '0'; }
};
$ran = false;
check_background( ! QueueExecution::run( static function () use ( &$ran ): void { $ran = true; } ) && ! $ran, 'An occupied site-wide SQL lock prevents another worker from running' );
$GLOBALS['wpdb'] = $previous_database;

$cron = background_fixture();
define( 'REST_REQUEST', true );
$cron->run_after_flush();
check_background( 0 === $GLOBALS['finish_calls'], 'Visitor REST calls never enter reduced mode' );
echo "BackgroundExecutionTest: all assertions passed\n";
