<?php
/**
 * Uninstall deletes queue rows in batches without per-row wp_delete_post (issue 54).
 * Per-row wp_delete_post over thousands of rows ran past max_execution_time in the
 * delete-plugin request, which showed "Deleting..." and then a critical error.
 */

declare(strict_types=1);

use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/uninstall-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$wpdb = $GLOBALS['wpdb'];
$wpdb->seed( 'twp_index_item', 2500 );
$wpdb->seed( 'post', 3 );

Lyfecycle::uninstall();

$queue_meta = 0;
foreach ( array_keys( $wpdb->meta_rows ) as $id ) {
	if ( ! isset( $wpdb->post_rows[ $id ] ) ) {
		$queue_meta += count( $wpdb->meta_rows[ $id ] );
	}
}

$max_batches = (int) ceil( 2500 / 500 );

assert_true( 0 === $wpdb->count_type( 'twp_index_item' ), 'Queue rows remain after uninstall: ' . $wpdb->count_type( 'twp_index_item' ) );
assert_true( 0 === $queue_meta, 'Queue meta remains after uninstall: ' . $queue_meta );
assert_true( 0 === $GLOBALS['uninstall_test_deletes'], 'wp_delete_post was called ' . $GLOBALS['uninstall_test_deletes'] . ' times; uninstall must delete in batches' );
assert_true( ( $wpdb->deletes['wp_posts'] ?? 0 ) <= $max_batches, 'Posts DELETE count ' . ( $wpdb->deletes['wp_posts'] ?? 0 ) . " exceeds {$max_batches}" );
assert_true( ( $wpdb->deletes['wp_postmeta'] ?? 0 ) <= $max_batches, 'Postmeta DELETE count ' . ( $wpdb->deletes['wp_postmeta'] ?? 0 ) . " exceeds {$max_batches}" );
assert_true( 3 === $wpdb->count_type( 'post' ), 'Uninstall must not touch other post types' );
assert_true( 3 === count( $wpdb->meta_rows ), 'Uninstall must not touch meta of other posts' );

// With "Delete all data on uninstall" on, the Lexicon and metric CPTs go the same way.
$GLOBALS['wpdb']                   = new Uninstall_Test_wpdb();
$wpdb                              = $GLOBALS['wpdb'];
$GLOBALS['uninstall_test_deletes'] = 0;
$GLOBALS['uninstall_test_cleaned'] = [];
$GLOBALS['uninstall_test_options'] = [
	'translatewp_advanced' => [ 'delete_on_uninstall' => true ],
	'translatewp_version'  => '0.10.20',
];
$wpdb->seed( 'twp_index_item', 1200 );
$wpdb->seed( 'translatewp_metric', 1001 );
$wpdb->seed( 'lexicon', 40, 2 );
$wpdb->seed( 'page', 2 );

Lyfecycle::uninstall();

foreach ( [ 'twp_index_item', 'translatewp_metric', 'lexicon' ] as $type ) {
	assert_true( 0 === $wpdb->count_type( $type ), "{$type} rows remain after a full wipe" );
}
assert_true( 2 === count( $wpdb->meta_rows ), 'Only the meta of the two pages may remain, found ' . count( $wpdb->meta_rows ) );
assert_true( [] === $wpdb->term_rows, 'Lexicon term relationships remain after a full wipe' );
assert_true( 0 === $GLOBALS['uninstall_test_deletes'], 'Full wipe called wp_delete_post ' . $GLOBALS['uninstall_test_deletes'] . ' times' );
assert_true( ! isset( $GLOBALS['uninstall_test_options']['translatewp_advanced'] ), 'Full wipe must delete the options' );
assert_true( 2 === $wpdb->count_type( 'page' ), 'Full wipe must not touch pages' );
// 1200 queue rows: 3 batches; 1001 metrics: 3 batches; 40 lexicon: 1 batch.
assert_true( ( $wpdb->deletes['wp_posts'] ?? 0 ) <= 7, 'Full wipe posts DELETE count ' . ( $wpdb->deletes['wp_posts'] ?? 0 ) . ' exceeds 7' );

// Every deleted post has its object cache cleared, so no stale cached post survives.
$cleaned = array_unique( $GLOBALS['uninstall_test_cleaned'] );
sort( $cleaned );
assert_true( 1200 + 1001 + 40 === count( $cleaned ), 'clean_post_cache ran for ' . count( $cleaned ) . ' posts, expected ' . ( 1200 + 1001 + 40 ) );

// A posts DELETE that removes nothing must end the loop and report 0 rows.
$GLOBALS['wpdb']                   = new Uninstall_Test_wpdb();
$wpdb                              = $GLOBALS['wpdb'];
$wpdb->seed( 'twp_index_item', 1200 );
$wpdb->posts_delete_cap            = 0;
$GLOBALS['uninstall_test_cleaned'] = [];
$result                            = \TranslateWP\Components\CleanupInventory::delete_posts_of_type( 'twp_index_item' );
assert_true( 0 === $result, "Empty posts DELETE must return 0 rows deleted, got {$result}" );
assert_true( 1 === $wpdb->selects, 'Empty posts DELETE must stop the loop after one batch, ran ' . $wpdb->selects . ' selects' );
assert_true( [] === $GLOBALS['uninstall_test_cleaned'], 'No post cache may be cleared when nothing was deleted' );

// A posts DELETE that removes fewer rows than selected reports the rows actually removed.
$GLOBALS['wpdb']        = new Uninstall_Test_wpdb();
$wpdb                   = $GLOBALS['wpdb'];
$wpdb->seed( 'twp_index_item', 700 );
$wpdb->posts_delete_cap = 300;
$result                 = \TranslateWP\Components\CleanupInventory::delete_posts_of_type( 'twp_index_item' );
$actual                 = 700 - $wpdb->count_type( 'twp_index_item' );
assert_true( $actual === $result, "delete_posts_of_type returned {$result}, but {$actual} rows were deleted" );

if ( $failures > 0 ) {
	fwrite( STDERR, "UninstallBatchTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "UninstallBatchTest ok\n" );
