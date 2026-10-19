<?php

declare(strict_types=1);

use TranslateWP\Components\QuotaHandoff;
use TranslateWP\Components\TranslationProRetry;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_handoff( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$connected = [
	'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'secret-site-token' ],
	'languages' => [ 'list' => [
		'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ],
		'de' => [ 'locale' => 'de', 'active' => true, 'default' => false ],
	] ],
];

$quota_answer = static function ( ?string $window_end ) {
	return [
		'response' => [ 'code' => 429 ],
		'body'     => wp_json_encode( [ 'error' => 'Translation quota reached', 'code' => 'limit_exceeded', 'quota' => [ 'percent_available' => 0, 'window_ends_at' => $window_end ] ] ),
	];
};
$rate_answer = [
	'response' => [ 'code' => 429 ],
	'body'     => wp_json_encode( [ 'error' => 'Too many requests', 'code' => 'rate_limited', 'retryAfterSeconds' => 30 ] ),
];
$job_answer = static function ( string $id ) {
	return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'jobId' => $id, 'sourceLang' => 'en', 'targetLang' => 'de' ] ) ];
};

$own_providers = [ [ 'provider' => 'openai', 'modelId' => 'gpt-5.2', 'apiKey' => 'sk-own' ] ];
// A reset clears every filter, so the addon's answer is registered after each.
$reset = static function () use ( $connected, $own_providers ): void {
	wp_test_reset( $connected );
	wp_test_set_filter(
		'translatewp_quota_handoff_providers',
		static function ( $providers, $language ) use ( $own_providers ) {
			return 'de' === $language ? $own_providers : $providers;
		}
	);
};

// --- 1. Switch off: a quota refusal is returned as it is, nothing is resent ---
$reset();
QuotaHandoff::set_enabled( false );
$GLOBALS['wp_test_translation_job_responses'] = [ $quota_answer( '2026-11-01T00:00:00Z' ) ];
$result = TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'de' ], 5 );
assert_handoff( is_wp_error( $result ) && 'limit_exceeded' === ( $result->get_error_data()['payload']['code'] ?? '' ), '1. off: the quota refusal comes back' );
assert_handoff( 1 === count( $GLOBALS['wp_test_remote_posts'] ), '1. off: one request only' );
assert_handoff( ! QuotaHandoff::is_active(), '1. off: nothing switched' );

// --- 2. Switch on, quota reached at job opening: the job is reopened once with the own providers and pinned ---
$reset();
QuotaHandoff::set_enabled( true );
$GLOBALS['wp_test_translation_job_responses'] = [ $quota_answer( '2026-11-01T00:00:00Z' ), $job_answer( 'job-own-1' ) ];
$result = TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'de' ], 5 );
assert_handoff( ! is_wp_error( $result ) && 'job-own-1' === $result['jobId'], '2. the job opens on the second try' );
$posts = $GLOBALS['wp_test_remote_posts'];
assert_handoff( 2 === count( $posts ) && ! isset( $posts[0]['body']['byok'] ) && $own_providers === ( $posts[1]['body']['byok'] ?? null ), '2. the first try carries no key, the second carries the own providers' );
assert_handoff( QuotaHandoff::is_active(), '2. the handoff is active site-wide' );
assert_handoff( '2026-11-01T00:00:00Z' === QuotaHandoff::until(), '2. the window end is kept' );
assert_handoff( QuotaHandoff::job_uses_own_providers( 'job-own-1' ), '2. the reopened job is pinned to the own providers' );

// --- 3. While active, a new job for a language with own providers opens with them at once; one without them opens hosted ---
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_job_responses'] = [ $job_answer( 'job-own-2' ) ];
$result = TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'de' ], 5 );
assert_handoff( ! is_wp_error( $result ) && $own_providers === ( $GLOBALS['wp_test_remote_posts'][0]['body']['byok'] ?? null ), '3. a new job opens with the own providers while the handoff is active' );
assert_handoff( QuotaHandoff::job_uses_own_providers( 'job-own-2' ), '3. and is pinned' );
$GLOBALS['wp_test_remote_posts'] = [];
$result = TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ], 5 );
assert_handoff( is_wp_error( $result ) && [] === $GLOBALS['wp_test_remote_posts'], '3. while the quota is exhausted a language without own providers is refused before anything is sent' );
assert_handoff( 'own_providers_missing' === \TranslateWP\Components\TranslationProviderFailure::reason( $result, 'remote_error' ), '3. and the reason names the missing providers' );

// --- 4. A pinned job keeps the own providers on every batch, even after the window ended ---
QuotaHandoff::activate( '2020-01-01T00:00:00Z' );
assert_handoff( ! QuotaHandoff::is_active(), '4. the window is over' );
$GLOBALS['wp_test_remote_posts'] = [];
TranslationProRetry::post( '/v1/translation/jobs/job-own-1/batches', [ 'batchId' => 'b1', 'items' => [ [ 'groupId' => 'g' ] ] ], 5, 'de' );
assert_handoff( $own_providers === ( $GLOBALS['wp_test_remote_posts'][0]['body']['byok'] ?? null ), '4. a batch of a pinned job still carries the own providers' );
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_job_responses'] = [ $job_answer( 'job-hosted-3' ) ];
TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'de' ], 5 );
assert_handoff( ! isset( $GLOBALS['wp_test_remote_posts'][0]['body']['byok'] ), '4. a new job after the window goes back to the TranslateWP model' );
assert_handoff( ! QuotaHandoff::job_uses_own_providers( 'job-hosted-3' ), '4. and is not pinned' );

// --- 5. Quota reached at a batch of a hosted job: that batch is resent once with the own providers and the job pinned ---
$reset();
QuotaHandoff::set_enabled( true );
$GLOBALS['wp_test_translation_batch_responses'] = [ $quota_answer( null ), [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'ok' => true, 'batchId' => 'b7', 'accepted' => 1 ] ) ] ];
$result = TranslationProRetry::post( '/v1/translation/jobs/job-hosted-9/batches', [ 'batchId' => 'b7', 'items' => [ [ 'groupId' => 'g' ] ] ], 5, 'de' );
assert_handoff( ! is_wp_error( $result ) && ! empty( $result['ok'] ), '5. the batch goes through on the second try' );
$posts = $GLOBALS['wp_test_remote_posts'];
assert_handoff( 2 === count( $posts ) && $own_providers === ( $posts[1]['body']['byok'] ?? null ), '5. the second try carries the own providers' );
assert_handoff( QuotaHandoff::job_uses_own_providers( 'job-hosted-9' ), '5. the job is pinned' );
assert_handoff( QuotaHandoff::is_active() && null === QuotaHandoff::until(), '5. without a window end the handoff is active until rechecked' );

// --- 6. A rate limit never switches ---
$reset();
QuotaHandoff::set_enabled( true );
$GLOBALS['wp_test_translation_job_responses'] = [ $rate_answer ];
$result = TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'de' ], 5 );
assert_handoff( is_wp_error( $result ) && 1 === count( $GLOBALS['wp_test_remote_posts'] ) && ! QuotaHandoff::is_active(), '6. rate_limited is returned once and switches nothing' );

// --- 7. The second try is never the own providers twice: a quota answer to the resend comes back as it is ---
$reset();
QuotaHandoff::set_enabled( true );
$GLOBALS['wp_test_translation_job_responses'] = [ $quota_answer( null ), $quota_answer( null ), $job_answer( 'never' ) ];
$result = TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'de' ], 5 );
assert_handoff( is_wp_error( $result ) && 2 === count( $GLOBALS['wp_test_remote_posts'] ), '7. two tries at most' );

// --- 8. A pin lasts until the job closes or is cancelled; a very old pin is forgotten; pins never overwrite each other ---
$reset();
QuotaHandoff::set_enabled( true );
for ( $i = 0; $i < 250; $i++ ) {
	QuotaHandoff::pin_job( 'job-' . $i );
}
assert_handoff( QuotaHandoff::job_uses_own_providers( 'job-0' ) && QuotaHandoff::job_uses_own_providers( 'job-249' ), '8. every running job keeps its pin' );
$GLOBALS['wp_test_translation_batch_responses'] = [ [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'ok' => true, 'batchId' => 'last', 'accepted' => 0 ] ) ] ];
TranslationProRetry::post( '/v1/translation/jobs/job-3/batches', [ 'batchId' => 'last', 'items' => [], 'closeInput' => true ], 5, 'de' );
assert_handoff( ! QuotaHandoff::job_uses_own_providers( 'job-3' ) && QuotaHandoff::job_uses_own_providers( 'job-2' ) && QuotaHandoff::job_uses_own_providers( 'job-4' ), '8. a batch that closes the input releases that pin alone' );
QuotaHandoff::release_job( 'job-5' );
assert_handoff( ! QuotaHandoff::job_uses_own_providers( 'job-5' ) && QuotaHandoff::job_uses_own_providers( 'job-6' ), '8. a release takes one pin and leaves the others' );
// Pins and the window state live apart: each pin is its own option row with its own expiry; activating the window touches none of them.
$pin_rows = static fn (): array => array_values( array_filter( array_keys( $GLOBALS['wp_test_options_store'] ?? [] ), static fn ( $key ): bool => 0 === strpos( (string) $key, 'quota_pin_' ) ) );
$before   = count( $pin_rows() );
QuotaHandoff::activate( '2026-12-01T00:00:00Z' );
QuotaHandoff::pin_job( 'job-concurrent' );
QuotaHandoff::activate( '2026-12-02T00:00:00Z' );
assert_handoff( count( $pin_rows() ) === $before + 1 && QuotaHandoff::job_uses_own_providers( 'job-concurrent' ) && '2026-12-02T00:00:00Z' === QuotaHandoff::until(), '8. the window state and the pins are written apart, each pin its own row' );
assert_handoff( ! array_key_exists( 'jobs', (array) get_option( QuotaHandoff::OPTION ) ), '8. the option holds no pin list to overwrite' );
// A flushed object cache leaves the pins: they are options, not transients.
$GLOBALS['wp_test_transients'] = [];
assert_handoff( QuotaHandoff::job_uses_own_providers( 'job-concurrent' ) && QuotaHandoff::job_uses_own_providers( 'job-10' ), '8. pins survive a cache flush' );
// A pin past its expiry is gone and its row removed.
update_option( QuotaHandoff::PIN_OPTION_PREFIX . md5( 'job-10' ), time() - 1 );
assert_handoff( ! QuotaHandoff::job_uses_own_providers( 'job-10' ) && ! in_array( 'quota_pin_' . md5( 'job-10' ), $pin_rows(), true ), '8. an expired pin is released and its row deleted' );

// --- 8b. A pinned job whose providers became unusable is refused, never sent to the TranslateWP model, and stays pinned ---
$reset();
QuotaHandoff::set_enabled( true );
QuotaHandoff::pin_job( 'job-pinned' );
wp_test_set_filter( 'translatewp_quota_handoff_providers', static fn ( $providers ) => [] );
$GLOBALS['wp_test_remote_posts'] = [];
$result = TranslationProRetry::post( '/v1/translation/jobs/job-pinned/batches', [ 'batchId' => 'b9', 'items' => [ [ 'groupId' => 'g' ] ] ], 5, 'de' );
assert_handoff( is_wp_error( $result ) && [] === $GLOBALS['wp_test_remote_posts'], '8b. nothing is sent without a usable own provider' );
assert_handoff( 'own_providers_missing' === \TranslateWP\Components\TranslationProviderFailure::reason( $result, 'remote_error' ), '8b. the queue reason names the missing providers' );
assert_handoff( QuotaHandoff::job_uses_own_providers( 'job-pinned' ), '8b. the job stays pinned' );

// --- 8c. The queue reason for a quota refusal the handoff could not take over says quota_exceeded ---
$reset();
QuotaHandoff::set_enabled( true );
$GLOBALS['wp_test_translation_batch_responses'] = [ $quota_answer( null ) ];
$result = TranslationProRetry::post( '/v1/translation/jobs/job-fr/batches', [ 'batchId' => 'b', 'items' => [ [ 'groupId' => 'g' ] ] ], 5, 'fr' );
assert_handoff( 'quota_exceeded' === \TranslateWP\Components\TranslationProviderFailure::reason( $result, $result->get_error_code() ), '8c. a batch quota refusal reads as quota_exceeded on the queue' );
assert_handoff( 'rate_limited' === \TranslateWP\Components\TranslationProviderFailure::reason( new WP_Error( 'remote_error', 'x', [ 'status' => 429, 'payload' => [ 'code' => 'rate_limited' ] ] ), 'remote_error' ), '8c. a rate limit reads as rate_limited' );

// --- 9. Uninstall inventory knows the option ---
assert_handoff( in_array( QuotaHandoff::OPTION, \TranslateWP\Components\CleanupInventory::INFRASTRUCTURE_OPTIONS, true ), '9. the option is cleaned up on uninstall' );

if ( $failures > 0 ) {
	fwrite( STDERR, "QuotaHandoffTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "QuotaHandoffTest: all assertions passed\n";
