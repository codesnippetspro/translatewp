<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\QueueMetadata;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';
require_once __DIR__ . '/fixtures/queue-metadata-db.php';

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		if ( is_string( $data ) && ( 'b:0;' === $data || false !== @unserialize( $data ) ) ) {
			return serialize( $data );
		}
		return $data;
	}
}

if ( ! function_exists( 'wp_cache_set_posts_last_changed' ) ) {
	function wp_cache_set_posts_last_changed(): void {
		$GLOBALS['qm_last_changed'] = ( $GLOBALS['qm_last_changed'] ?? 0 ) + 1;
	}
}

$failures = 0;

function qm_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		$failures++;
	}
}

function qm_double( ?array $found ): QueueMetadataTestDb {
	$db             = new QueueMetadataTestDb();
	$db->col_result = $found;
	$GLOBALS['wpdb']                 = $db;
	$GLOBALS['wp_test_object_cache'] = [];
	$GLOBALS['qm_last_changed']      = 0;
	return $db;
}

function qm_cache( int $id ): void {
	$GLOBALS['wp_test_object_cache']['post_meta'][ $id ] = [ 'cached' ];
}

function qm_cached( int $id ): bool {
	return isset( $GLOBALS['wp_test_object_cache']['post_meta'][ $id ] );
}

// --- Inputs that need no SQL ---
$db = qm_double( [] );
qm_assert( true === QueueMetadata::write( [] ), 'Empty input is a successful write' );
qm_assert( true === QueueMetadata::write( [], [ '_twp_claimed_at' ] ), 'A removal without rows touches nothing' );
qm_assert( true === QueueMetadata::write( [ 5 => [] ] ), 'A row without keys and no removal touches nothing' );
qm_assert( [] === $db->queries, 'Empty input runs no SQL' );

$too_many = array_fill_keys( range( 1, 51 ), [ '_twp_status' => 'pending' ] );
$invalid  = [
	'more than 50 rows'       => [ $too_many, [] ],
	'zero ID'                 => [ [ 0 => [ '_twp_status' => 'x' ] ], [] ],
	'negative ID'             => [ [ -3 => [ '_twp_status' => 'x' ] ], [] ],
	'string ID'               => [ [ 'abc' => [ '_twp_status' => 'x' ] ], [] ],
	'row is not an array'     => [ [ 5 => 'x' ], [] ],
	'key without prefix'      => [ [ 5 => [ '_edit_lock' => 'x' ] ], [] ],
	'upper case key'          => [ [ 5 => [ '_TWP_status' => 'x' ] ], [] ],
	'key with SQL'            => [ [ 5 => [ "_twp_a' OR 1=1" => 'x' ] ], [] ],
	'integer key'             => [ [ 5 => [ 'x' ] ], [] ],
	'overlong key'            => [ [ 5 => [ '_twp_' . str_repeat( 'a', 251 ) => 'x' ] ], [] ],
	'null value'              => [ [ 5 => [ '_twp_status' => null ] ], [] ],
	'resource value'          => [ [ 5 => [ '_twp_status' => STDIN ] ], [] ],
	'removal without prefix'  => [ [ 5 => [ '_twp_status' => 'x' ] ], [ '_thumbnail_id' ] ],
	'removal is not a string' => [ [ 5 => [ '_twp_status' => 'x' ] ], [ 7 ] ],
	'key written and removed' => [ [ 5 => [ '_twp_status' => 'x' ] ], [ '_twp_status' ] ],
];
foreach ( $invalid as $label => [ $rows, $remove ] ) {
	$db = qm_double( [ 5 ] );
	qm_cache( 5 );
	qm_assert( false === QueueMetadata::write( $rows, $remove ), "Rejects {$label}" );
	qm_assert( [] === $db->queries, "Runs no SQL for {$label}" );
	qm_assert( qm_cached( 5 ) && 0 === $GLOBALS['qm_last_changed'], "Leaves caches alone for {$label}" );
}
$db = qm_double( range( 1, 50 ) );
qm_assert( true === QueueMetadata::write( array_slice( $too_many, 0, 50, true ) ), 'Accepts exactly 50 rows' );

// --- Scoping, statement count and stored values ---
$db = qm_double( [ 11, 12 ] );
foreach ( [ 11, 12, 13 ] as $id ) {
	qm_cache( $id );
}
$ok = QueueMetadata::write(
	[
		11 => [ '_twp_status' => 'claimed', '_twp_on' => true, '_twp_off' => false, '_twp_attempts' => 2, '_twp_list' => [ 'a' => 1 ] ],
		12 => [ '_twp_status' => 'pending' ],
		13 => [ '_twp_status' => 'not a queue row' ],
	],
	[ '_twp_claimed_at', '_twp_claimed_at' ]
);
qm_assert( true === $ok, 'A valid batch succeeds' );
qm_assert( 4 === count( $db->queries ), 'One scope select, one delete, one insert and one update: ' . count( $db->queries ) );
qm_assert( 0 === strpos( $db->queries[0], 'SELECT ID FROM wp_posts WHERE ID IN (11,12,13) AND post_type = ' . "'" . IndexQueue::POST_TYPE . "' AND post_status = 'private'" ), 'The scope select checks type and private status' );
qm_assert( 0 === strpos( $db->queries[1], 'DELETE pm FROM wp_postmeta pm INNER JOIN wp_posts p' ) && false !== strpos( $db->queries[1], "IN (11,12) AND pm.meta_key IN ('_twp_claimed_at')" ), 'The delete covers only queue IDs and the removed key once' );
qm_assert( 0 === strpos( $db->queries[2], 'INSERT INTO wp_postmeta' ) && 0 === strpos( $db->queries[3], 'UPDATE wp_postmeta' ), 'Inserts run before updates' );
foreach ( [ 1, 2, 3 ] as $n ) {
	qm_assert( false === strpos( $db->queries[ $n ], '13' ) && false === strpos( $db->queries[ $n ], 'not a queue row' ), 'A non-queue ID never reaches a write' );
	qm_assert( false !== strpos( $db->queries[ $n ], "p.post_type = 'twp_index_item' AND p.post_status = 'private'" ), 'Every write joins the queue type and status' );
}
qm_assert( false !== strpos( $db->queries[3], "pm.meta_key = '_twp_on' THEN '1'" ) && false !== strpos( $db->queries[3], "pm.meta_key = '_twp_off' THEN ''" ), 'Booleans are stored as 1 and empty' );
qm_assert( false !== strpos( $db->queries[3], "THEN '2'" ) && false !== strpos( $db->queries[3], addslashes( serialize( [ 'a' => 1 ] ) ) ), 'Integers become strings and arrays are serialized' );
qm_assert( ! qm_cached( 11 ) && ! qm_cached( 12 ) && qm_cached( 13 ), 'Only the changed rows lose their meta cache' );
qm_assert( 1 === $GLOBALS['qm_last_changed'], 'Cached post queries are refreshed once' );

$db = qm_double( [] );
qm_cache( 13 );
qm_assert( true === QueueMetadata::write( [ 13 => [ '_twp_status' => 'x' ] ] ), 'IDs that are not queue rows are skipped' );
qm_assert( 1 === count( $db->queries ) && qm_cached( 13 ) && 0 === $GLOBALS['qm_last_changed'], 'Only the scope select runs when no queue row matches' );

// --- Failures ---
$db = qm_double( null );
qm_assert( false === QueueMetadata::write( [ 11 => [ '_twp_status' => 'x' ] ] ), 'A failed scope select fails the write' );
qm_assert( 1 === count( $db->queries ), 'Nothing is written after a failed scope select' );

foreach ( [ 2 => 'delete', 3 => 'insert', 4 => 'update' ] as $at => $label ) {
	$db          = qm_double( [ 11 ] );
	$db->fail_at = $at;
	qm_cache( 11 );
	qm_assert( false === QueueMetadata::write( [ 11 => [ '_twp_status' => 'x' ] ], [ '_twp_claimed_at' ] ), "A failed {$label} fails the write" );
	qm_assert( $at === count( $db->queries ), "No statement runs after a failed {$label}" );
	qm_assert( ! qm_cached( 11 ) && 1 === $GLOBALS['qm_last_changed'], "Caches are cleared after a failed {$label}" );
}

// --- Large values split into statements below 1 MB ---
$db   = qm_double( range( 1, 50 ) );
$big  = [];
$keys = array_map( static fn( int $k ): string => '_twp_key_' . $k, range( 1, 12 ) );
foreach ( range( 1, 50 ) as $id ) {
	$big[ $id ] = array_fill_keys( $keys, str_repeat( "'", 4096 ) );
}
qm_assert( true === QueueMetadata::write( $big ), 'A large batch succeeds' );
$writes = count( $db->queries ) - 1;
qm_assert( $writes >= 4 && 0 === $writes % 2 && $writes <= 20, 'A 2.4 MB batch is split into a few insert/update pairs: ' . $writes );
qm_assert( max( array_map( 'strlen', $db->queries ) ) < 1048576, 'Every statement stays under 1 MB even when escaping doubles the value' );

// --- Real engine ---
$mysqld = queue_metadata_find_mysqld();
if ( null === $mysqld ) {
	echo "QueueMetadataTest: real engine skipped: no MySQL binary\n";
} else {
	$started = queue_metadata_start_server( $mysqld );
	if ( is_string( $started ) ) {
		if ( getenv( 'TWP_TEST_DB_BIN' ) ) {
			qm_assert( false, 'The requested MySQL server did not start: ' . $started );
		} else {
			echo "QueueMetadataTest: real engine skipped: {$started}\n";
		}
	} else {
		[ $mysqli, $stop ] = $started;
		try {
			require __DIR__ . '/fixtures/queue-metadata-engine.php';
		} finally {
			$mysqli->close();
			$stop();
		}
	}
}

if ( $failures > 0 ) {
	fwrite( STDERR, "QueueMetadataTest: {$failures} failure(s)\n" );
	exit( 1 );
}
echo "QueueMetadataTest: all checks passed\n";
