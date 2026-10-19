<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxyRateLimit;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Functionality\Endpoints\TranslationJobs;
use TranslateWP\Functionality\Indexer;

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

function assert_wp_error_code( $result, string $code ): void {
	assert_true( is_wp_error( $result ), "Expected WP_Error for {$code}" );
	if ( is_wp_error( $result ) ) {
		assert_true( $code === $result->get_error_code(), "Expected error code {$code}, got " . $result->get_error_code() );
	}
}

function make_request( string $method, array $params = [] ): WP_REST_Request {
	// A visitor's report always names the page it came from.
	if ( ! array_key_exists( 'url', $params ) ) {
		$params['url'] = home_url( '/about/' );
	}
	return new WP_REST_Request( $method, '/translatewp/v1/translation/miss', $params, [], '' );
}

/**
 * In-memory queue capturing enqueue_push rows so the endpoint tests need no
 * posts backend. The endpoint constructs its own IndexQueue, so this double
 * is injected through the CPT post functions instead: simplest is to track
 * created rows via wp_insert_post capture.
 */
$connected_options = [
	'site'      => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
	'languages' => [
		'list' => [
			'en' => [
				'locale'  => 'en',
				'slug'    => 'en',
				'active'  => true,
				'default' => true,
			],
			'es' => [
				'locale'  => 'es',
				'slug'    => 'es',
				'active'  => true,
				'default' => false,
			],
		],
	],
];

$valid_item = [
	'groupId'       => 'group-1',
	'markerPayload' => '⟪item_0001⟫Shop the collection',
	'contextKey'    => 'div:nth-of-type(1) > p:nth-of-type(1)',
	'contextKind'   => 'text-flow',
];

// --- Unconfigured language rejected ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'de', 'items' => [ $valid_item ] ] ) );
assert_wp_error_code( $result, 'language_not_configured' );

// --- The site's own language is never a translation target ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'en', 'items' => [ $valid_item ] ] ) );
assert_wp_error_code( $result, 'language_not_configured' );

// --- Invalid items rejected ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
foreach ( [
	[],
	array_fill( 0, 41, $valid_item ),
	[ [ 'groupId' => '', 'markerPayload' => 'x', 'contextKey' => 'p', 'contextKind' => 'text-flow' ] ],
	[ [ 'groupId' => 'g', 'markerPayload' => '  ', 'contextKey' => 'p', 'contextKind' => 'text-flow' ] ],
	[ [ 'groupId' => 'g', 'markerPayload' => 'x', 'contextKey' => 'p', 'contextKind' => 'evil-kind' ] ],
] as $bad_items ) {
	$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => $bad_items ] ) );
	assert_true( is_wp_error( $result ), 'Invalid items payload must be rejected: ' . wp_json_encode( $bad_items ? array_keys( $bad_items[0] ?? [] ) : 'empty' ) );
}

// --- Total payload cap: many individually-valid items whose sum exceeds the cap ---
// applies per item now ; the cap skips the items that would tip the running
// total over, it does not fail the whole batch.
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$big_items = [];
for ( $i = 0; $i < 40; $i++ ) {
	// Each payload is unique (index baked into the fixed-width prefix) so
	// dedupe never collapses these into a single accepted item ; the test
	// isolates the total-bytes cap, not the dedupe path.
	$big_items[] = [
		'groupId'       => 'group-' . $i,
		'markerPayload' => '⟪item_0001⟫' . sprintf( '%05d', $i ) . str_repeat( 'a', 7984 ),
		'contextKey'    => 'p',
		'contextKind'   => 'text-flow',
	];
}
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => $big_items ] ) );
assert_true( ! is_wp_error( $result ), 'A batch with some items over the total-bytes cap must still accept the rest, got ' . wp_json_encode( $result ) );
// With 8000 bytes/item and a 262144 cap, 32 items fit before the 33rd tips
// the running total over ; that item and every one after it are skipped.
assert_true( 32 === ( $result['accepted'] ?? 0 ) && 8 === ( $result['skipped'] ?? 0 ), 'Expected 32 accepted and 8 skipped, got ' . wp_json_encode( $result ) );
$rows = array_values( array_filter( $GLOBALS['wp_test_inserted_posts'] ?? [], static fn ( $post ) => 'twp_index_item' === (string) ( $post['post_type'] ?? '' ) ) );
assert_true( 1 === count( $rows ), 'A miss report queues one row for the page and language, queued ' . count( $rows ) );
$queued_items = json_decode( \TranslateWP\Components\IndexQueue::decode_payload( (string) $rows[0]['post_content'] ), true );
assert_true( is_array( $queued_items ) && 32 === count( $queued_items ), 'The page row carries the 32 accepted groups, got ' . ( is_array( $queued_items ) ? count( $queued_items ) : 'none' ) );

// --- A later miss on the same page and language merges into the pending row ---
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ [ 'groupId' => 'group-late', 'markerPayload' => '⟪item_0001⟫Late arrival', 'contextKey' => 'p', 'contextKind' => 'text-flow' ] ] ] ) );
assert_true( ! is_wp_error( $result ) && 1 === ( $result['accepted'] ?? 0 ), 'A later miss on the same page is accepted, got ' . wp_json_encode( $result ) );
$rows = array_values( array_filter( $GLOBALS['wp_test_inserted_posts'] ?? [], static fn ( $post ) => 'twp_index_item' === (string) ( $post['post_type'] ?? '' ) ) );
assert_true( 1 === count( $rows ), 'A later miss on the same page merges into the existing row, rows: ' . count( $rows ) );
$queued_items = json_decode( \TranslateWP\Components\IndexQueue::decode_payload( (string) $rows[0]['post_content'] ), true );
assert_true( is_array( $queued_items ) && 33 === count( $queued_items ), 'The merged row carries 33 groups, got ' . ( is_array( $queued_items ) ? count( $queued_items ) : 'none' ) );

// --- One oversized item among valid ones is skipped, not fatal to the batch ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$oversized_item = [
	'groupId'       => 'group-oversized',
	'markerPayload' => '⟪item_0001⟫' . str_repeat( 'a', 70000 ),
	'contextKey'    => 'p',
	'contextKind'   => 'text-flow',
];
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item, $oversized_item ] ] ) );
assert_true( ! is_wp_error( $result ), 'A batch with one oversized item must still accept the valid one, got ' . wp_json_encode( $result ) );
assert_true( 1 === ( $result['accepted'] ?? 0 ) && 1 === ( $result['skipped'] ?? 0 ), 'Expected 1 accepted and 1 skipped, got ' . wp_json_encode( $result ) );
$subscription_post = end( $GLOBALS['wp_test_remote_posts'] );
assert_true(
	1 === count( $subscription_post['body']['hashes'] ?? [] ),
	'A skipped item must not be bound into the translation subscription'
);

// --- Long contextKey (over the raised 1024-char cap) is skipped, not fatal ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$long_context_item = [
	'groupId'       => 'group-long-key',
	'markerPayload' => '⟪item_0001⟫Shop the collection',
	'contextKey'    => str_repeat( 'div:nth-of-type(1) > ', 60 ),
	'contextKind'   => 'text-flow',
];
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item, $long_context_item ] ] ) );
assert_true( ! is_wp_error( $result ), 'A batch with one long-contextKey item must still accept the valid one, got ' . wp_json_encode( $result ) );
assert_true( 1 === ( $result['accepted'] ?? 0 ) && 1 === ( $result['skipped'] ?? 0 ), 'Expected 1 accepted and 1 skipped, got ' . wp_json_encode( $result ) );

// --- Multiple invalid items with nothing valid alongside them: 400 ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$result = $jobs->report_misses( make_request( 'POST', [
	'language' => 'es',
	'items'    => [
		[ 'groupId' => '', 'markerPayload' => 'x', 'contextKey' => 'p', 'contextKind' => 'text-flow' ],
		[ 'groupId' => 'g', 'markerPayload' => 'x', 'contextKey' => 'p', 'contextKind' => 'evil-kind' ],
	],
] ) );
assert_wp_error_code( $result, 'invalid_parameter' );
assert_true( 400 === ( is_wp_error( $result ) ? ( $result->get_error_data()['status'] ?? 0 ) : 0 ), 'A batch where every item is invalid must be a 400' );

// --- Valid miss: fingerprint recomputed server-side, queued at miss priority ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( ! is_wp_error( $result ), 'Valid miss must be accepted' );
assert_true( 1 === ( $result['accepted'] ?? 0 ) && 0 === ( $result['deduped'] ?? -1 ), 'One accepted, none deduped, got ' . wp_json_encode( $result ) );

$queued = $GLOBALS['wp_test_inserted_posts'] ?? [];
assert_true( 1 === count( $queued ), 'Exactly one queue row must be inserted' );
$row  = $queued[0];
$meta = $row['meta_input'];
assert_true( 'miss' === $meta['_twp_object_type'], 'Row must be a miss row' );
assert_true( 'push' === $meta['_twp_stage'] && 'pending' === $meta['_twp_status'], 'Miss row must be pending push' );
assert_true( 10 === $meta['_twp_priority'], 'Miss row must use the miss priority' );

$payload = json_decode( IndexQueue::decode_payload( (string) $row['post_content'] ), true );
$expected_hash = TranslationFingerprint::compute_hash(
	$valid_item['markerPayload'],
	TranslationProxySettings::default_locale(),
	'es',
	$valid_item['contextKind'],
	TranslationProxySettings::ai_settings()
);
assert_true( $expected_hash === ( $payload[0]['cache']['hash'] ?? '' ), 'Queued hash must equal the server-recomputed fingerprint' );
assert_true( '00000000-0000-4000-8000-000000000001' === ( $payload[0]['cache']['siteId'] ?? '' ), 'Queued row must carry the site id' );
assert_true( $valid_item['markerPayload'] === ( $payload[0]['markerPayload'] ?? '' ), 'Queued payload must carry the submitted content' );
assert_true( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Accepted miss must schedule a drain' );
$subscription_post = end( $GLOBALS['wp_test_remote_posts'] );
assert_true(
	'/v1/translation/cache/subscriptions' === ( $subscription_post['path'] ?? '' ),
	'Accepted miss must mint a translation subscription'
);
assert_true(
	[ 'language' => 'es', 'hashes' => [ $expected_hash ] ] === ( $subscription_post['body'] ?? [] ),
	'Subscription mint must contain only the recomputed valid fingerprint'
);
assert_true(
	isset( $result['subscription']['url'], $result['subscription']['expiresAt'] ),
	'Accepted miss must return sanitized subscription metadata'
);
assert_true( false === strpos( wp_json_encode( $result ), 'secret-site-token' ), 'Miss response must never expose the Site bearer token' );

// --- Miss page title is stored on the queue row for the context block ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$result = $jobs->report_misses( make_request( 'POST', [
	'language'  => 'es',
	'pageTitle' => 'Salle principale',
	'items'     => [ $valid_item ],
] ) );
assert_true( ! is_wp_error( $result ) && 1 === ( $result['accepted'] ?? 0 ), 'Valid miss with a page title must be accepted' );
$title_row = ( $GLOBALS['wp_test_inserted_posts'] ?? [] )[0] ?? null;
assert_true(
	null !== $title_row && 'Salle principale' === ( $title_row['meta_input']['_twp_page_title'] ?? '' ),
	'Miss row must store the page title for the context block, got ' . wp_json_encode( $title_row['meta_input']['_twp_page_title'] ?? null )
);

// --- A Customizer preview or admin page url is accepted for translation but
//     never recorded against a page, same as any other invalid url ---
foreach ( [
	'https://example.com/about/?customize_changeset_uuid=abc-123',
	'https://example.com/wp-admin/customize.php',
	'https://example.com/wp-login.php',
] as $unindexable_url ) {
	wp_test_reset( $connected_options );
	$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
	$result = $jobs->report_misses( make_request( 'POST', [
		'language' => 'es',
		'url'      => $unindexable_url,
		'items'    => [ $valid_item ],
	] ) );
	assert_true( ! is_wp_error( $result ) && 1 === ( $result['accepted'] ?? 0 ), "Miss on {$unindexable_url} must still queue the segment for translation" );
	$url_row = ( $GLOBALS['wp_test_inserted_posts'] ?? [] )[0] ?? null;
	assert_true(
		null !== $url_row && '' === ( $url_row['meta_input']['_twp_url'] ?? null ),
		"Miss on {$unindexable_url} must never be recorded as a page url, got " . wp_json_encode( $url_row['meta_input']['_twp_url'] ?? null )
	);
}

// --- An ordinary same-host https url is recorded as the page ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$result = $jobs->report_misses( make_request( 'POST', [
	'language' => 'es',
	'url'      => 'https://example.com/about/',
	'items'    => [ $valid_item ],
] ) );
assert_true( ! is_wp_error( $result ) && 1 === ( $result['accepted'] ?? 0 ), 'Miss with an ordinary page url must be accepted' );
$about_row = ( $GLOBALS['wp_test_inserted_posts'] ?? [] )[0] ?? null;
assert_true(
	null !== $about_row && 'https://example.com/about' === ( $about_row['meta_input']['_twp_url'] ?? '' ),
	'An ordinary page url must be recorded canonicalized, got ' . wp_json_encode( $about_row['meta_input']['_twp_url'] ?? null )
);

// --- Client-supplied hash is ignored (server recompute is authoritative) ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$forged = $valid_item + [ 'hash' => str_repeat( 'f', 64 ) ];
$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $forged ] ] ) );
assert_true( ! is_wp_error( $result ) && 1 === ( $result['accepted'] ?? 0 ), 'Forged-hash item still accepted (hash ignored)' );
$payload = json_decode( IndexQueue::decode_payload( (string) ( $GLOBALS['wp_test_inserted_posts'][0]['post_content'] ?? '' ) ), true );
assert_true( str_repeat( 'f', 64 ) !== ( $payload[0]['cache']['hash'] ?? '' ), 'Client hash must NEVER reach the queue' );

// --- Same segment twice: proxy claim dedupes the second report ---
wp_test_reset( $connected_options );
$jobs   = new TranslationJobs( 'translatewp', '0.0.0-test' );
$first  = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
$second = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( 1 === ( $first['accepted'] ?? 0 ), 'First report accepted' );
assert_true( 0 === ( $second['accepted'] ?? -1 ) && 1 === ( $second['deduped'] ?? 0 ), 'Second report must dedupe, got ' . wp_json_encode( $second ) );
assert_true( 1 === count( $GLOBALS['wp_test_inserted_posts'] ?? [] ), 'Dedupe must not insert a second queue row' );
assert_true(
	isset( $second['subscription'] ),
	'A deduped segment already claimed by another request must still receive a subscription'
);
$deduped_subscription_post = end( $GLOBALS['wp_test_remote_posts'] );
assert_true(
	[ $expected_hash ] === ( $deduped_subscription_post['body']['hashes'] ?? [] ),
	'A deduped valid fingerprint must remain bound into the translation subscription'
);

// --- Invalid or failed subscription responses never reject accepted work ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_subscription_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode(
		[
			'url'       => 'https://attacker.example/events?token=stolen',
			'expiresAt' => gmdate( 'c', time() + 300 ),
		]
	),
];
$unsafe_subscription = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( 1 === ( $unsafe_subscription['accepted'] ?? 0 ), 'Unsafe subscription metadata must not reject accepted work' );
assert_true( ! isset( $unsafe_subscription['subscription'] ), 'A cross-origin subscription URL must be omitted' );

wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_subscription_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => wp_json_encode(
		[
			'url'       => 'https://api.translatewp.ai/v1/translation/cache/events?token=opaque-token',
			'expiresAt' => 'not-a-time',
		]
	),
];
$invalid_expiry = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( 1 === ( $invalid_expiry['accepted'] ?? 0 ), 'Invalid expiry metadata must not reject accepted work' );
assert_true( ! isset( $invalid_expiry['subscription'] ), 'Invalid expiry metadata must be omitted' );

wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_subscription_response'] = new WP_Error( 'remote_error', 'mint unavailable' );
$unavailable_subscription = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( 1 === ( $unavailable_subscription['accepted'] ?? 0 ), 'Mint failure must not reject accepted work' );
assert_true( ! isset( $unavailable_subscription['subscription'] ), 'Mint failure must omit subscription metadata' );

// --- Enqueue failure releases the claim so a second miss re-enqueues ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_insert_post_fail'] = true;
$failed = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( 0 === ( $failed['accepted'] ?? -1 ) && 1 === ( $failed['deduped'] ?? 0 ), 'Enqueue failure must dedupe the report, got ' . wp_json_encode( $failed ) );
assert_true( [] === ( $GLOBALS['wp_test_inserted_posts'] ?? [] ), 'Enqueue failure must not insert a queue row' );

$expected_hash = TranslationFingerprint::compute_hash(
	$valid_item['markerPayload'],
	TranslationProxySettings::default_locale(),
	'es',
	$valid_item['contextKind'],
	TranslationProxySettings::ai_settings()
);
$claim_key = \TranslateWP\Components\TranslationProxyStorage::segment_claim_key(
	'00000000-0000-4000-8000-000000000001',
	'es',
	$expected_hash
);
assert_true( false === get_transient( $claim_key ), 'Enqueue failure must release the dedupe claim' );

$GLOBALS['wp_test_insert_post_fail'] = false;
$retried = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( 1 === ( $retried['accepted'] ?? 0 ), 'A second miss after the release must re-enqueue, got ' . wp_json_encode( $retried ) );
assert_true( 1 === count( $GLOBALS['wp_test_inserted_posts'] ?? [] ), 'The retried miss must insert exactly one queue row' );

// --- Mutation rate limit: one unit per CALL, not per item ---
// A call with many items must cost the same single unit as a call with one,
// so the mutation ceiling (raised for coalesced miss reports) is the number
// of CALLS a visitor IP may make per minute, never the number of items in
// them.
wp_test_reset( $connected_options );
$jobs           = new TranslationJobs( 'translatewp', '0.0.0-test' );
$mutation_limit = TranslationProxyRateLimit::limit_for_bucket( TranslationProxyRateLimit::BUCKET_MUTATION );
assert_true( 300 === $mutation_limit, 'MUTATION_MAX must be raised to 300, got ' . $mutation_limit );

for ( $i = 0; $i < $mutation_limit - 1; $i++ ) {
	$item   = [
		'groupId'       => 'rl-' . $i,
		'markerPayload' => '⟪item_0001⟫rate limit payload ' . $i,
		'contextKey'    => 'p',
		'contextKind'   => 'text-flow',
	];
	$result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $item ] ] ) );
	assert_true( ! is_wp_error( $result ), "Call {$i} within the mutation limit must pass" );
}

$bulk_items = [];
for ( $i = 0; $i < 40; $i++ ) {
	$bulk_items[] = [
		'groupId'       => 'bulk-' . $i,
		'markerPayload' => '⟪item_0001⟫bulk payload ' . $i,
		'contextKey'    => 'p',
		'contextKind'   => 'text-flow',
	];
}
$bulk_result = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => $bulk_items ] ) );
assert_true(
	! is_wp_error( $bulk_result ) && 40 === ( $bulk_result['accepted'] ?? 0 ),
	'A single 40-item call must still cost exactly one unit and be accepted as the last call before the limit, got ' . wp_json_encode( $bulk_result )
);

$over_limit_item = [
	'groupId'       => 'over-limit',
	'markerPayload' => '⟪item_0001⟫over limit payload',
	'contextKey'    => 'p',
	'contextKind'   => 'text-flow',
];
$over_limit = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $over_limit_item ] ] ) );
assert_true( $over_limit instanceof WP_REST_Response && 429 === $over_limit->get_status() && (int) ( $over_limit->get_headers()['Retry-After'] ?? 0 ) > 0, 'A report over the request limit must be refused with 429 and Retry-After, got ' . wp_json_encode( $over_limit ) );

// --- A report for another host is refused, not stored without a page ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$off_site = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'url' => 'https://other.example/page/', 'items' => [ $valid_item ] ] ) );
assert_wp_error_code( $off_site, 'invalid_page_url' );
$no_url = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'url' => '', 'items' => [ $valid_item ] ] ) );
assert_wp_error_code( $no_url, 'invalid_page_url' );
// A file is not a page: scripts, data and robots files reported by a visitor's
// browser never become queue rows.
foreach ( [ '/locales/fr/translation.json', '/llms.txt', '/robots.txt', '/wp-content/app.js', '/feed.xml', '/sitemap_index.xml', '/image.png' ] as $file ) {
	$as_page = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'url' => home_url( $file ), 'items' => [ $valid_item ] ] ) );
	assert_wp_error_code( $as_page, 'invalid_page_url' );
}
$html_page = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'url' => home_url( '/about/index.html' ), 'items' => [ $valid_item ] ] ) );
assert_true( ! is_wp_error( $html_page ) || 'invalid_page_url' !== $html_page->get_error_code(), 'An .html address is still a page' );

// --- A miss item is one group of a page: one over 16 KB is skipped ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$long_item = $valid_item;
$long_item['markerPayload'] = '⟪item_0001⟫' . str_repeat( 'b', 17000 );
$too_long = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $long_item ] ] ) );
assert_wp_error_code( $too_long, 'invalid_parameter' );

// --- One client's items are budgeted: 400 in ten minutes, then the report is refused ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$fill = [];
for ( $i = 0; $i < 40; $i++ ) {
	$fill[] = [
		'groupId'       => 'fill-' . $i,
		'markerPayload' => '⟪item_0001⟫Late segment ' . $i,
		'contextKey'    => 'p',
		'contextKind'   => 'text-flow',
	];
}
for ( $round = 0; $round < 10; $round++ ) {
	$round_items = array_map(
		static function ( array $item ) use ( $round ): array {
			$item['markerPayload'] .= ' round ' . $round;
			return $item;
		},
		$fill
	);
	$within = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => $round_items ] ) );
	assert_true( ! is_wp_error( $within ), 'Report ' . ( $round + 1 ) . ' of ten within the item budget must be accepted, got ' . wp_json_encode( $within ) );
}
$over_budget = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( $over_budget instanceof WP_REST_Response && 429 === $over_budget->get_status(), 'A report over the item budget must be refused with 429, got ' . wp_json_encode( $over_budget ) );
$retry_after = $over_budget instanceof WP_REST_Response ? (int) ( $over_budget->get_headers()['Retry-After'] ?? 0 ) : 0;
assert_true( $retry_after > 0 && $retry_after <= TranslationProxyRateLimit::MISS_ITEMS_WINDOW_SECONDS, 'The refusal must say when to retry, got Retry-After ' . $retry_after );
assert_true( 'rate_limited' === ( $over_budget instanceof WP_REST_Response ? ( $over_budget->get_data()['code'] ?? '' ) : '' ), 'The refusal keeps the rate_limited code' );

// --- Re-reporting text that is already queued costs no item budget ---
wp_test_reset( $connected_options );
$jobs = new TranslationJobs( 'translatewp', '0.0.0-test' );
$first_report = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => $fill ] ) );
assert_true( ! is_wp_error( $first_report ) && 40 === ( $first_report['accepted'] ?? 0 ), 'The first report queues its forty items' );
for ( $round = 0; $round < 20; $round++ ) {
	$repeat = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => $fill ] ) );
	assert_true( is_array( $repeat ) && 40 === ( $repeat['deduped'] ?? 0 ), 'Repeat report ' . ( $round + 1 ) . ' must be answered as already queued, got ' . wp_json_encode( $repeat ) );
}
$fresh = $jobs->report_misses( make_request( 'POST', [ 'language' => 'es', 'items' => [ $valid_item ] ] ) );
assert_true( is_array( $fresh ) && 1 === ( $fresh['accepted'] ?? 0 ), 'New text after repeated reports must still be accepted, got ' . wp_json_encode( $fresh ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "MissProxyTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "MissProxyTest: all assertions passed\n";
