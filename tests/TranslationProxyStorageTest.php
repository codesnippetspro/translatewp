<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationProxyDedupe;
use TranslateWP\Components\TranslationProxyStorage;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

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

$site_id     = '00000000-0000-4000-8000-000000000001';
$target_lang = 'lv';
$segment     = str_repeat( 'a', 64 );

// --- Claim lifecycle: claim, duplicate, release, reclaim ---
wp_test_reset( [] );
$first = TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment );
assert_true( true === $first, 'First segment claim must succeed' );

$concurrent_loser = TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment );
assert_wp_error_code( $concurrent_loser, 'duplicate_segment' );

TranslationProxyStorage::release_segment( $site_id, $target_lang, $segment );
$retry = TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment );
assert_true( true === $retry, 'Released segment claim must allow retry' );

// --- Claim isolation: other language and other site are independent ---
$other_lang = TranslationProxyStorage::claim_segment( $site_id, 'de', $segment );
assert_true( true === $other_lang, 'Same segment in another language must claim independently' );
$other_site = TranslationProxyStorage::claim_segment( '00000000-0000-4000-8000-000000000002', $target_lang, $segment );
assert_true( true === $other_site, 'Same segment for another site must claim independently' );

// --- Claim expiry: expired claims reclaim (TTL = dedupe window) ---
wp_test_reset( [] );
TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment );
$claim_key = TranslationProxyStorage::segment_claim_key( $site_id, $target_lang, $segment );
assert_true(
	( $GLOBALS['wp_test_transient_expirations'][ $claim_key ] ?? 0 ) === time() + TranslationProxyDedupe::window_seconds(),
	'Claim TTL must equal the dedupe window'
);
$GLOBALS['wp_test_transient_expirations'][ $claim_key ] = time() - 1;
$after_expiry = TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment );
assert_true( true === $after_expiry, 'Expired segment claim must allow a new claim' );

// --- Invalid claim params ---
assert_wp_error_code( TranslationProxyStorage::claim_segment( '', $target_lang, $segment ), 'invalid_parameter' );
assert_wp_error_code( TranslationProxyStorage::claim_segment( $site_id, '', $segment ), 'invalid_parameter' );
assert_wp_error_code( TranslationProxyStorage::claim_segment( $site_id, $target_lang, '' ), 'invalid_parameter' );

// --- Storage failure surfaces as 503 ---
wp_test_reset( [] );
$GLOBALS['wp_test_transient_set_fail'] = true;
assert_wp_error_code( TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment ), 'storage_unavailable' );
$GLOBALS['wp_test_transient_set_fail'] = false;

// --- Rate limit: within limit passes, over limit blocks, keeps counting ---
wp_test_reset( [] );
for ( $i = 1; $i <= 5; $i++ ) {
	$result = TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.10', 5, 60 );
	assert_true( true === $result, "Rate limit request {$i}/5 must pass" );
}
$limited = TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.10', 5, 60 );
assert_wp_error_code( $limited, 'rate_limited' );

// --- Rate limit isolation: another IP and another bucket are independent ---
assert_true(
	true === TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.99', 5, 60 ),
	'Another IP must have its own counter'
);
assert_true(
	true === TranslationProxyStorage::increment_rate_limit( 'status', $site_id, '203.0.113.10', 5, 60 ),
	'Another bucket must have its own counter'
);

// --- Rate limit window expiry resets the counter ---
$rate_key = TranslationProxyStorage::rate_limit_key( 'mutation', $site_id, '203.0.113.10', 60 );
$GLOBALS['wp_test_transient_expirations'][ $rate_key ] = time() - 1;
assert_true(
	true === TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.10', 5, 60 ),
	'Expired window must reset the counter'
);
assert_true( 1 === (int) get_transient( $rate_key ), 'Counter must restart at 1 after the window expires' );

// --- A refused request does not count, and the window does not move ---
wp_test_reset( [] );
$window_key = TranslationProxyStorage::rate_limit_key( 'mutation', $site_id, '203.0.113.20', 60 );
for ( $i = 0; $i < 5; $i++ ) {
	TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.20', 5, 60 );
}
$ends_at = time() + 10;
$GLOBALS['wp_test_transient_expirations'][ $window_key ]                                            = $ends_at;
$GLOBALS['wp_test_transient_expirations'][ TranslationProxyStorage::rate_limit_reset_key( $window_key ) ] = $ends_at;
$GLOBALS['wp_test_transients'][ TranslationProxyStorage::rate_limit_reset_key( $window_key ) ]             = $ends_at;
for ( $i = 0; $i < 3; $i++ ) {
	$refused = TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.20', 5, 60 );
	assert_wp_error_code( $refused, 'rate_limited' );
}
assert_true( 5 === (int) get_transient( $window_key ), 'Refused requests must not add to the counter, got ' . (int) get_transient( $window_key ) );
assert_true( $ends_at === ( $GLOBALS['wp_test_transient_expirations'][ $window_key ] ?? null ), 'Refused requests must not push the window end back' );
$refused_data = is_wp_error( $refused ) ? $refused->get_error_data() : [];
assert_true( isset( $refused_data['retry_after'] ) && $refused_data['retry_after'] >= 1 && $refused_data['retry_after'] <= 10, 'A refusal must carry the seconds until the window ends, got ' . wp_json_encode( $refused_data ) );

wp_test_reset( [] );
$slide_key = TranslationProxyStorage::rate_limit_key( 'mutation', $site_id, '203.0.113.21', 60 );
TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.21', 5, 60 );
$slide_ends = time() + 10;
$GLOBALS['wp_test_transient_expirations'][ $slide_key ]                                            = $slide_ends;
$GLOBALS['wp_test_transient_expirations'][ TranslationProxyStorage::rate_limit_reset_key( $slide_key ) ] = $slide_ends;
$GLOBALS['wp_test_transients'][ TranslationProxyStorage::rate_limit_reset_key( $slide_key ) ]             = $slide_ends;
TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.21', 5, 60 );
assert_true( 2 === (int) get_transient( $slide_key ), 'An accepted request inside the window adds to the counter' );
assert_true( $slide_ends === ( $GLOBALS['wp_test_transient_expirations'][ $slide_key ] ?? null ), 'An accepted request must not push the window end back' );

// --- Invalid rate limit params ---
assert_wp_error_code( TranslationProxyStorage::increment_rate_limit( '', $site_id, '203.0.113.10', 5, 60 ), 'invalid_parameter' );
assert_wp_error_code( TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '', 5, 60 ), 'invalid_parameter' );
assert_wp_error_code( TranslationProxyStorage::increment_rate_limit( 'mutation', $site_id, '203.0.113.10', 0, 60 ), 'invalid_parameter' );

// --- Job language map: remember, read, forget, expiry ---
wp_test_reset( [] );
$job_id = '00000000-0000-4000-8000-0000000000aa';
assert_true( true === TranslationProxyStorage::remember_job_languages( $job_id, 'en', 'lv' ), 'Job languages must store' );
assert_true(
	[ 'sourceLang' => 'en', 'targetLang' => 'lv' ] === TranslationProxyStorage::job_languages( $job_id ),
	'Job languages must read back'
);

TranslationProxyStorage::forget_job_languages( $job_id );
assert_true( null === TranslationProxyStorage::job_languages( $job_id ), 'Forgotten job languages must be gone' );

TranslationProxyStorage::remember_job_languages( $job_id, 'en', 'lv' );
$GLOBALS['wp_test_transient_expirations'][ TranslationProxyStorage::job_lang_key( $job_id ) ] = time() - 1;
assert_true( null === TranslationProxyStorage::job_languages( $job_id ), 'Expired job languages must be gone' );

assert_wp_error_code( TranslationProxyStorage::remember_job_languages( '', 'en', 'lv' ), 'invalid_parameter' );
assert_true( null === TranslationProxyStorage::job_languages( '' ), 'Empty job id must read as null' );

// --- Atomic claim on a persistent object cache (audit hardening) ---
wp_test_reset( [] );
$GLOBALS['wp_test_ext_object_cache'] = true;
$claim_site = '00000000-0000-4000-8000-000000000001';
$first_ext  = TranslationProxyStorage::claim_segment( $claim_site, 'es', str_repeat( 'c', 64 ) );
assert_true( true === $first_ext, 'First claim on object cache succeeds via wp_cache_add' );
$dup_ext = TranslationProxyStorage::claim_segment( $claim_site, 'es', str_repeat( 'c', 64 ) );
assert_wp_error_code( $dup_ext, 'duplicate_segment' );
// Release must clear the object-cache key so a re-claim can succeed.
TranslationProxyStorage::release_segment( $claim_site, 'es', str_repeat( 'c', 64 ) );
$reclaim_ext = TranslationProxyStorage::claim_segment( $claim_site, 'es', str_repeat( 'c', 64 ) );
assert_true( true === $reclaim_ext, 'Released object-cache claim must allow a re-claim' );
$GLOBALS['wp_test_ext_object_cache'] = false;

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationProxyStorageTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslationProxyStorageTest: all assertions passed\n";
