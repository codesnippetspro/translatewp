<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\QuotaHandoff;
use TranslateWP\Components\ReadinessBudget;
use TranslateWP\Components\TranslationProRetry;
use TranslateWP\Functionality\Indexer;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function check_own( bool $ok, string $message ): void {
	global $failures;
	if ( ! $ok ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function own_options(): array {
	return [
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ],
		'languages' => [ 'list' => [
			'en' => [ 'locale' => 'en', 'slug' => 'en', 'default' => true, 'active' => true ],
			'it' => [ 'locale' => 'it', 'slug' => 'it', 'default' => false, 'active' => true ],
		] ],
	];
}

function own_chain(): array {
	return [ [ 'provider' => 'openai', 'modelId' => 'test', 'apiKey' => 'test-only' ] ];
}

/** Registers what the Pro addon adds to every job and batch body when the customer uses own providers. */
function with_own_providers(): void {
	$add = static fn( array $body ): array => array_merge( $body, [ 'byok' => own_chain() ] );
	wp_test_set_filter( 'translatewp_translation_job_request_body', $add );
	wp_test_set_filter( 'translatewp_translation_batch_request_body', $add );
}

function own_posts( string $needle ): array {
	return array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], static fn( array $row ): bool => $needle === $row['path'] || ( '/batches' === $needle && false !== strpos( $row['path'], '/batches' ) ) ) );
}

function background_row(): Indexer {
	wp_test_reset( own_options() );
	$queue = new IndexQueue();
	$id    = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 321, 'url' => 'https://example.com/shop/',
		'page_title' => '', 'language' => 'it', 'priority' => 50,
		'payload' => wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫Shop', 'cache' => [ 'siteId' => '00000000-0000-4000-8000-000000000001', 'hash' => str_repeat( 'a', 64 ) ] ] ] ) ] );
	$queue->track_refill( (int) $id );

	return new Indexer( '', '', $queue );
}

function job_body( array $extra = [] ): array {
	return array_merge( [ 'sourceLang' => 'en', 'targetLang' => 'it', 'purpose' => 'background_refresh' ], $extra );
}

// --- A background job on a site with own providers keeps them and its purpose ---
$indexer = background_row();
with_own_providers();
$indexer->step_push( new ReadinessBudget() );
$created = own_posts( '/v1/translation/jobs' );
check_own( 1 === count( $created ) && 'background_refresh' === ( $created[0]['body']['purpose'] ?? '' ), 'A background job with own providers still names its purpose' );
check_own( own_chain() === ( $created[0]['body']['byok'] ?? null ), 'A background job keeps the own providers the addon added' );
$batches = own_posts( '/batches' );
check_own( 1 === count( $batches ) && own_chain() === ( $batches[0]['body']['byok'] ?? null ), 'A background batch keeps the own providers the addon added' );
check_own( ! isset( $batches[0]['body']['purpose'] ), 'The purpose is never sent on a batch' );

// --- Without own providers: purpose and no key ---
$indexer = background_row();
$indexer->step_push( new ReadinessBudget() );
$created = own_posts( '/v1/translation/jobs' );
check_own( 1 === count( $created ) && 'background_refresh' === ( $created[0]['body']['purpose'] ?? '' ) && ! isset( $created[0]['body']['byok'] ), 'A background job without own providers names its purpose and carries no key' );

// --- A missing purpose echo still holds back the background batches, with or without own providers ---
foreach ( [ true, false ] as $own ) {
	$indexer = background_row();
	if ( $own ) {
		with_own_providers();
	}
	$GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 201 ], 'body' => wp_json_encode( [ 'jobId' => '00000000-0000-4000-8000-000000000099' ] ) ] ];
	$indexer->step_push( new ReadinessBudget() );
	check_own( [] === own_posts( '/batches' ), 'A job that does not echo the purpose receives no background batch' . ( $own ? ' (own providers)' : '' ) );
	check_own( 1 === count( own_posts( '/v1/translation/jobs/00000000-0000-4000-8000-000000000099/cancel' ) ), 'A job that does not echo the purpose is cancelled' . ( $own ? ' (own providers)' : '' ) );
}

// --- The Pro-required resend keeps the key and the purpose ---
wp_test_reset( own_options() );
$GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 403 ], 'body' => wp_json_encode( [ 'error' => 'pro_required' ] ) ] ];
TranslationProRetry::post( '/v1/translation/jobs', job_body( [ 'byok' => own_chain(), 'aiSettings' => [ 'custom_tone' => 'warm' ] ] ), 5, 'it', true );
$created = own_posts( '/v1/translation/jobs' );
check_own( 2 === count( $created ), 'A Pro-required refusal is resent once' );
check_own( isset( $created[1] ) && own_chain() === ( $created[1]['body']['byok'] ?? null ) && 'background_refresh' === ( $created[1]['body']['purpose'] ?? '' ), 'The Pro-required resend keeps the own providers and the purpose' );

// --- The cache revision floor retry keeps the key and the purpose ---
wp_test_reset( own_options() );
$GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 409 ], 'body' => wp_json_encode( [ 'code' => 'cache_revision_conflict', 'floor' => [ 'settingsVersion' => 4, 'lexiconVersion' => 2 ] ] ) ] ];
TranslationProRetry::post( '/v1/translation/jobs', job_body( [ 'byok' => own_chain() ] ), 5, 'it', true );
$created = own_posts( '/v1/translation/jobs' );
check_own( 2 === count( $created ), 'A cache revision conflict is retried once' );
check_own( isset( $created[1] ) && own_chain() === ( $created[1]['body']['byok'] ?? null ) && 'background_refresh' === ( $created[1]['body']['purpose'] ?? '' ), 'The cache revision retry keeps the own providers and the purpose' );

// --- The quota handoff treats a background job like a normal job ---
$quota_refusal = [ 'response' => [ 'code' => 429 ], 'body' => wp_json_encode( [ 'error' => 'Translation quota reached', 'code' => 'limit_exceeded' ] ) ];
$handoff = static function ( bool $background, bool $active ) use ( $quota_refusal ): array {
	wp_test_reset( own_options() );
	QuotaHandoff::set_enabled( true );
	if ( $active ) {
		QuotaHandoff::activate( null );
	}
	wp_test_set_filter( 'translatewp_quota_handoff_providers', static fn( $providers, $language = '' ) => 'it' === $language ? own_chain() : $providers );
	$GLOBALS['wp_test_translation_job_responses'] = $active ? [] : [ $quota_refusal ];
	$body = $background ? job_body() : [ 'sourceLang' => 'en', 'targetLang' => 'it' ];
	TranslationProRetry::post( '/v1/translation/jobs', $body, 5, 'it', $background );

	return array_map(
		static function ( array $row ): array {
			unset( $row['body']['purpose'], $row['body']['cacheVersions'] );
			return $row;
		},
		own_posts( '/v1/translation/jobs' )
	);
};
foreach ( [ true, false ] as $active ) {
	$normal     = $handoff( false, $active );
	$background = $handoff( true, $active );
	$label      = $active ? 'while the handoff is active' : 'after a quota refusal';
	check_own( $normal === $background, 'A background job switches to the own providers exactly like a normal job ' . $label );
	check_own( own_chain() === ( end( $background )['body']['byok'] ?? null ), 'A background job ends on the own providers ' . $label );
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "BackgroundOwnProvidersTest: all assertions passed\n";
