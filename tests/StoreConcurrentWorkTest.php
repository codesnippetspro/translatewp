<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\TranslationStoreSync;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.5' );
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
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
			],
		],
	];
}

function page( string $title, string $url ): int {
	$post_id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'publish' ] );
	$GLOBALS['wp_test_url_to_postid'][ $url ]  = $post_id;
	$GLOBALS['wp_test_permalinks'][ $post_id ] = $url;

	return $post_id;
}

// ---------------------------------------------------------------------------
// A page recorded while a back-fill pass is resolving other pages keeps its
// place in the back-fill queue.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$GLOBALS['wp_test_url_to_postid'] = [];
$first_url = 'https://example.com/first';
$later_url = 'https://example.com/later';
$first_id  = page( 'First', $first_url );
$later_id  = page( 'Later', $later_url );

TranslationStore::record_pending( $first_url, $first_id, 'it', 'en', [ [ 'hash' => 'h-first', 'markerPayload' => '⟪item_0001⟫First' ] ] );

$lookup = static function ( string $locale, array $hashes ) use ( $later_url, $later_id ): array {
	TranslationStore::record_pending( $later_url, $later_id, 'it', 'en', [ [ 'hash' => 'h-later', 'markerPayload' => '⟪item_0001⟫Later' ] ] );

	return in_array( 'h-first', $hashes, true ) ? [ 'h-first' => '⟪item_0001⟫Primo' ] : [];
};
TranslationStore::run_backfill_batch( 25, $lookup );

assert_true( 0 === TranslationStore::pending_count( $first_url, 'it', $first_id ), 'The resolved page leaves the pending list' );
assert_true( TranslationStore::has_pending_work(), 'A page recorded during the pass must stay queued for back-fill' );

TranslationStore::run_backfill_batch(
	25,
	static function ( string $locale, array $hashes ): array {
		return in_array( 'h-later', $hashes, true ) ? [ 'h-later' => '⟪item_0001⟫Dopo' ] : [];
	}
);
assert_true( 'stored' === TranslationStore::title_state( $later_url, 'Later', 'it', $later_id ), 'The page recorded during the pass resolves on the next pass' );

// ---------------------------------------------------------------------------
// Pending segments whose queue entry was lost are found and resolved, so the
// page never reads as still translating forever.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$GLOBALS['wp_test_url_to_postid'] = [];
$orphan_url = 'https://example.com/orphan';
$orphan_id  = page( 'Orphan', $orphan_url );

TranslationStore::record_pending( $orphan_url, $orphan_id, 'it', 'en', [ [ 'hash' => 'h-orphan', 'markerPayload' => '⟪item_0001⟫Orphan' ] ] );
delete_option( TranslationStore::WORK_OPTION );
assert_true( 'pending' === TranslationStore::title_state( $orphan_url, 'Orphan', 'it', $orphan_id ), 'Precondition: the orphaned page reads as pending' );

$GLOBALS['wp_test_cache_lookup_items']['h-orphan'] = '⟪item_0001⟫Orfano';
$sync = new TranslationStoreSync( 'translatewp', TRANSLATEWP_VERSION );
$sync->maybe_schedule_backfill();
$sync->run_backfill();

assert_true(
	'stored' === TranslationStore::title_state( $orphan_url, 'Orphan', 'it', $orphan_id ),
	'An orphaned pending page must be re-queued and resolved, got ' . TranslationStore::title_state( $orphan_url, 'Orphan', 'it', $orphan_id )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "StoreConcurrentWorkTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

echo "StoreConcurrentWorkTest passed\n";
