<?php
/**
 * QueueMetadataTest against a real server. Included with $mysqli connected as
 * root to a throwaway instance; tables use WordPress core's posts and postmeta
 * schema, the session drops the SQL modes wpdb drops.
 */

declare(strict_types=1);

use TranslateWP\Components\QueueMetadata;

/** @var mysqli $mysqli */
$mysqli->set_charset( 'utf8mb4' );
$version = (string) $mysqli->query( 'SELECT VERSION()' )->fetch_row()[0];
$modes   = array_diff(
	explode( ',', (string) $mysqli->query( 'SELECT @@SESSION.sql_mode' )->fetch_row()[0] ),
	[ 'NO_ZERO_DATE', 'ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'TRADITIONAL', 'ANSI' ]
);
$mysqli->query( "SET SESSION sql_mode = '" . $mysqli->real_escape_string( implode( ',', $modes ) ) . "'" );
$mysqli->query( 'CREATE DATABASE wp' );
$mysqli->select_db( 'wp' );
$collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
$mysqli->query(
	"CREATE TABLE wp_postmeta (
	meta_id bigint(20) unsigned NOT NULL auto_increment,
	post_id bigint(20) unsigned NOT NULL default '0',
	meta_key varchar(255) default NULL,
	meta_value longtext,
	PRIMARY KEY  (meta_id),
	KEY post_id (post_id),
	KEY meta_key (meta_key(191))
) {$collate}"
);
$mysqli->query(
	"CREATE TABLE wp_posts (
	ID bigint(20) unsigned NOT NULL auto_increment,
	post_author bigint(20) unsigned NOT NULL default '0',
	post_date datetime NOT NULL default '0000-00-00 00:00:00',
	post_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
	post_content longtext NOT NULL,
	post_title text NOT NULL,
	post_excerpt text NOT NULL,
	post_status varchar(20) NOT NULL default 'publish',
	comment_status varchar(20) NOT NULL default 'open',
	ping_status varchar(20) NOT NULL default 'open',
	post_password varchar(255) NOT NULL default '',
	post_name varchar(200) NOT NULL default '',
	to_ping text NOT NULL,
	pinged text NOT NULL,
	post_modified datetime NOT NULL default '0000-00-00 00:00:00',
	post_modified_gmt datetime NOT NULL default '0000-00-00 00:00:00',
	post_content_filtered longtext NOT NULL,
	post_parent bigint(20) unsigned NOT NULL default '0',
	guid varchar(255) NOT NULL default '',
	menu_order int(11) NOT NULL default '0',
	post_type varchar(20) NOT NULL default 'post',
	post_mime_type varchar(100) NOT NULL default '',
	comment_count bigint(20) NOT NULL default '0',
	PRIMARY KEY  (ID),
	KEY post_name (post_name(191)),
	KEY type_status_date (post_type,post_status,post_date,ID),
	KEY post_parent (post_parent),
	KEY post_author (post_author),
	KEY type_status_author (post_type,post_status,post_author)
) {$collate}"
);

$post = static function ( string $type, string $status ) use ( $mysqli ): int {
	$mysqli->query( "INSERT INTO wp_posts (post_content, post_title, post_excerpt, to_ping, pinged, post_content_filtered, post_type, post_status) VALUES ('', '', '', '', '', '', '{$type}', '{$status}')" );
	return (int) $mysqli->insert_id;
};
$add_meta = static function ( int $id, string $key, string $value ) use ( $mysqli ): void {
	$stmt = $mysqli->prepare( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' );
	$stmt->bind_param( 'iss', $id, $key, $value );
	$stmt->execute();
};
$meta = static function ( int $id, string $key ) use ( $mysqli ): array {
	$stmt = $mysqli->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ? ORDER BY meta_id' );
	$stmt->bind_param( 'is', $id, $key );
	$stmt->execute();
	return array_column( $stmt->get_result()->fetch_all( MYSQLI_NUM ), 0 );
};
$count_meta = static function () use ( $mysqli ): int {
	return (int) $mysqli->query( 'SELECT COUNT(*) FROM wp_postmeta' )->fetch_row()[0];
};

// Filler so the optimizer weighs the indexes as on a real site.
$values = [];
for ( $i = 0; $i < 4000; $i++ ) {
	$values[] = "('', '', '', '', '', '', " . ( $i % 10 ? "'post', 'publish'" : "'twp_index_item', 'private'" ) . ')';
}
$mysqli->query( 'INSERT INTO wp_posts (post_content, post_title, post_excerpt, to_ping, pinged, post_content_filtered, post_type, post_status) VALUES ' . implode( ',', $values ) );
$mysqli->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) SELECT ID, '_edit_lock', '1' FROM wp_posts" );
$mysqli->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) SELECT ID, '_twp_status', 'done' FROM wp_posts WHERE post_type = 'twp_index_item'" );
$mysqli->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) SELECT ID, '_twp_stage', 'push' FROM wp_posts WHERE post_type = 'twp_index_item'" );
$mysqli->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) SELECT ID, '_thumbnail_id', '7' FROM wp_posts WHERE post_type = 'post'" );

$a     = $post( 'twp_index_item', 'private' );
$b     = $post( 'twp_index_item', 'private' );
$page  = $post( 'page', 'publish' );
$trash = $post( 'twp_index_item', 'trash' );
$add_meta( $a, '_twp_status', 'pending' );
$add_meta( $a, '_twp_dup', 'first' );
$add_meta( $a, '_twp_dup', 'second' );
$add_meta( $a, '_edit_lock', 'keep' );
$add_meta( $a, '_twp_claimed_at', '100' );
$add_meta( $b, '_twp_claimed_at', '200' );
$add_meta( $page, '_twp_status', 'page value' );
$add_meta( $page, '_twp_claimed_at', '300' );
$add_meta( $trash, '_twp_status', 'trash value' );
$add_meta( $trash, '_twp_claimed_at', '400' );
$mysqli->query( 'ANALYZE TABLE wp_posts, wp_postmeta' );

$tricky = "back\\slash \\\\ 'single' \"double\" 100% %s %d \u{1F600} \u{00E9}\n" . str_repeat( 'x', 102400 );
$wpdb   = new QueueMetadataTestDb( $mysqli );
$GLOBALS['wpdb'] = $wpdb;
$before = $count_meta();
$ok     = QueueMetadata::write(
	[
		$a     => [ '_twp_status' => 'claimed', '_twp_dup' => 'same', '_twp_attempts' => 2, '_twp_on' => true, '_twp_off' => false, '_twp_list' => [ 'k' => [ 1, 'two' ] ] ],
		$b     => [ '_twp_status' => 'pending', '_twp_url' => $tricky, '_twp_raw' => serialize( [ 'x' ] ) ],
		$page  => [ '_twp_status' => 'hijack', '_twp_new' => 'hijack' ],
		$trash => [ '_twp_status' => 'hijack', '_twp_new' => 'hijack' ],
	],
	[ '_twp_claimed_at' ]
);
$statements = $wpdb->queries;
qm_assert( true === $ok, 'Real engine: the batch succeeds: ' . $wpdb->last_error );
qm_assert( 4 === count( $statements ), 'Real engine: four statements for a write with removals' );
qm_assert( [ 'claimed' ] === $meta( $a, '_twp_status' ), 'Real engine: an existing key is updated in place' );
qm_assert( [ 'same', 'same' ] === $meta( $a, '_twp_dup' ), 'Real engine: every duplicate row of a key gets the value and none is added' );
qm_assert( [ '2' ] === $meta( $a, '_twp_attempts' ) && [ '1' ] === $meta( $a, '_twp_on' ) && [ '' ] === $meta( $a, '_twp_off' ), 'Real engine: scalars are stored like update_post_meta' );
qm_assert( [ serialize( [ 'k' => [ 1, 'two' ] ] ) ] === $meta( $a, '_twp_list' ), 'Real engine: arrays are serialized' );
qm_assert( [ serialize( serialize( [ 'x' ] ) ) ] === $meta( $b, '_twp_raw' ), 'Real engine: a serialized string is serialized again, as core does' );
qm_assert( [ $tricky ] === $meta( $b, '_twp_url' ), 'Real engine: backslashes, quotes, percent signs, emoji and 100 KB survive byte for byte' );
qm_assert( [ 'pending' ] === $meta( $b, '_twp_status' ), 'Real engine: a missing key is inserted once' );
qm_assert( [ 'keep' ] === $meta( $a, '_edit_lock' ), 'Real engine: unrelated metadata on a queue row is untouched' );
qm_assert( [] === $meta( $a, '_twp_claimed_at' ) && [] === $meta( $b, '_twp_claimed_at' ), 'Real engine: removed keys are gone from the queue rows' );
qm_assert( [ 'page value' ] === $meta( $page, '_twp_status' ) && [ '300' ] === $meta( $page, '_twp_claimed_at' ) && [] === $meta( $page, '_twp_new' ), 'Real engine: an ordinary page is never written' );
qm_assert( [ 'trash value' ] === $meta( $trash, '_twp_status' ) && [ '400' ] === $meta( $trash, '_twp_claimed_at' ) && [] === $meta( $trash, '_twp_new' ), 'Real engine: a queue post in another status is never written' );
qm_assert( $before + 7 - 2 === $count_meta(), 'Real engine: exactly seven rows inserted and two removed' );

// Even when the scope select is wrong, the writes' own joins keep other posts out.
$wpdb->queries        = [];
$wpdb->scope_override = [ $page, $trash ];
$unchanged            = $count_meta();
qm_assert( true === QueueMetadata::write( [ $page => [ '_twp_status' => 'hijack', '_twp_new' => 'x' ], $trash => [ '_twp_status' => 'hijack', '_twp_new' => 'x' ] ], [ '_twp_claimed_at' ] ), 'Real engine: writes against non-queue posts run' );
$wpdb->scope_override = null;
qm_assert( 4 === count( $wpdb->queries ), 'Real engine: the forced writes reached the server' );
qm_assert( [ 'page value' ] === $meta( $page, '_twp_status' ) && [ '300' ] === $meta( $page, '_twp_claimed_at' ) && [ 'trash value' ] === $meta( $trash, '_twp_status' ) && [ '400' ] === $meta( $trash, '_twp_claimed_at' ) && $unchanged === $count_meta(), 'Real engine: the joins alone block writes to a page and to a queue post in another status' );

$wpdb->queries = [];
$again         = $count_meta();
qm_assert( true === QueueMetadata::write( [ $a => [ '_twp_status' => 'claimed', '_twp_on' => true ], $b => [ '_twp_url' => $tricky ] ] ), 'Real engine: a repeated write succeeds' );
qm_assert( $again === $count_meta() && 3 === count( $wpdb->queries ), 'Real engine: a repeated write adds no rows in three statements' );

// The statement count does not grow with rows.
$ids = array_map( 'intval', array_column( $mysqli->query( "SELECT ID FROM wp_posts WHERE post_type = 'twp_index_item' AND post_status = 'private' ORDER BY ID LIMIT 50" )->fetch_all( MYSQLI_NUM ), 0 ) );
$rows = [];
foreach ( $ids as $n => $id ) {
	foreach ( range( 1, 12 ) as $k ) {
		$rows[ $id ][ '_twp_key_' . $k ] = "v{$n}-{$k}";
	}
	$rows[ $id ]['_twp_status'] = 'pending';
}
$wpdb->queries = [];
qm_assert( true === QueueMetadata::write( $rows ), 'Real engine: 50 rows by 13 keys succeed' );
qm_assert( 3 === count( $wpdb->queries ), 'Real engine: 50 rows by 13 keys take three statements' );
qm_assert( [ 'v49-12' ] === $meta( $ids[49], '_twp_key_12' ) && [ 'pending' ] === $meta( $ids[0], '_twp_status' ), 'Real engine: the wide batch is stored' );

// 2.4 MB of quote-heavy values against max_allowed_packet = 1M.
foreach ( $rows as $id => $keys ) {
	foreach ( $keys as $key => $unused ) {
		$rows[ $id ][ $key ] = str_repeat( "'\\", 2048 ) . $id . $key;
	}
}
$wpdb->queries = [];
qm_assert( true === QueueMetadata::write( $rows ), 'Real engine: a 2.4 MB batch fits a 1 MB packet limit: ' . $wpdb->last_error );
qm_assert( [ str_repeat( "'\\", 2048 ) . $ids[7] . '_twp_key_3' ] === $meta( $ids[7], '_twp_key_3' ), 'Real engine: the split batch is stored' );
$split = count( $wpdb->queries );

// A statement failing midway leaves each key old or new, never mixed rows.
$wpdb->queries = [];
$wpdb->fail_at = 4;
$c             = $post( 'twp_index_item', 'private' );
$add_meta( $c, '_twp_status', 'old' );
$add_meta( $c, '_twp_drop', 'old' );
qm_assert( false === QueueMetadata::write( [ $c => [ '_twp_status' => 'new', '_twp_added' => 'new' ] ], [ '_twp_drop' ] ), 'Real engine: a failed update fails the write' );
qm_assert( [ 'old' ] === $meta( $c, '_twp_status' ) && [ 'new' ] === $meta( $c, '_twp_added' ) && [] === $meta( $c, '_twp_drop' ), 'Real engine: after a failed update the existing key is old and the inserted key is new' );
$wpdb->fail_at = 0;

// Index use, explained before the statements run.
$d = $post( 'twp_index_item', 'private' );
$add_meta( $d, '_twp_status', 'pending' );
$add_meta( $d, '_twp_gone', 'x' );
$wpdb->queries        = [];
$wpdb->explain_writes = true;
QueueMetadata::write( [ $a => [ '_twp_status' => 'done', '_twp_fresh' => 'x' ], $d => [ '_twp_status' => 'done', '_twp_fresh' => 'y' ] ], [ '_twp_gone' ] );
$wpdb->explain_writes = false;
$plans = [];
foreach ( $wpdb->plans as $n => $explain ) {
	foreach ( $explain as $row ) {
		$plans[] = sprintf( '%s: %s %s type=%s key=%s rows=%s %s', [ 'delete', 'insert', 'update' ][ $n ], $row['select_type'], $row['table'], $row['type'], $row['key'] ?? 'NULL', $row['rows'] ?? 'NULL', $row['Extra'] ?? '' );
		if ( in_array( $row['table'], [ 'pm', 'p' ], true ) ) {
			qm_assert( 'ALL' !== $row['type'] && in_array( $row['key'], [ 'PRIMARY', 'post_id', 'meta_key' ], true ), "Real engine: the {$n} write reads {$row['table']} through an index" );
		}
	}
}
qm_assert( 3 === count( $wpdb->plans ) && [ 'pending' ] === $meta( $d, '_twp_status' ), 'Real engine: the explained writes were not run' );

echo "QueueMetadataTest: real engine {$version}, split batch took {$split} statements\n  " . implode( "\n  ", $plans ) . "\n";
