<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\KeepWarm;

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

function rewarm_test_setup(): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_options_store']['site'] = [
		'id'            => '00000000-0000-4000-8000-000000000001',
		'token'         => 'site-token',
		'home_url'      => 'https://example.com',
		'account_label' => 'owner@example.com',
	];
	$GLOBALS['wp_test_options_store']['languages'] = [
		'list' => [
			'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ],
			'fr' => [ 'locale' => 'fr', 'active' => true, 'default' => false ],
		],
	];
	$GLOBALS['wp_test_post_type_map'] = [ 10 => 'page' ];
}

function queue_rows(): array {
	return array_values(
		array_filter(
			$GLOBALS['wp_test_inserted_posts'] ?? [],
			static function ( array $post ): bool {
				return 'twp_index_item' === (string) ( $post['post_type'] ?? '' );
			}
		)
	);
}

function make_post( int $id, string $type = 'page', string $status = 'publish' ): WP_Post {
	$post               = new WP_Post();
	$post->ID           = $id;
	$post->post_type    = $type;
	$post->post_status  = $status;

	return $post;
}

$keepwarm = new KeepWarm( 'translatewp', TRANSLATEWP_VERSION );

// --- a password-protected or excluded page is never re-warmed on save ---
rewarm_test_setup();
$GLOBALS['wp_test_inserted_posts'] = [
	[ 'ID' => 21, 'post_type' => 'page', 'post_password' => 'secret' ],
	[ 'ID' => 22, 'post_type' => 'page', 'meta_input' => [ '_translatewp_exclude' => '1' ] ],
];
$GLOBALS['wp_test_post_type_map'] = [ 21 => 'page', 22 => 'page' ];
$keepwarm->on_save_post( 21, make_post( 21 ) );
$keepwarm->on_save_post( 22, make_post( 22 ) );
assert_true( false === wp_next_scheduled( KeepWarm::REWARM_DEBOUNCE_HOOK ), 'Saving a protected or excluded page must schedule no rewarm' );
$keepwarm->rewarm_saved_object( 21, 'page' );
$keepwarm->rewarm_saved_object( 22, 'page' );
assert_true( [] === queue_rows(), 'A protected or excluded page must never be queued by the rewarm' );

// --- never-warmed object: rewarm enqueues nothing (miss path serves it) ---

rewarm_test_setup();
$keepwarm->on_save_post( 10, make_post( 10 ) );
assert_true( false !== wp_next_scheduled( KeepWarm::REWARM_DEBOUNCE_HOOK ), 'Publish must schedule the rewarm debounce' );

$keepwarm->rewarm_saved_object( 10, 'page' );
assert_true( 0 === count( queue_rows() ), 'Never-warmed objects must not be enqueued by rewarm' );
assert_true( false === wp_next_scheduled( Indexer::DRAIN_HOOK ), 'No rows revived means no drain scheduled' );

// --- previously warmed object: done rows revive to pending extract ---

rewarm_test_setup();
$queue = new IndexQueue();
$queue->enqueue(
	[
		[
			'object_type' => 'page',
			'object_id'   => 10,
			'url'         => 'https://example.com/?p=10',
			'language'    => 'en',
			'priority'    => Indexer::PRIORITY_DEFAULT,
		],
		[
			'object_type' => 'page',
			'object_id'   => 10,
			'url'         => 'https://example.com/?p=10',
			'language'    => 'fr',
			'priority'    => Indexer::PRIORITY_DEFAULT,
		],
	]
);
foreach ( queue_rows() as $row ) {
	update_post_meta( (int) $row['ID'], '_twp_status', IndexQueue::STATUS_DONE );
}

$keepwarm->rewarm_saved_object( 10, 'page' );
$rows = queue_rows();
assert_true( 2 === count( $rows ), 'Rewarm must not duplicate existing rows' );
foreach ( $rows as $row ) {
	assert_true(
		IndexQueue::STATUS_PENDING === (string) ( $row['meta_input']['_twp_status'] ?? '' ),
		'Done rows must revive to pending for re-extraction'
	);
	assert_true(
		'extract' === (string) ( $row['meta_input']['_twp_stage'] ?? '' ),
		'Revived rows must re-enter the extract stage'
	);
}
assert_true( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Revived rows must schedule a drain' );

// --- revision and autosave: nothing scheduled ---

rewarm_test_setup();
$GLOBALS['wp_test_is_revision'] = true;
$keepwarm->on_save_post( 10, make_post( 10 ) );
$GLOBALS['wp_test_is_revision'] = false;
assert_true( false === wp_next_scheduled( KeepWarm::REWARM_DEBOUNCE_HOOK ), 'Revisions must not schedule rewarm' );

$GLOBALS['wp_test_is_autosave'] = true;
$keepwarm->on_save_post( 10, make_post( 10 ) );
$GLOBALS['wp_test_is_autosave'] = false;
assert_true( false === wp_next_scheduled( KeepWarm::REWARM_DEBOUNCE_HOOK ), 'Autosaves must not schedule rewarm' );

// --- draft: nothing scheduled ---

rewarm_test_setup();
$keepwarm->on_save_post( 10, make_post( 10, 'page', 'draft' ) );
assert_true( false === wp_next_scheduled( KeepWarm::REWARM_DEBOUNCE_HOOK ), 'Drafts must not schedule rewarm' );

// --- out-of-scope type: nothing scheduled ---

rewarm_test_setup();
$keepwarm->on_save_post( 10, make_post( 10, 'attachment' ) );
assert_true( false === wp_next_scheduled( KeepWarm::REWARM_DEBOUNCE_HOOK ), 'Out-of-scope types must not schedule rewarm' );

// --- unconnected site: rewarm does nothing ---

rewarm_test_setup();
$GLOBALS['wp_test_options_store']['site'] = [ 'id' => '', 'token' => '', 'home_url' => '', 'account_label' => '' ];
$keepwarm->rewarm_saved_object( 10, 'page' );
assert_true( 0 === count( queue_rows() ), 'Unconnected sites must not enqueue rewarm rows' );

if ( $failures > 0 ) {
	fwrite( STDERR, "KeepWarmRewarmTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "KeepWarmRewarmTest ok\n" );
