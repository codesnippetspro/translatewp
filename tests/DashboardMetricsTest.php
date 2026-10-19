<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Functionality\Endpoints\Quota;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.17' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function connected_options(): array {
	return [
		'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
		'languages' => [
			'list' => [
				[ 'locale' => 'de', 'name' => 'German' ],
				[ 'locale' => 'fr', 'name' => 'French' ],
				[ 'locale' => 'es', 'name' => 'Spanish' ],
			],
		],
	];
}

function usage_response( array $payload ): array {
	return [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( $payload ),
	];
}

function enqueue_row( IndexQueue $queue, string $object_type, int $object_id, string $url, string $language ): void {
	$queue->enqueue(
		[
			[
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'url'         => $url,
				'page_title'  => 'Page ' . $object_id,
				'language'    => $language,
				'priority'    => 100,
			],
		]
	);
}

function complete_next_row( IndexQueue $queue ): void {
	$extract = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 1 );
	$queue->store_payload( (int) $extract[0]['id'], (string) wp_json_encode( [ [ 'groupId' => 'g', 'markerPayload' => 'm' ] ] ) );
	$push = $queue->claim_batch( IndexQueue::STAGE_PUSH, 1 );
	$queue->complete( (int) $push[0]['id'] );
	\TranslateWP\Components\TranslationReadiness::record( (int) $push[0]['id'], $push[0]['language'], [ [ 'groupId' => 'seo:seo:title', 'cache' => [ 'hash' => str_repeat( 'a', 64 ) ], 'markerPayload' => '⟪item_0001⟫Hello' ] ] );
	\TranslateWP\Components\TranslationReadiness::refresh( $queue->list_rows(), 0, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Translated' ] );
	\TranslateWP\Components\TranslationReadiness::refresh( $queue->list_rows(), 1, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Translated' ] );
	\TranslateWP\Components\TranslationReadiness::refresh( $queue->list_rows(), 2, static fn() => [ str_repeat( 'a', 64 ) => '⟪item_0001⟫Translated' ] );
}

function find_done_row_id( IndexQueue $queue, string $language ): int {
	foreach ( $queue->list_rows() as $row ) {
		if ( $language === $row['language'] && IndexQueue::STATUS_DONE === $row['status'] ) {
			return (int) $row['id'];
		}
	}
	return 0;
}

// --- the endpoint sources tokens from the API and translations from the queue ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$GLOBALS['wp_test_remote_get_response'] = usage_response(
	[
		'tokensToday'     => 5300000,
		'tokensYesterday' => 2000000,
		'tokensThisMonth' => 7400000,
		'cacheHitsToday'       => 40,
		'cacheMissesToday'     => 4,
		'cacheHitsYesterday'   => 12,
		'cacheMissesYesterday' => 8,
		'daily'           => [
			[ 'date' => '2026-09-10', 'totalTokens' => 2000000, 'cacheHits' => 12, 'cacheMisses' => 8 ],
			[ 'date' => '2026-09-11', 'totalTokens' => 5300000, 'cacheHits' => 40, 'cacheMisses' => 4 ],
		],
	]
);

$queue = new IndexQueue();

enqueue_row( $queue, 'post', 101, 'https://example.com/a', 'de' );
complete_next_row( $queue );
enqueue_row( $queue, 'post', 101, 'https://example.com/a', 'fr' );
complete_next_row( $queue );
enqueue_row( $queue, 'post', 102, 'https://example.com/b', 'de' );
complete_next_row( $queue );
enqueue_row( $queue, 'post', 103, 'https://example.com/c', 'es' );
complete_next_row( $queue );

// Age the Spanish row beyond the 24h window so it drops out of "today" but
// still counts toward distinct pages per language and recent content.
$es_id = find_done_row_id( $queue, 'es' );
assert_true( $es_id > 0, 'precondition: the Spanish row completed' );
update_post_meta( $es_id, '_twp_updated_at', time() - 2 * DAY_IN_SECONDS );

$GLOBALS['wp_test_lexicon_posts'] = [
	10 => [ 'title' => 'Checkout', 'content' => '' ],
	20 => [ 'title' => 'Shipping', 'content' => '' ],
];

$admin    = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$response = $admin->dashboard_metrics( new WP_REST_Request( 'GET', '/translatewp/v1/admin/dashboard-metrics', [], [], '' ) );

assert_true( is_array( $response ), 'dashboard_metrics must return an array' );

// Tokens come from the API usage response.
assert_true( 5300000 === (int) $response['tokensToday'], 'tokensToday must come from the API usage, got ' . (int) $response['tokensToday'] );
assert_true( 7400000 === (int) $response['tokensThisMonth'], 'tokensThisMonth must come from the API usage, got ' . (int) $response['tokensThisMonth'] );
assert_true( 165.0 === (float) $response['tokensVsYesterday'], 'tokensVsYesterday must be computed from today vs yesterday, got ' . (string) $response['tokensVsYesterday'] );
assert_true( 2 === count( $response['tokenTrend'] ), 'tokenTrend must carry one point per API daily bucket, got ' . count( $response['tokenTrend'] ) );
assert_true( 'Sep 11' === (string) $response['tokenTrend'][1]['period'], 'the trend label must format the UTC date, got ' . (string) $response['tokenTrend'][1]['period'] );
assert_true( 5300000 === (int) $response['tokenTrend'][1]['totalTokens'], 'the trend must carry the API daily total' );
assert_true( 40 === (int) $response['tokenTrend'][1]['cachedHits'], 'the trend must carry the API daily cache hits, got ' . (int) $response['tokenTrend'][1]['cachedHits'] );

// Cache figures come from the API usage response: visitors served from the
// cache never touch the browser-recorded metrics.
assert_true( 40 === (int) $response['cacheHitsToday'], 'cacheHitsToday must come from the API usage, got ' . (int) $response['cacheHitsToday'] );
assert_true( 81 === (int) $response['cacheHitRatio'], 'cacheHitRatio must be hits over lookups across two days, got ' . (int) $response['cacheHitRatio'] );

// Translations come from finished queue rows.
assert_true( 3 === (int) $response['translationsToday'], 'translationsToday must count rows finished in the last 24h, got ' . (int) $response['translationsToday'] );

$by_locale = [];
foreach ( $response['languageUsage'] as $entry ) {
	$by_locale[ $entry['locale'] ] = $entry;
}
assert_true( 2 === (int) ( $by_locale['de']['translations'] ?? 0 ), 'German must count two distinct pages, got ' . (int) ( $by_locale['de']['translations'] ?? 0 ) );
assert_true( 'German' === (string) ( $by_locale['de']['name'] ?? '' ), 'the language name must come from the languages config' );
assert_true( 50 === (int) ( $by_locale['de']['share'] ?? 0 ), 'German share of four pages must be 50 percent, got ' . (int) ( $by_locale['de']['share'] ?? 0 ) );
assert_true( 1 === (int) ( $by_locale['fr']['translations'] ?? 0 ), 'French must count one page' );
assert_true( 1 === (int) ( $by_locale['es']['translations'] ?? 0 ), 'Spanish must still count its aged page' );

$content_keys = array_map(
	static function ( $item ) {
		return $item['page'] . '|' . $item['language'];
	},
	$response['recentContent']
);
assert_true( 4 === count( $response['recentContent'] ), 'recentContent must list four distinct page and language pairs, got ' . count( $response['recentContent'] ) );
assert_true( in_array( 'Page 101|German', $content_keys, true ), 'recentContent must include the German page 101' );
assert_true( in_array( 'Page 101|French', $content_keys, true ), 'recentContent must include the French page 101' );
assert_true( in_array( 'Page 103|Spanish', $content_keys, true ), 'recentContent must include the aged Spanish page 103' );

// Lexicon comes from the lexicon store.
assert_true( 2 === count( $response['recentLexicon'] ), 'recentLexicon must reflect the lexicon store, got ' . count( $response['recentLexicon'] ) );
$lexicon_terms = array_map(
	static function ( $entry ) {
		return $entry['term'];
	},
	$response['recentLexicon']
);
assert_true( in_array( 'Checkout', $lexicon_terms, true ) && in_array( 'Shipping', $lexicon_terms, true ), 'recentLexicon must list the seeded terms' );

// --- a disconnected site degrades tokens to zero but still reports translations ---
wp_test_reset(
	[
		'languages' => [
			'list' => [
				[ 'locale' => 'de', 'name' => 'German' ],
			],
		],
	]
);
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$GLOBALS['wp_test_remote_get_response'] = null;

$offline_queue = new IndexQueue();
enqueue_row( $offline_queue, 'post', 201, 'https://example.com/d', 'de' );
complete_next_row( $offline_queue );

$admin         = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$offline        = $admin->dashboard_metrics( new WP_REST_Request( 'GET', '/translatewp/v1/admin/dashboard-metrics', [], [], '' ) );

assert_true( 0 === (int) $offline['tokensToday'], 'a disconnected site reports zero tokens today, got ' . (int) $offline['tokensToday'] );
assert_true( 0 === (int) $offline['tokensThisMonth'], 'a disconnected site reports zero tokens this month' );
assert_true( [] === $offline['tokenTrend'], 'a disconnected site carries an empty token trend' );
assert_true( 0 === (int) $offline['cacheHitsToday'] && 0 === (int) $offline['cacheHitRatio'], 'a disconnected site reports zero cache figures' );
assert_true( 1 === (int) $offline['translationsToday'], 'translations stay sourced from the queue while tokens are unavailable, got ' . (int) $offline['translationsToday'] );
assert_true( 1 === count( $offline['languageUsage'] ), 'the queue still drives language usage while the API is unreachable' );

// --- the account percentage comes from the quota block of the API usage ---
function read_quota( ?array $payload ): array {
	wp_test_reset( connected_options() );
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
	$GLOBALS['wp_test_remote_get_response'] = null === $payload ? new WP_Error( 'http_request_failed', 'unreachable' ) : usage_response( $payload );

	$route = new Quota( 'translatewp', TRANSLATEWP_VERSION );

	return $route->quota( new WP_REST_Request( 'GET', '/translatewp/v1/admin/quota', [], [], '' ) );
}

$off = read_quota( [ 'tokensToday' => 5300000, 'quota' => [ 'percent_available' => 100, 'window_ends_at' => null, 'enforced' => false ] ] );
assert_true( [ 'percentAvailable' => 100, 'windowEndsAt' => null, 'enforced' => false ] === $off, 'enforcement off reads 100 percent available, got ' . wp_json_encode( $off ) );
assert_true( ! array_key_exists( 'tokensToday', $off ), 'the quota route carries no token figure' );

$on = read_quota( [ 'quota' => [ 'percent_available' => 42, 'window_ends_at' => '2026-10-21T00:00:00.000Z', 'enforced' => true ] ] );
assert_true( [ 'percentAvailable' => 42, 'windowEndsAt' => '2026-10-21T00:00:00.000Z', 'enforced' => true ] === $on, 'an enforced bucket passes its percentage through, got ' . wp_json_encode( $on ) );

$over = read_quota( [ 'quota' => [ 'percent_available' => 150, 'window_ends_at' => null, 'enforced' => true ] ] );
assert_true( 100 === $over['percentAvailable'], 'the percentage is clamped to 100, got ' . $over['percentAvailable'] );

$under = read_quota( [ 'quota' => [ 'percent_available' => -5, 'window_ends_at' => null, 'enforced' => true ] ] );
assert_true( 0 === $under['percentAvailable'], 'the percentage is clamped to 0, got ' . $under['percentAvailable'] );

$off_partial = read_quota( [ 'quota' => [ 'percent_available' => 60, 'window_ends_at' => '2026-10-21T00:00:00.000Z', 'enforced' => false ] ] );
assert_true( [ 'percentAvailable' => 100, 'windowEndsAt' => null, 'enforced' => false ] === $off_partial, 'with enforcement off the bucket reads 100 percent whatever the API sends, got ' . wp_json_encode( $off_partial ) );

$no_flag = read_quota( [ 'quota' => [ 'percent_available' => 60, 'window_ends_at' => null ] ] );
assert_true( 100 === $no_flag['percentAvailable'], 'a block without the enforced flag reads 100 percent, got ' . $no_flag['percentAvailable'] );

$fallback = [ 'percentAvailable' => 100, 'windowEndsAt' => null, 'enforced' => false ];
assert_true( $fallback === read_quota( [ 'tokensToday' => 1 ] ), 'a response without the quota block reads 100 percent available' );
assert_true( $fallback === read_quota( null ), 'an unreachable API reads 100 percent available' );

// --- a second read inside the cache window does not call the API again ---
read_quota( [ 'quota' => [ 'percent_available' => 42, 'window_ends_at' => null, 'enforced' => true ] ] );
$GLOBALS['wp_test_remote_get_response'] = usage_response( [ 'quota' => [ 'percent_available' => 7, 'window_ends_at' => null, 'enforced' => true ] ] );
$GLOBALS['wp_test_remote_get_urls']     = [];
$cached = ( new Quota( 'translatewp', TRANSLATEWP_VERSION ) )->quota( new WP_REST_Request( 'GET', '/translatewp/v1/admin/quota', [], [], '' ) );
assert_true( 42 === $cached['percentAvailable'], 'the cached percentage is served inside the window, got ' . $cached['percentAvailable'] );
assert_true( [] === $GLOBALS['wp_test_remote_get_urls'], 'a cached read makes no API call' );

// --- a failed read is not cached ---
read_quota( null );
$GLOBALS['wp_test_remote_get_response'] = usage_response( [ 'quota' => [ 'percent_available' => 42, 'window_ends_at' => null, 'enforced' => true ] ] );
$recovered = ( new Quota( 'translatewp', TRANSLATEWP_VERSION ) )->quota( new WP_REST_Request( 'GET', '/translatewp/v1/admin/quota', [], [], '' ) );
assert_true( 42 === $recovered['percentAvailable'], 'the read after a failure asks the API again, got ' . $recovered['percentAvailable'] );

// --- only an administrator may read the account percentage ---
$routes = ( new Quota( 'translatewp', TRANSLATEWP_VERSION ) )->define_endpoints();
assert_true( 1 === count( $routes ), 'the quota group registers one route' );
$permission = $routes[0]->getPermissionCallback();
$GLOBALS['wp_test_caps'] = [ 'edit_posts' ];
assert_true( true !== call_user_func( $permission ), 'a user without manage_options is refused by the quota route' );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
assert_true( true === call_user_func( $permission ), 'an administrator may read the quota route' );

if ( $failures > 0 ) {
	fwrite( STDERR, "DashboardMetricsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "DashboardMetricsTest: all assertions passed\n";
