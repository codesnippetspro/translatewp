<?php

declare(strict_types=1);

use TranslateWP\Functionality\Endpoints\Scanning;

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

function make_request( string $method, array $params = [] ): WP_REST_Request {
	return new WP_REST_Request( $method, '/translatewp/v1/scanning/context', $params, [], '' );
}

/** A connected site: the scan is a site request and needs the credentials. */
function scan_site_options(): array {
	return [
		'site' => [
			'id'    => '00000000-0000-4000-8000-0000000000aa',
			'token' => 'secret-site-token',
		],
	];
}

function seed_published_posts(): void {
	$GLOBALS['wp_test_inserted_posts'] = [
		[
			'ID'           => 1,
			'post_type'    => 'page',
			'post_title'   => 'Front page',
			'post_excerpt' => '',
			'post_content' => 'Welcome to our translation site.',
			'post_status'  => 'publish',
		],
	];
	$GLOBALS['wp_test_posts']['page'] = [ 1 ];
}

// --- POST returns pending fast, schedules once, spawns cron ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
assert_true(
	true === has_action( 'translatewp_context_scan_run', [ $scanning, 'run_scan' ] ),
	'Scanning must wire the scan cron hook to run_scan'
);
$result   = $scanning->scan_context( make_request( 'POST' ) );
assert_true( 'pending' === ( $result['status'] ?? '' ), 'POST must return pending immediately, got ' . wp_json_encode( $result ) );
assert_true( [] === $GLOBALS['wp_test_remote_posts'], 'POST must not call the remote API synchronously' );
assert_true( false !== wp_next_scheduled( 'translatewp_context_scan_run' ), 'POST must schedule the scan cron event' );
assert_true( 1 === (int) $GLOBALS['wp_test_spawn_cron'], 'POST must spawn cron for an immediate run' );

$scheduled_before = count( $GLOBALS['wp_test_scheduled_log'] );
$scanning->scan_context( make_request( 'POST' ) );
assert_true( count( $GLOBALS['wp_test_scheduled_log'] ) === $scheduled_before, 'Scheduling must not stack' );

// --- Cron handler stores done + context ---
$GLOBALS['wp_test_scheduled'] = [];
$scanning->run_scan();
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'done' === ( $status['status'] ?? '' ), 'Successful run must store done, got ' . wp_json_encode( $status ) );
assert_true( 'A site about translation' === ( $status['context'] ?? '' ), 'Status must carry the scanned context' );
assert_true( 'lv' === ( $status['language'] ?? '' ), 'Status must carry the language the read found, lowercased, got ' . wp_json_encode( $status['language'] ?? null ) );
assert_true( 'A site about translation' === \TranslateWP\Components\Options::get( 'translations.context.text_context', '' ), 'Done scan must write the context option' );
$scan_post = end( $GLOBALS['wp_test_remote_posts'] );
assert_true( is_array( $scan_post ) && 'Bearer secret-site-token' === ( $scan_post['headers']['Authorization'] ?? '' ), 'The scan request must carry the site credentials, got ' . wp_json_encode( $scan_post['headers'] ?? null ) );
assert_true( is_array( $scan_post ) && '00000000-0000-4000-8000-0000000000aa' === ( $scan_post['headers']['X-TranslateWP-Site-Id'] ?? '' ), 'The scan request must carry the metering headers' );

assert_true( true === ( $status['applied'] ?? null ), 'A scan on a site without translations is applied on the spot' );
assert_true( 'neutral' === ( $status['style'] ?? '' ) && 'friendly' === ( $status['tone'] ?? '' ) && 5 === ( $status['formality'] ?? null ), 'Status must carry the scanned style, tone and formality for the form, got ' . wp_json_encode( $status ) );

// --- Without credentials the scan cannot run and is reported as unavailable ---
wp_test_reset( [] );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scanning->run_scan();
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'failed' === ( $status['status'] ?? '' ) && 'context_scan_service_unavailable' === ( $status['reason'] ?? '' ), 'An unconnected site must fail as service unavailable, got ' . wp_json_encode( $status ) );

// --- Once the site holds translations the scan is a proposal: nothing is written ---
wp_test_reset( scan_site_options() );
seed_published_posts();
\TranslateWP\Components\Options::update( 'translations.context.text_context', 'The owner wrote this.' );
\TranslateWP\Components\Options::update( 'translations.style.tone', 'formal' );
update_option( \TranslateWP\Components\TranslationStore::URL_OPTION, [ 'abc' => [ 'url' => 'https://example.com/', 'post_id' => 1 ] ] );
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'done' === ( $status['status'] ?? '' ), 'A proposal scan still finishes as done, got ' . wp_json_encode( $status ) );
assert_true( false === ( $status['applied'] ?? null ), 'A scan on a translated site must report itself as not applied' );
assert_true( 'A site about translation' === ( $status['context'] ?? '' ), 'The proposal carries the scanned context for the form' );
assert_true( 'The owner wrote this.' === \TranslateWP\Components\Options::get( 'translations.context.text_context', '' ), 'A proposal scan must leave the stored context untouched' );
assert_true( 'formal' === \TranslateWP\Components\Options::get( 'translations.style.tone', '' ), 'A proposal scan must leave the stored style untouched' );
assert_true( \TranslateWP\Components\Options::get( 'translations.context.last_scan_time', 0 ) > 0, 'The scan time is recorded either way' );

// --- Transient failure retries once, then failed ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_scan_fail'] = true;
$scanning->run_scan();
assert_true( false !== wp_next_scheduled( 'translatewp_context_scan_run' ), 'First failure must schedule one retry' );
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'pending' === ( $status['status'] ?? '' ), 'Status must stay pending after the first failure' );

$GLOBALS['wp_test_scheduled'] = [];
$scanning->run_scan();
assert_true( false === wp_next_scheduled( 'translatewp_context_scan_run' ), 'Second failure must not re-schedule' );
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'failed' === ( $status['status'] ?? '' ), 'Second failure must mark the scan failed, got ' . wp_json_encode( $status ) );
assert_true( 'context_scan_unreachable' === ( $status['reason'] ?? '' ), 'A transport failure with no HTTP status must read as unreachable, got ' . wp_json_encode( $status ) );

// --- The failure class and HTTP status land in the action log, no body ---
$log_text = \TranslateWP\Components\ActionLog::text();
assert_true( false !== strpos( $log_text, 'context_scan_unreachable' ), 'The action log must carry the failure class, got ' . $log_text );

// --- Classification by HTTP status and the API failure code ---
function scan_fail_reason_for( $response ): string {
	wp_test_reset( scan_site_options() );
	seed_published_posts();
	$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
	$GLOBALS['wp_test_scan_response'] = $response;
	$scanning->run_scan();
	$GLOBALS['wp_test_scheduled'] = [];
	$scanning->run_scan();
	$GLOBALS['wp_test_scan_response'] = null;
	$status = $scanning->scan_status( make_request( 'GET' ) );
	return (string) ( $status['reason'] ?? '' );
}

function api_error_response( int $code, array $body ): array {
	return [ 'response' => [ 'code' => $code ], 'body' => wp_json_encode( $body ) ];
}

assert_true( 'context_scan_service_unavailable' === scan_fail_reason_for( api_error_response( 503, [ 'error' => 'x', 'code' => 'scan_service_unavailable' ] ) ), 'A 503 scan_service_unavailable must read as service unavailable' );
assert_true( 'context_scan_timeout' === scan_fail_reason_for( api_error_response( 504, [ 'error' => 'x', 'code' => 'scan_timeout' ] ) ), 'A 504 scan_timeout must read as timeout' );
assert_true( 'invalid_scan_response' === scan_fail_reason_for( api_error_response( 502, [ 'error' => 'x', 'code' => 'scan_output_unusable' ] ) ), 'A 502 scan_output_unusable must read as an unusable answer' );
assert_true( 'invalid_scan_response' === scan_fail_reason_for( api_error_response( 422, [ 'error' => 'bad body' ] ) ), 'A 4xx must read as an unusable answer' );
// Today's production API: a plain 502 with no code must not blame the site's network.
assert_true( 'context_scan_service_unavailable' === scan_fail_reason_for( api_error_response( 502, [ 'error' => 'Context scan unavailable' ] ) ), 'A plain 502 with no code must read as service unavailable, not unreachable' );
assert_true( 'context_scan_unreachable' === scan_fail_reason_for( new WP_Error( 'http_request_failed', 'dns failure' ) ), 'A transport error with no answer must read as unreachable' );

// --- A successful scan clears an earlier failure reason (stale reason fix) ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
\TranslateWP\Components\Options::update( 'translations.context.scan_reason', 'context_scan_service_unavailable' );
$scanning->run_scan();
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'done' === ( $status['status'] ?? '' ), 'A clean run after a stale reason must finish done, got ' . wp_json_encode( $status ) );
assert_true( '' === (string) \TranslateWP\Components\Options::get( 'translations.context.scan_reason', '' ), 'A successful scan must clear the earlier failure reason' );

// --- Starting a scan clears an earlier failure reason at once ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
\TranslateWP\Components\Options::update( 'translations.context.scan_reason', 'context_scan_unreachable' );
$scanning->scan_context( make_request( 'POST' ) );
assert_true( '' === (string) \TranslateWP\Components\Options::get( 'translations.context.scan_reason', '' ), 'Starting a scan must clear the earlier failure reason' );

// --- A transient failure retries at once ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_scan_fail'] = true;
$GLOBALS['wp_test_spawn_cron'] = 0;
$scanning->run_scan();
$retry_at = wp_next_scheduled( 'translatewp_context_scan_run' );
assert_true( false !== $retry_at && (int) $retry_at <= time(), 'A transient failure must schedule the retry at once, got ' . wp_json_encode( $retry_at ) . ' for now ' . time() );
assert_true( 1 === (int) $GLOBALS['wp_test_spawn_cron'], 'A transient failure must spawn cron for the retry' );
$GLOBALS['wp_test_scan_fail'] = false;

// --- Retry succeeds after one transient failure ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_scan_fail'] = true;
$scanning->run_scan();
$GLOBALS['wp_test_scan_fail'] = false;
$GLOBALS['wp_test_scheduled'] = [];
$scanning->run_scan();
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'done' === ( $status['status'] ?? '' ), 'Retry must succeed after a transient failure, got ' . wp_json_encode( $status ) );

// --- P8.3 wiring: overdue scan kicks inline on the status route ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
\TranslateWP\Components\Options::update( 'translations.context.scan_status', 'pending' );
$GLOBALS['wp_test_scheduled']['translatewp_context_scan_run'] = time() - 120;
$status = $scanning->scan_status( make_request( 'GET' ) );
assert_true( 'done' === ( $status['status'] ?? '' ), 'Overdue scan must run inline on the status route, got ' . wp_json_encode( $status ) );

// --- P8.2: payload carries locally-collected samples + a private hint ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'http_request_failed', 'loopback broken' );
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scan_post = $GLOBALS['wp_test_remote_posts'][0] ?? [];
assert_true( isset( $scan_post['body']['samples'] ) && is_array( $scan_post['body']['samples'] ), 'Scan payload must carry samples' );
assert_true( count( $scan_post['body']['samples'] ) > 0, 'Scan payload samples must not be empty' );
assert_true( true === ( $scan_post['body']['site_private'] ?? false ), 'Unreachable site must set the private hint' );

// --- Publicly reachable site: hint unset ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => '<html></html>',
	'headers'  => [],
];
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scan_post = $GLOBALS['wp_test_remote_posts'][0] ?? [];
assert_true( false === ( $scan_post['body']['site_private'] ?? true ), 'Reachable site must not set the private hint' );

// --- Redirect to an auth host: hint set ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 302 ],
	'body'     => '',
	'headers'  => [ 'location' => 'https://auth.example.net/login' ],
];
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scan_post = $GLOBALS['wp_test_remote_posts'][0] ?? [];
assert_true( true === ( $scan_post['body']['site_private'] ?? false ), 'Auth-host redirect must set the private hint' );

// --- Payload cap: oversized samples are bounded ---
wp_test_reset( scan_site_options() );
seed_published_posts();
$GLOBALS['wp_test_inserted_posts'][] = [
	'ID'           => 2,
	'post_type'    => 'page',
	'post_title'   => 'Huge page',
	'post_excerpt' => '',
	'post_content' => str_repeat( 'a', 300000 ),
	'post_status'  => 'publish',
];
$GLOBALS['wp_test_posts']['page'][] = 2;
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => '<html></html>',
	'headers'  => [],
];
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scan_post = $GLOBALS['wp_test_remote_posts'][0] ?? [];
$total_bytes = 0;
foreach ( $scan_post['body']['samples'] ?? [] as $sample ) {
	$total_bytes += strlen( (string) $sample );
}
assert_true( $total_bytes <= Scanning::SCAN_PAYLOAD_MAX_BYTES, 'Sample payload must stay under the total cap, got ' . $total_bytes );

// --- Each sample is truncated, never dropped ---
wp_test_reset( scan_site_options() );
$GLOBALS['wp_test_inserted_posts'] = [
	[
		'ID'           => 1,
		'post_type'    => 'page',
		'post_title'   => 'Front page',
		'post_excerpt' => '',
		'post_content' => str_repeat( 'Welcome to our translation site. ', 10000 ),
		'post_status'  => 'publish',
	],
];
$GLOBALS['wp_test_posts']['page'] = [ 1 ];
update_option( 'page_on_front', 1 );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => '<html></html>',
	'headers'  => [],
];
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scan_post      = $GLOBALS['wp_test_remote_posts'][0] ?? [];
$scan_samples   = $scan_post['body']['samples'] ?? [];
$scan_reflector = new ReflectionClass( Scanning::class );
$sample_max     = (int) $scan_reflector->getConstant( 'SCAN_SAMPLE_MAX_BYTES' );
$payload_max    = (int) $scan_reflector->getConstant( 'SCAN_PAYLOAD_MAX_BYTES' );
assert_true( 1 === count( $scan_samples ), 'A 300 KB front page must yield one sample, got ' . count( $scan_samples ) );
assert_true( $sample_max > 0 && strlen( (string) ( $scan_samples[0] ?? '' ) ) <= $sample_max, 'The front page sample must be truncated to SCAN_SAMPLE_MAX_BYTES (' . $sample_max . '), got ' . strlen( (string) ( $scan_samples[0] ?? '' ) ) );
assert_true( $payload_max <= 49152, 'The total sample cap must be at most 48 KB, got ' . $payload_max );
update_option( 'page_on_front', 0 );

// --- translatewp_context_scan_request_body filters the body before the scan request ---
wp_test_reset( scan_site_options() );
seed_published_posts();
wp_test_set_filter( 'translatewp_context_scan_request_body', static function ( array $body ) {
	$body['filtered_scan'] = true;
	return $body;
} );
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
$scan_post = $GLOBALS['wp_test_remote_posts'][0] ?? [];
assert_true(
	true === ( $scan_post['body']['filtered_scan'] ?? false ),
	'translatewp_context_scan_request_body must run before the context scan request'
);

// --- the scan never adds lexicon terms by itself; its term proposals reach Pro only ---
function lexicon_term_count(): int {
	return count( array_filter( $GLOBALS['wp_test_inserted_posts'] ?? [], static fn( $post ) => \TranslateWP\Entities\CPTs\Lexicon::POST_TYPE === ( $post['post_type'] ?? '' ) ) );
}

function mark_site_pro(): void {
	set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true, 'shape' => 2 ], 3600 );
}

function mark_site_translated(): void {
	update_option( \TranslateWP\Components\TranslationStore::URL_OPTION, [ 'abc' => [ 'url' => 'https://example.com/', 'post_id' => 1 ] ] );
}

// A first scan on a site with no translations adds no lexicon terms, Free or Pro.
foreach ( [ 'Free' => false, 'Pro' => true ] as $edition => $is_pro ) {
	wp_test_reset( scan_site_options() );
	seed_published_posts();
	if ( $is_pro ) {
		mark_site_pro();
	}
	$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
	$scanning->run_scan();
	$status = $scanning->scan_status( make_request( 'GET' ) );
	assert_true( 'done' === ( $status['status'] ?? '' ) && true === ( $status['applied'] ?? null ), "A first $edition scan is done and applied, got " . wp_json_encode( $status ) );
	assert_true( 0 === lexicon_term_count(), "A first scan on a $edition site with no translations must add no lexicon terms, got " . lexicon_term_count() );
}
// On Pro the first scan's terms go to the review instead.
assert_true( 2 === count( $status['lexiconSuggestions'] ?? [] ), 'A first Pro scan proposes its terms for review, got ' . wp_json_encode( $status['lexiconSuggestions'] ?? null ) );

// A Free site gets no lexicon suggestions from the scan status, before or after the first translation.
foreach ( [ 'untranslated' => false, 'translated' => true ] as $label => $translated ) {
	wp_test_reset( scan_site_options() );
	seed_published_posts();
	if ( $translated ) {
		mark_site_translated();
	}
	$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
	$scanning->run_scan();
	$status = $scanning->scan_status( make_request( 'GET' ) );
	assert_true( [] === ( $status['lexiconSuggestions'] ?? null ), "A Free $label site must get no lexicon suggestions from the scan status, got " . wp_json_encode( $status['lexiconSuggestions'] ?? null ) );
}

// apply-lexicon is refused for Free and adds nothing.
$applied = $scanning->apply_scan_lexicon( make_request( 'POST' ) );
assert_true( $applied instanceof WP_Error && 403 === ( $applied->get_error_data()['status'] ?? null ), 'apply-lexicon must be refused for Free with a 403, got ' . wp_json_encode( $applied ) );
assert_true( 0 === lexicon_term_count(), 'A refused apply-lexicon must add no terms, got ' . lexicon_term_count() );

// On Pro, apply-lexicon is the owner action that adds the proposed terms.
wp_test_reset( scan_site_options() );
seed_published_posts();
mark_site_translated();
mark_site_pro();
$scanning = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
$scanning->run_scan();
assert_true( 0 === lexicon_term_count(), 'A Pro proposal scan adds no terms by itself' );
$applied = $scanning->apply_scan_lexicon( make_request( 'POST' ) );
assert_true( 2 === ( $applied['added'] ?? null ) && 2 === lexicon_term_count(), 'On Pro, apply-lexicon adds the proposed terms, got ' . wp_json_encode( $applied ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "AsyncScanTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AsyncScanTest: all assertions passed\n";
