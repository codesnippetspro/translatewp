<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
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

/**
 * In-memory queue honoring the IndexQueue contract (claim ordering, stale
 * reclaim, guarded transitions, retry cap). Lets the tests exercise the
 * Indexer orchestration without a real wpdb.
 */
class InMemoryIndexQueue extends IndexQueue {
	public array $rows = [];
	private int $next_id = 1;

	public function enqueue( array $rows ): int {
		$inserted = 0;
		foreach ( $rows as $row ) {
			$key      = $row['object_type'] . '|' . $row['object_id'] . '|' . $row['language'];
			$existing = $this->rows[ $key ] ?? null;

			if ( null === $existing ) {
				$this->rows[ $key ] = [
					'id'          => $this->next_id++,
					'object_type' => (string) $row['object_type'],
					'object_id'   => (int) $row['object_id'],
					'url'         => (string) $row['url'],
					'page_title'  => (string) ( $row['page_title'] ?? '' ),
					'language'    => (string) $row['language'],
					'stage'       => self::STAGE_EXTRACT,
					'status'      => self::STATUS_PENDING,
					'priority'    => (int) $row['priority'],
					'payload'     => null,
					'attempts'    => 0,
					'fail_reason' => '',
					'claimed_at'  => null,
				];
				$inserted++;
				continue;
			}

			if ( self::STATUS_DEAD === $existing['status'] ) {
				$this->rows[ $key ] = array_merge( $existing, [
					'stage'       => self::STAGE_EXTRACT,
					'status'      => self::STATUS_PENDING,
					'priority'    => (int) $row['priority'],
					'payload'     => null,
					'attempts'    => 0,
					'fail_reason' => '',
					'claimed_at'  => null,
					'url'         => (string) $row['url'],
				] );
				$inserted++;
			}
		}

		return $inserted;
	}

	public function claim_rows( array $ids ): array {
		$claimed = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$key = $this->find( $id );
			if ( null === $key || self::STATUS_PENDING !== $this->rows[ $key ]['status'] ) {
				continue;
			}
			$this->rows[ $key ]['status']     = self::STATUS_CLAIMED;
			$this->rows[ $key ]['claimed_at'] = time();
			$row                              = $this->rows[ $key ];
			$claimed[]                        = [
				'id'          => $row['id'],
				'object_type' => $row['object_type'],
				'object_id'   => $row['object_id'],
				'url'         => $row['url'],
				'page_title'  => $row['page_title'] ?? '',
				'language'    => $row['language'],
				'stage'       => $row['stage'],
				'priority'    => $row['priority'],
				'payload'     => $row['payload'],
				'attempts'    => $row['attempts'],
			];
		}
		return $claimed;
	}

	public function enqueue_push( array $row ) {
		$key      = $row['object_type'] . '|' . $row['object_id'] . '|' . $row['language'];
		$existing = $this->rows[ $key ] ?? null;
		if ( null !== $existing ) {
			if ( in_array( $existing['status'], [ self::STATUS_PENDING, self::STATUS_CLAIMED ], true ) ) {
				return false;
			}
			$this->rows[ $key ] = array_merge( $existing, [
				'stage'   => self::STAGE_PUSH,
				'status'  => self::STATUS_PENDING,
				'payload' => (string) $row['payload'],
				'priority' => (int) $row['priority'],
				'attempts' => 0,
			] );
			return $existing['id'];
		}
		$this->rows[ $key ] = [
			'id'          => $this->next_id,
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'url'         => (string) ( $row['url'] ?? '' ),
			'page_title'  => (string) ( $row['page_title'] ?? '' ),
			'language'    => (string) $row['language'],
			'stage'       => self::STAGE_PUSH,
			'status'      => self::STATUS_PENDING,
			'priority'    => (int) $row['priority'],
			'payload'     => (string) $row['payload'],
			'attempts'    => 0,
			'fail_reason' => '',
			'claimed_at'  => null,
		];
		return $this->next_id++;
	}

	public function claim_batch( string $stage, int $limit ): array {
		$stale = time() - self::STALE_CLAIM_SECONDS;
		foreach ( $this->rows as &$row ) {
			if ( self::STATUS_CLAIMED === $row['status'] && null !== $row['claimed_at'] && $row['claimed_at'] <= $stale ) {
				$row['status']     = self::STATUS_PENDING;
				$row['claimed_at'] = null;
			}
		}
		unset( $row );

		$candidates = [];
		foreach ( $this->rows as $key => $row ) {
			if ( self::STATUS_PENDING === $row['status'] && $row['stage'] === $stage ) {
				$candidates[ $key ] = $row;
			}
		}
		uasort( $candidates, static fn( array $left, array $right ): int => [ $left['priority'], $left['id'] ] <=> [ $right['priority'], $right['id'] ] );
		$candidates = array_slice( $candidates, 0, max( 1, $limit ), true );

		$claimed = [];
		foreach ( array_keys( $candidates ) as $key ) {
			$this->rows[ $key ]['status']     = self::STATUS_CLAIMED;
			$this->rows[ $key ]['claimed_at'] = time();
			$row                              = $this->rows[ $key ];
			$claimed[]                        = [
				'id'          => $row['id'],
				'object_type' => $row['object_type'],
				'object_id'   => $row['object_id'],
				'url'         => $row['url'],
				'page_title'  => $row['page_title'] ?? '',
				'language'    => $row['language'],
				'stage'       => $row['stage'],
				'priority'    => $row['priority'],
				'payload'     => $row['payload'],
				'attempts'    => $row['attempts'],
			];
		}

		return $claimed;
	}

	private function find( int $id ): ?string {
		foreach ( $this->rows as $key => $row ) {
			if ( $row['id'] === $id ) {
				return $key;
			}
		}
		return null;
	}

	public function store_payload( int $id, string $payload ): bool {
		$key = $this->find( $id );
		if ( null === $key
			|| self::STATUS_CLAIMED !== $this->rows[ $key ]['status']
			|| self::STAGE_EXTRACT !== $this->rows[ $key ]['stage'] ) {
			return false;
		}

		$this->rows[ $key ] = array_merge( $this->rows[ $key ], [
			'stage'       => self::STAGE_PUSH,
			'status'      => self::STATUS_PENDING,
			'payload'     => $payload,
			'attempts'    => 0,
			'fail_reason' => '',
			'claimed_at'  => null,
		] );

		return true;
	}

	public function append_extraction( int $id, array $items, bool $is_final ): bool {
		$key = $this->find( $id );
		if ( null === $key
			|| self::STATUS_CLAIMED !== $this->rows[ $key ]['status']
			|| self::STAGE_EXTRACT !== $this->rows[ $key ]['stage'] ) {
			return false;
		}

		$buffered = json_decode( (string) ( $this->rows[ $key ]['payload'] ?? '' ), true );
		$buffered = is_array( $buffered ) ? $buffered : [];
		$merged   = array_merge( $buffered, array_values( $items ) );

		if ( $is_final ) {
			if ( ! $merged ) {
				return $this->complete( $id );
			}

			return $this->store_payload( $id, (string) wp_json_encode( array_values( $merged ) ) );
		}

		$this->rows[ $key ]['payload']    = (string) wp_json_encode( array_values( $merged ) );
		$this->rows[ $key ]['claimed_at'] = time();

		return true;
	}

	public function complete( int $id ): bool {
		$key = $this->find( $id );
		if ( null === $key || self::STATUS_CLAIMED !== $this->rows[ $key ]['status'] ) {
			return false;
		}

		$this->rows[ $key ]['status']     = self::STATUS_DONE;
		$this->rows[ $key ]['payload']    = null;
		$this->rows[ $key ]['claimed_at'] = null;

		return true;
	}

	public function release( int $id ): bool {
		$key = $this->find( $id );
		if ( null === $key || self::STATUS_CLAIMED !== $this->rows[ $key ]['status'] ) {
			return false;
		}

		$this->rows[ $key ]['status']     = self::STATUS_PENDING;
		$this->rows[ $key ]['claimed_at'] = null;

		return true;
	}

	public function fail( int $id, string $reason ): bool {
		$key = $this->find( $id );
		if ( null === $key || self::STATUS_CLAIMED !== $this->rows[ $key ]['status'] ) {
			return false;
		}

		$this->rows[ $key ]['attempts']++;
		$this->rows[ $key ]['status']      = $this->rows[ $key ]['attempts'] >= self::MAX_ATTEMPTS ? self::STATUS_DEAD : self::STATUS_PENDING;
		$this->rows[ $key ]['fail_reason'] = substr( $reason, 0, 255 );
		$this->rows[ $key ]['claimed_at']  = null;

		return true;
	}

	public function reprioritize( array $pinned_object_ids, int $pinned_priority, int $default_priority ): void {
		$pinned = array_map( 'intval', $pinned_object_ids );
		foreach ( $this->rows as &$row ) {
			if ( self::STATUS_PENDING !== $row['status'] ) {
				continue;
			}
			$row['priority'] = in_array( $row['object_id'], $pinned, true ) ? $pinned_priority : $default_priority;
		}
		unset( $row );
	}

	public function counts(): array {
		$counts = [];
		foreach ( $this->rows as $row ) {
			$language = $row['language'];
			if ( ! isset( $counts[ $language ] ) ) {
				$counts[ $language ] = [
					'total'           => 0,
					'done'            => 0,
					'dead'            => 0,
					'pending_extract' => 0,
					'pending_push'    => 0,
				];
			}

			$counts[ $language ]['total']++;
			if ( self::STATUS_DONE === $row['status'] ) {
				$counts[ $language ]['done']++;
			} elseif ( self::STATUS_DEAD === $row['status'] ) {
				$counts[ $language ]['dead']++;
			} elseif ( self::STAGE_EXTRACT === $row['stage'] ) {
				$counts[ $language ]['pending_extract']++;
			} else {
				$counts[ $language ]['pending_push']++;
			}
		}

		return $counts;
	}

	public function failed_items( ?string $language = null ): array {
		$items = [];
		foreach ( $this->rows as $row ) {
			if ( self::STATUS_DEAD !== $row['status'] ) {
				continue;
			}
			if ( null !== $language && $row['language'] !== $language ) {
				continue;
			}
			$items[] = [
				'id'          => $row['id'],
				'language'    => $row['language'],
				'url'         => $row['url'],
				'object_type' => $row['object_type'],
				'object_id'   => $row['object_id'],
				'reason'      => $row['fail_reason'],
				'attempts'    => $row['attempts'],
			];
		}
		return $items;
	}

	public function retry_failed( ?string $language = null ): int {
		$revived = 0;
		foreach ( $this->rows as &$row ) {
			if ( self::STATUS_DEAD !== $row['status'] ) {
				continue;
			}
			if ( null !== $language && $row['language'] !== $language ) {
				continue;
			}
			$row = array_merge( $row, [
				'stage'       => self::STAGE_EXTRACT,
				'status'      => self::STATUS_PENDING,
				'attempts'    => 0,
				'fail_reason' => '',
				'payload'     => null,
				'claimed_at'  => null,
			] );
			$revived++;
		}
		unset( $row );
		return $revived;
	}

	public function clear_failed( ?string $language = null ): int {
		$cleared = 0;
		foreach ( $this->rows as $key => $row ) {
			if ( self::STATUS_DEAD !== $row['status'] ) {
				continue;
			}
			if ( null !== $language && $row['language'] !== $language ) {
				continue;
			}
			unset( $this->rows[ $key ] );
			$cleared++;
		}
		return $cleared;
	}
}

function connected_options(): array {
	return [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] ];
}

function make_indexer( InMemoryIndexQueue $queue, ?callable $clock = null ): Indexer {
	return new Indexer( 'translatewp', TRANSLATEWP_VERSION, $queue, $clock );
}

function push_row( InMemoryIndexQueue $queue, Indexer $indexer, int $object_id, string $language ): int {
	$queue->enqueue( [ [
		'object_type' => 'page',
		'object_id'   => $object_id,
		'url'         => 'https://example.com/?p=' . $object_id,
		'page_title'  => 'Title ' . $object_id,
		'language'    => $language,
		'priority'    => 100,
	] ] );
	$claimed = $indexer->claim_extract_batch( 1 );
	$indexer->submit_extraction( $claimed[0]['id'], [ [ 'groupId' => 'group-' . $object_id, 'markerPayload' => '⟪item_0001⟫Hello ' . $object_id ] ] );

	return $claimed[0]['id'];
}

// --- Enumeration via the Pro-extension filter: objects × languages, pins first ---
wp_test_reset( connected_options() );
wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 10 => 'page', 11 => 'page', 20 => 'post' ] );
update_option( 'translatewp_index_priority_pins', [ 11 ] );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );

$enqueued = $indexer->enqueue_for_languages( [ 'es', 'de' ] );
assert_true( 6 === $enqueued, 'Three objects times two languages must enqueue six rows, got ' . $enqueued );
$claimed = $indexer->claim_extract_batch( 2 );
assert_true( 11 === $claimed[0]['object_id'] && 11 === $claimed[1]['object_id'], 'Pinned page 11 must be claimed first for both languages' );
assert_true( 0 === $indexer->enqueue_for_languages( [ 'es', 'de' ] ), 'Re-enumeration must not duplicate live rows' );

// --- Extraction submit moves the row to the push stage ---
$row_counts = $queue->counts();
assert_true( 3 === $row_counts['es']['total'], 'Three lv rows expected' );
$indexer->submit_extraction( $claimed[0]['id'], [ [ 'groupId' => 'g1', 'markerPayload' => '⟪item_0001⟫Hi' ] ] );
assert_true( 1 === $queue->counts()[ $claimed[0]['language'] ]['pending_push'], 'Submitted row must be pending push' );
assert_true( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Submission must schedule a drain' );

// --- Spine scope default: front page + published menu targets only ---
wp_test_reset( connected_options() );
update_option( 'page_on_front', 10 );
update_option( 'show_on_front', 'page' );
$GLOBALS['wp_test_nav_menus']      = [ 'main' ];
$GLOBALS['wp_test_nav_menu_items'] = [
	'main' => [
		(object) [ 'type' => 'post_type', 'object_id' => 10 ],
		(object) [ 'type' => 'post_type', 'object_id' => 11 ],
		(object) [ 'type' => 'post_type', 'object_id' => 12 ],
		(object) [ 'type' => 'post_type', 'object_id' => 20 ],
		(object) [ 'type' => 'custom', 'object_id' => 999 ],
	],
];
$GLOBALS['wp_test_post_statuses'] = [ 12 => 'draft' ];
$GLOBALS['wp_test_post_type_map'] = [ 10 => 'page', 11 => 'page', 20 => 'post' ];
$GLOBALS['wp_test_front_page_html'] = '<a href="https://example.com/?p=999">should not be fetched</a>';
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$spine_enqueued = $indexer->enqueue_for_languages( [ 'es' ] );
assert_true( 0 === (int) $GLOBALS['wp_test_front_page_fetches'], 'Menus present: fallback must NOT fetch the front page' );
assert_true( 4 === $spine_enqueued, 'Spine must enqueue front page + published menu targets (10,11,20) and the 404 page, got ' . $spine_enqueued );
$spine_ids = array_values( array_unique( array_column( $queue->rows, 'object_id' ) ) );
sort( $spine_ids );
assert_true( [ -1, 10, 11, 20 ] === $spine_ids, 'Spine ids must dedupe front page, drop drafts/custom links, and carry the 404 page as -1, got ' . wp_json_encode( $spine_ids ) );
$not_found_rows = array_values( array_filter( $queue->rows, static fn( $row ) => -1 === (int) $row['object_id'] ) );
assert_true( [] !== $not_found_rows && 'not_found' === $not_found_rows[0]['object_type'] && false !== strpos( (string) $not_found_rows[0]['url'], '/translatewp-page-not-found/' ) && 'Page not found' === $not_found_rows[0]['page_title'], 'The 404 row names its type, an address no page answers, and its title, got ' . wp_json_encode( $not_found_rows[0] ?? null ) );
assert_true( ! in_array( 30, $spine_ids, true ), 'Non-menu page 30 must stay out of the spine' );

// --- Spine: a password-protected page and a page excluded in the editor stay out; switched on, the protected one joins ---
$seed_protected_spine = static function ( bool $translate_protected ): array {
	wp_test_reset( connected_options() );
	if ( $translate_protected ) {
		\TranslateWP\Components\Options::update( 'translations.settings', [ 'translate_protected_pages' => true ] );
	}
	update_option( 'page_on_front', 10 );
	update_option( 'show_on_front', 'page' );
	$GLOBALS['wp_test_nav_menus']      = [ 'main' ];
	$GLOBALS['wp_test_nav_menu_items'] = [
		'main' => [
			(object) [ 'type' => 'post_type', 'object_id' => 10 ],
			(object) [ 'type' => 'post_type', 'object_id' => 11 ],
			(object) [ 'type' => 'post_type', 'object_id' => 13 ],
			(object) [ 'type' => 'post_type', 'object_id' => 14 ],
		],
	];
	$GLOBALS['wp_test_post_type_map']  = [ 10 => 'page', 11 => 'page', 13 => 'page', 14 => 'page' ];
	$GLOBALS['wp_test_inserted_posts'] = [
		[ 'ID' => 13, 'post_type' => 'page', 'post_password' => 'secret' ],
		[ 'ID' => 14, 'post_type' => 'page', 'meta_input' => [ '_translatewp_exclude' => '1' ] ],
	];
	$queue = new InMemoryIndexQueue();
	make_indexer( $queue )->enqueue_for_languages( [ 'es' ] );
	$ids = array_values( array_unique( array_column( $queue->rows, 'object_id' ) ) );
	sort( $ids );

	return $ids;
};
assert_true( [ -1, 10, 11 ] === $seed_protected_spine( false ), 'The spine must leave out a password-protected page and an excluded page, got ' . wp_json_encode( $seed_protected_spine( false ) ) );
assert_true( [ -1, 10, 11, 13 ] === $seed_protected_spine( true ), 'Switched on, the spine takes the protected page but never the excluded one, got ' . wp_json_encode( $seed_protected_spine( true ) ) );

// --- Pages another plugin adds through the objects filter are held to the same rule ---
wp_test_reset( connected_options() );
update_option( 'page_on_front', 10 );
update_option( 'show_on_front', 'page' );
$GLOBALS['wp_test_post_type_map']  = [ 10 => 'page', 13 => 'page' ];
$GLOBALS['wp_test_inserted_posts'] = [ [ 'ID' => 13, 'post_type' => 'page', 'post_password' => 'secret' ] ];
add_filter( 'translatewp/indexer_objects', static fn( array $objects ): array => $objects + [ 13 => 'page' ] );
$queue = new InMemoryIndexQueue();
make_indexer( $queue )->enqueue_for_languages( [ 'es' ] );
assert_true( ! in_array( 13, array_column( $queue->rows, 'object_id' ), true ), 'A protected page added through the objects filter must stay out' );
remove_filter( 'translatewp/indexer_objects' );

// --- The home page goes first: read first and translated first, behind only a pinned page ---
wp_test_reset( connected_options() );
update_option( 'page_on_front', 10 );
update_option( 'show_on_front', 'page' );
update_option( 'translatewp_index_priority_pins', [ 20 ] );
$GLOBALS['wp_test_nav_menus']      = [ 'main' ];
$GLOBALS['wp_test_nav_menu_items'] = [
	'main' => [
		(object) [ 'type' => 'post_type', 'object_id' => 11 ],
		(object) [ 'type' => 'post_type', 'object_id' => 10 ],
		(object) [ 'type' => 'post_type', 'object_id' => 20 ],
	],
];
$GLOBALS['wp_test_post_type_map'] = [ 10 => 'page', 11 => 'page', 20 => 'page' ];
$queue = new InMemoryIndexQueue();
make_indexer( $queue )->enqueue_for_languages( [ 'es' ] );
$priority_of = [];
foreach ( $queue->rows as $row ) {
	$priority_of[ (int) $row['object_id'] ] = (int) $row['priority'];
}
assert_true( Indexer::PRIORITY_HOME === ( $priority_of[10] ?? null ), 'The home page gets the home priority, got ' . wp_json_encode( $priority_of ) );
assert_true( ( $priority_of[10] ?? 999 ) < ( $priority_of[11] ?? 0 ), 'The home page goes before an ordinary page' );
assert_true( ( $priority_of[20] ?? 999 ) < ( $priority_of[10] ?? 0 ), 'A pinned page still goes before the home page' );

// --- Fallback: no menu targets -> front page internal links join the spine ---
wp_test_reset( connected_options() );
update_option( 'page_on_front', 10 );
update_option( 'show_on_front', 'page' );
$GLOBALS['wp_test_post_type_map'] = [ 10 => 'page', 11 => 'page', 20 => 'post' ];
$GLOBALS['wp_test_post_statuses'] = [ 12 => 'draft' ];
$GLOBALS['wp_test_front_page_html'] = '<nav><a href="https://example.com/?p=11">About</a>'
	. '<a href="https://example.com/?p=12">Draft</a>'
	. '<a href="https://other-host.com/?p=13">External</a>'
	. '<a href="/relative?p=20">Relative</a>'
	. '<a href="https://example.com/unknown">No object</a></nav>';
$GLOBALS['wp_test_url_to_postid'] = [
	'https://example.com/?p=11' => 11,
	'https://example.com/?p=12' => 12,
	'https://other-host.com/?p=13' => 13,
	'/relative?p=20' => 20,
];
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$fallback_enqueued = $indexer->enqueue_for_languages( [ 'es' ] );
assert_true( 1 === (int) $GLOBALS['wp_test_front_page_fetches'], 'No menus: fallback must fetch the front page exactly once' );
assert_true( 4 === $fallback_enqueued, 'Fallback must enqueue front page + published internal-link targets (10,11,20), got ' . $fallback_enqueued );
$fallback_ids = array_values( array_unique( array_column( $queue->rows, 'object_id' ) ) );
sort( $fallback_ids );
assert_true( [ -1, 10, 11, 20 ] === $fallback_ids, 'Fallback ids must drop drafts, external hosts, and unresolvable links, got ' . wp_json_encode( $fallback_ids ) );

// --- SSRF guard: a foreign-host front permalink must never be fetched ---
wp_test_reset( connected_options() );
update_option( 'page_on_front', 10 );
update_option( 'show_on_front', 'page' );
$GLOBALS['wp_test_permalinks']      = [ 10 => 'https://attacker.example.net/internal' ];
$GLOBALS['wp_test_front_page_html'] = '<a href="https://example.com/?p=11">About</a>';
$GLOBALS['wp_test_url_to_postid']   = [ 'https://example.com/?p=11' => 11 ];
$GLOBALS['wp_test_post_type_map']   = [ 10 => 'page', 11 => 'page' ];
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$ssrf_enqueued = $indexer->enqueue_for_languages( [ 'es' ] );
assert_true( 0 === (int) $GLOBALS['wp_test_front_page_fetches'], 'SSRF guard: foreign-host front permalink must NOT be fetched' );
$ssrf_ids = array_values( array_unique( array_column( $queue->rows, 'object_id' ) ) );
assert_true( [ -1, 10 ] === $ssrf_ids, 'SSRF guard: only the front page itself enqueues, no fetched links, got ' . wp_json_encode( $ssrf_ids ) );
unset( $GLOBALS['wp_test_permalinks'] );

// --- P8.3 wiring: overdue drain kicks inline on the indexing status route ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 40, 'es' );
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() - 120;
$indexing = new \TranslateWP\Functionality\Endpoints\Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );
$status   = $indexing->status( new WP_REST_Request( 'GET', '/translatewp/v1/indexing/status', [], [], '' ) );
assert_true( 0 === $queue->counts()['es']['done'], 'Status must return without waiting for an overdue translation drain' );
assert_true( [] !== array_filter( $GLOBALS['wp_test_actions_fired'] ?? [], static fn( $event ) => 'translatewp_loopback_kick' === $event['tag'] ), 'Status must preserve the asynchronous constrained-host drain kick' );

// --- Drain happy path: one job per language, batches pushed, closeInput, rows done ---
wp_test_reset( connected_options() );
$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'Demo Workshop' ];
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 1, 'es' );
push_row( $queue, $indexer, 2, 'es' );

$result = $indexer->drain_tick();
assert_true( 2 === $result['processed'], 'Both push rows must be processed, got ' . $result['processed'] );
$paths = array_column( $GLOBALS['wp_test_remote_posts'], 'path' );
assert_true( 1 === count( array_keys( $paths, '/v1/translation/jobs', true ) ), 'Exactly one job must be created for one language' );
$batch_posts = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) ) );
assert_true( 1 === count( $batch_posts ), 'One combined batch per language per tick expected, got ' . count( $batch_posts ) );
assert_true( 4 === count( $batch_posts[0]['body']['items'] ?? [] ), 'Combined batch must carry each row\'s body and page title' );
$item_by_group = array_column( $batch_posts[0]['body']['items'] ?? [], null, 'groupId' );
assert_true(
	'https://example.com/?p=1' === ( $item_by_group['group-1']['pageUrl'] ?? '' ),
	'Each item must carry its own row\'s canonical page url, got ' . wp_json_encode( $item_by_group['group-1'] ?? null )
);
assert_true(
	'https://example.com/?p=2' === ( $item_by_group['group-2']['pageUrl'] ?? '' ),
	'A combined batch must never let one row\'s page url leak onto another row\'s item, got ' . wp_json_encode( $item_by_group['group-2'] ?? null )
);
$title_items = array_values( array_filter(
	$batch_posts[0]['body']['items'] ?? [],
	static fn( array $item ): bool => 'seo:title' === ( $item['contextKey'] ?? '' )
) );
assert_true( 2 === count( $title_items ), 'Each page must warm its title in the same batch, got ' . wp_json_encode( $title_items ) );
assert_true(
	[ 'Title 1', 'Title 2' ] === array_map(
		static fn( array $item ): string => str_replace( '⟪item_0001⟫', '', (string) ( $item['markerPayload'] ?? '' ) ),
		$title_items
	),
	'Title warm-up items must carry the source page titles, got ' . wp_json_encode( $title_items )
);
assert_true(
	[ 'https://example.com/?p=1', 'https://example.com/?p=2' ] === array_column( $title_items, 'pageUrl' ),
	'Title warm-up items must stay attributed to their own pages, got ' . wp_json_encode( $title_items )
);
assert_true(
	[ 'attribute', 'attribute' ] === array_column( $title_items, 'contextKind' ),
	'Title warm-up items must use the visitor title fingerprint shape, got ' . wp_json_encode( $title_items )
);
assert_true( true === ( $batch_posts[0]['body']['closeInput'] ?? false ), 'Combined batch must close input inline' );
assert_true( 'index' === ( $batch_posts[0]['body']['origin'] ?? '' ), 'Warm-up batches must carry the index origin so visitors go first' );
$batch_context = (string) ( $batch_posts[0]['body']['context'] ?? '' );
assert_true( false !== strpos( $batch_context, 'Site: Demo Workshop' ), 'Combined batch must carry the auto context block with the site title, got ' . wp_json_encode( $batch_context ) );
assert_true( false !== strpos( $batch_context, 'Pages: Title 1; Title 2' ), 'Combined batch context must list both pages\' titles, got ' . wp_json_encode( $batch_context ) );
$job_posts = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => '/v1/translation/jobs' === $post['path'] ) );
assert_true( false !== strpos( (string) ( $job_posts[0]['body']['context'] ?? '' ), 'Site: Demo Workshop' ), 'The created job must also carry the site context block' );
assert_true( 2 === $queue->counts()['es']['done'], 'Both rows must be done after the drain' );

// --- Pushed rows are announced with their page url for the translation store ---
$pushed_events = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $event ): bool => 'translatewp_index_rows_pushed' === $event['tag'] ) );
assert_true( 1 === count( $pushed_events ), 'One push must announce its rows once, got ' . count( $pushed_events ) );
assert_true( 'https://example.com/?p=1' === ( $pushed_events[0]['args'][2][0]['url'] ?? '' ), 'Announced rows must carry the page url, got ' . wp_json_encode( $pushed_events[0]['args'][2][0] ?? null ) );

// --- Completion is reported to the API exactly once ---
$index_complete_posts = static fn(): array => array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => '/v1/site/index-complete' === $post['path'] ) );
assert_true( 1 === count( $index_complete_posts() ), 'Completed language must be reported once' );
assert_true( 'es' === $index_complete_posts()[0]['body']['language'], 'Completion must carry the language' );
$purge_events = static fn(): array => array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $event ): bool => 'translatewp_page_cache_purged' === $event['tag'] ) );
assert_true( 1 === count( $purge_events() ) && 'first_pass_complete' === ( $purge_events()[0]['args'][0] ?? '' ), 'A language completing its first pass purges the page caches once, got ' . count( $purge_events() ) );
$indexer->drain_tick();
assert_true( 1 === count( $index_complete_posts() ), 'A second drain must not re-report completion' );
assert_true( 1 === count( $purge_events() ), 'A drain that completes nothing new purges nothing' );
$status = $indexer->status();
assert_true( true === $status['languages']['es']['complete'] && true === $status['languages']['es']['reported'], 'Status must show lv complete and reported' );

// --- Item pageUrl follows each row's canonical url; a row with no known url tags nothing ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue_push( [
	'object_type' => 'miss',
	'object_id'   => 950,
	'url'         => 'https://example.com/contact/',
	'language'    => 'es',
	'priority'    => 10,
	'payload'     => wp_json_encode( [ [ 'groupId' => 'g-known', 'markerPayload' => '⟪item_0001⟫Known' ] ] ),
] );
$queue->enqueue_push( [
	'object_type' => 'miss',
	'object_id'   => 951,
	'url'         => '',
	'language'    => 'es',
	'priority'    => 10,
	'payload'     => wp_json_encode( [ [ 'groupId' => 'g-unknown', 'markerPayload' => '⟪item_0001⟫Unknown' ] ] ),
] );
$indexer->drain_tick();
$miss_batch = array_values(
	array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) )
)[0] ?? null;
assert_true( null !== $miss_batch, 'Miss rows must push a combined batch' );
$miss_items = array_column( $miss_batch['body']['items'] ?? [], null, 'groupId' );
assert_true(
	'https://example.com/contact' === ( $miss_items['g-known']['pageUrl'] ?? '' ),
	'A miss row with a known url must tag its item with the canonical pageUrl, got ' . wp_json_encode( $miss_items['g-known'] ?? null )
);
assert_true(
	! array_key_exists( 'pageUrl', $miss_items['g-unknown'] ?? [] ),
	'A miss row with no known url must not set pageUrl, got ' . wp_json_encode( $miss_items['g-unknown'] ?? null )
);

// --- warm-up job/batch filters run with the right arguments, ai_settings before the request body ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 50, 'es' );

$job_body_filter_calls = [];
wp_test_set_filter( 'translatewp_translation_job_request_body', static function ( array $body ) use ( &$job_body_filter_calls ) {
	$job_body_filter_calls[] = $body;
	return $body;
} );
$batch_body_filter_calls = [];
wp_test_set_filter( 'translatewp_translation_batch_request_body', static function ( array $body, string $received_job_id, string $target_lang = '' ) use ( &$batch_body_filter_calls ) {
	$batch_body_filter_calls[] = [
		'body'       => $body,
		'jobId'      => $received_job_id,
		'targetLang' => $target_lang,
	];
	return $body;
} );
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_style'] = 'pirate';
	return $ai_settings;
} );

$result = $indexer->drain_tick();
assert_true( 1 === $result['processed'], 'Warm-up drain with filters registered must still process the row' );
assert_true( 1 === count( $job_body_filter_calls ), 'translatewp_translation_job_request_body must run once for the warm-up job POST' );
assert_true(
	isset( $job_body_filter_calls[0]['aiSettings']['custom_style'] ),
	'translatewp_translation_ai_settings must run before translatewp_translation_job_request_body sees aiSettings for warm-up jobs'
);
assert_true( 1 === count( $batch_body_filter_calls ), 'translatewp_translation_batch_request_body must run once for the warm-up batches POST' );
assert_true( 'es' === ( $batch_body_filter_calls[0]['targetLang'] ?? '' ), 'translatewp_translation_batch_request_body must receive the target language of the job' );
assert_true(
	isset( $batch_body_filter_calls[0]['body']['aiSettings']['custom_style'] ),
	'translatewp_translation_ai_settings must run before translatewp_translation_batch_request_body sees aiSettings for warm-up batches'
);
$warm_batch_posts = array_values(
	array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) )
);
assert_true(
	'pirate' === ( $warm_batch_posts[0]['body']['aiSettings']['custom_style'] ?? null ),
	'The filtered aiSettings must reach the warm-up batches POST body'
);

// --- pro_required retry applies to the warm-up job/batch pipeline too, never looping ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 51, 'es' );
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_style'] = 'pirate';
	return $ai_settings;
} );
$GLOBALS['wp_test_translation_job_responses'] = [
	[
		'response' => [ 'code' => 403 ],
		'body'     => wp_json_encode( [ 'error' => 'pro_required' ] ),
	],
];

$result    = $indexer->drain_tick();
$job_paths = array_column( $GLOBALS['wp_test_remote_posts'], 'path' );
assert_true( 1 === $result['processed'], 'A single pro_required rejection on the warm-up job POST must be retried once and then succeed' );
assert_true(
	2 === count( array_keys( $job_paths, '/v1/translation/jobs', true ) ),
	'pro_required must cause exactly one retry for the warm-up job POST, never looping'
);

// --- Push failure retries, then dead-letters at the cap with the reason ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 3, 'es' );

for ( $attempt = 1; $attempt <= IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$GLOBALS['wp_test_batch_post_failures'] = 1;
	$result                                 = $indexer->drain_tick();
}
assert_true( 1 === $queue->counts()['es']['dead'], 'Row must dead-letter after the attempt cap' );
$dead = array_values( array_filter( $queue->rows, static fn( array $row ): bool => 'dead' === $row['status'] ) );
assert_true( 'remote_error' === $dead[0]['fail_reason'], 'Dead letter must keep the failure reason, got ' . $dead[0]['fail_reason'] );
assert_true( IndexQueue::MAX_ATTEMPTS === $dead[0]['attempts'], 'Dead letter must record the attempts' );
assert_true( 0 === count( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => '/v1/site/index-complete' === $post['path'] ) ), 'Fully dead queue still reports completion only via drain — dead-only counts as complete' );

// --- Drain failure releases the miss-proxy dedupe claims so a later miss re-enqueues ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$row_hash = str_repeat( 'a', 64 );
$queue->enqueue( [ [
	'object_type' => 'miss',
	'object_id'   => 30,
	'url'         => 'https://example.com/?p=30',
	'language'    => 'es',
	'priority'    => 100,
] ] );
$claimed = $indexer->claim_extract_batch( 1 );
$indexer->submit_extraction( $claimed[0]['id'], [ [
	'groupId'       => 'group-30',
	'markerPayload' => '⟪item_0001⟫Hello 30',
	'contextKey'    => 'p',
	'contextKind'   => 'text-flow',
	'cache'         => [ 'siteId' => '00000000-0000-4000-8000-000000000001', 'hash' => $row_hash ],
] ] );
$claim_key = \TranslateWP\Components\TranslationProxyStorage::segment_claim_key(
	'00000000-0000-4000-8000-000000000001',
	'es',
	$row_hash
);
$GLOBALS['wp_test_transients'][ $claim_key ] = 1;
$GLOBALS['wp_test_transient_expirations'][ $claim_key ] = time() + 130;
assert_true( false !== get_transient( $claim_key ), 'Claim must be held before the failing drain' );

$GLOBALS['wp_test_batch_post_failures'] = 1;
$result = $indexer->drain_tick();
assert_true( 1 === $result['failed'], 'Failing drain must fail the row, got ' . wp_json_encode( $result ) );
assert_true( false === get_transient( $claim_key ), 'Failing drain must release the dedupe claim' );

$GLOBALS['wp_test_batch_post_failures'] = 0;
$second = $indexer->drain_tick();
assert_true( 1 === $second['processed'], 'A later drain must re-push the released segment, got ' . wp_json_encode( $second ) );

// --- Budget exhaustion releases unprocessed rows without charging attempts ---
wp_test_reset( connected_options() );
$queue = new InMemoryIndexQueue();
$tick  = 0;
$clock = static function () use ( &$tick ): float {
	// First call stamps the start; every later call is past the budget.
	$tick += Indexer::TICK_BUDGET_SECONDS;
	return (float) $tick;
};
$indexer = make_indexer( $queue, $clock );
push_row( $queue, $indexer, 4, 'es' );
push_row( $queue, $indexer, 5, 'es' );

$result = $indexer->drain_tick();
assert_true( 0 === $result['processed'] && 2 === $result['released'], 'Budget exhaustion must release all claimed rows, got ' . wp_json_encode( $result ) );
$pending = $queue->counts()['es']['pending_push'];
assert_true( 2 === $pending, 'Released rows must be pending again' );
$attempts = array_column( $queue->rows, 'attempts' );
assert_true( [ 0, 0 ] === array_values( $attempts ), 'Releases must not charge attempts' );
assert_true( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Remaining work must re-schedule the drain' );

// --- Disconnected site: drain is a no-op ---
wp_test_reset( [] );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 6, 'url' => 'https://example.com/?p=6', 'language' => 'es', 'priority' => 100 ] ] );
$result = $indexer->drain_tick();
assert_true( 0 === $result['processed'] && 0 === $result['failed'], 'Disconnected drain must process nothing' );
assert_true( [] === $GLOBALS['wp_test_remote_posts'], 'Disconnected drain must not call the API' );

// --- Dead rows revive on re-enumeration ---
wp_test_reset( connected_options() );
wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 7 => 'page' ] );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 7, 'es' );
for ( $attempt = 1; $attempt <= IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$GLOBALS['wp_test_batch_post_failures'] = 1;
	$indexer->drain_tick();
}
assert_true( 1 === $queue->counts()['es']['dead'], 'Row must be dead before re-enumeration' );
assert_true( 1 === $indexer->enqueue_for_languages( [ 'es' ] ), 'Re-enumeration must revive the dead row' );
assert_true( 1 === $queue->counts()['es']['pending_extract'], 'Revived row must restart at the extract stage' );

// --- Empty extraction completes the row as a no-op (not a failure) ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 8, 'url' => 'https://example.com/?p=8', 'language' => 'es', 'priority' => 100 ] ] );
$claimed = $indexer->claim_extract_batch( 1 );
assert_true( $indexer->submit_extraction( $claimed[0]['id'], [] ), 'Empty extraction submit must succeed' );
$empty_counts = $queue->counts()['es'];
assert_true( 1 === $empty_counts['done'] && 0 === $empty_counts['dead'], 'Empty extraction must mark the row done, not dead' );

// --- Manual pins re-prioritize pending rows and persist ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue( [
	[ 'object_type' => 'page', 'object_id' => 20, 'url' => 'https://example.com/?p=20', 'language' => 'es', 'priority' => 100 ],
	[ 'object_type' => 'page', 'object_id' => 21, 'url' => 'https://example.com/?p=21', 'language' => 'es', 'priority' => 100 ],
] );
$pins = $indexer->set_priority_pins( [ 21, 21, 0 ] );
assert_true( [ 21 ] === $pins, 'Pins must dedupe and drop invalid ids, got ' . wp_json_encode( $pins ) );
assert_true( [ 21 ] === $indexer->priority_pins(), 'Pins must persist in the option' );
$claimed = $indexer->claim_extract_batch( 1 );
assert_true( 21 === $claimed[0]['object_id'], 'Pinned object must be claimed first after reprioritize' );

// --- D61 inline drain: pushes ONLY the requested rows, foreign rows untouched ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$own_id     = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 901, 'language' => 'es', 'priority' => 10, 'payload' => wp_json_encode( [ [ 'groupId' => 'g-own', 'markerPayload' => '⟪item_0001⟫Own' ] ] ) ] );
$foreign_id = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 902, 'language' => 'es', 'priority' => 10, 'payload' => wp_json_encode( [ [ 'groupId' => 'g-other', 'markerPayload' => '⟪item_0001⟫Other' ] ] ) ] );
$inline = $indexer->drain_rows( [ $own_id ] );
assert_true( 1 === $inline['processed'], 'Inline drain must process exactly the requested row, got ' . wp_json_encode( $inline ) );
$statuses = array_column( $queue->rows, 'status', 'object_id' );
assert_true( 'done' === $statuses[901] && 'pending' === $statuses[902], 'Foreign pending row must stay untouched by the inline drain' );
$batch_bodies = array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) );
foreach ( $batch_bodies as $post ) {
	foreach ( $post['body']['items'] as $pushed_item ) {
		assert_true( 'g-other' !== $pushed_item['groupId'], 'Foreign items must never ride an inline drain' );
	}
}

// --- D61 inline drain: budget exhaustion releases without attempt charge ---
wp_test_reset( connected_options() );
$queue = new InMemoryIndexQueue();
$tick  = 0;
$slow_clock = static function () use ( &$tick ): float {
	$tick += Indexer::INLINE_BUDGET_SECONDS;
	return (float) $tick;
};
$indexer = make_indexer( $queue, $slow_clock );
$row_a = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 911, 'language' => 'es', 'priority' => 10, 'payload' => wp_json_encode( [ [ 'groupId' => 'g-a', 'markerPayload' => '⟪item_0001⟫A' ] ] ) ] );
$inline = $indexer->drain_rows( [ $row_a ] );
assert_true( 0 === $inline['processed'] && 1 === $inline['released'], 'Budget exhaustion must release inline rows, got ' . wp_json_encode( $inline ) );
$released_row = array_values( $queue->rows )[0];
assert_true( 'pending' === $released_row['status'] && 0 === $released_row['attempts'], 'Released inline row must be pending with no attempt charge' );

// --- D61 semaphore: cap, release, and TTL auto-release ---
wp_test_reset( connected_options() );
assert_true( true === Indexer::acquire_inline_slot(), 'First slot acquires' );
assert_true( true === Indexer::acquire_inline_slot(), 'Second slot acquires' );
assert_true( false === Indexer::acquire_inline_slot(), 'Cap must refuse a third concurrent inline drain' );
Indexer::release_inline_slot();
assert_true( true === Indexer::acquire_inline_slot(), 'Released slot must be reusable' );
$GLOBALS['wp_test_transient_expirations'][ Indexer::INLINE_SLOT_TRANSIENT ] = time() - 1;
assert_true( true === Indexer::acquire_inline_slot(), 'Expired slot transient must auto-release (crash-leak protection)' );

// --- D61 scheduler mode: no fastcgi_finish_request (CLI) -> cron fallback, no slot taken ---
wp_test_reset( connected_options() );
assert_true( 'cron' === Indexer::schedule_inline_drain( [ 1 ] ), 'Non-FPM must fall back to cron' );
assert_true( false === get_transient( Indexer::INLINE_SLOT_TRANSIENT ), 'Cron fallback must not consume a slot' );

// --- The source (default) locale is never enqueued: a source->source warm-up
//     job never resolves, so that language would otherwise sit forever
//     "Translating" and every one of its pages dead-letters. ---
wp_test_reset( connected_options() );
update_option(
	'translatewp_languages',
	[
		'list' => [
			'lv'    => [ 'locale' => 'lv', 'slug' => 'lv', 'active' => true, 'default' => true ],
			'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => false ],
			'de'    => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
		],
	]
);
wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 10 => 'page' ] );
$src_queue    = new InMemoryIndexQueue();
$src_indexer  = make_indexer( $src_queue );
$src_enqueued = $src_indexer->enqueue_for_languages();
$src_langs    = array_values( array_unique( array_column( $src_queue->rows, 'language' ) ) );
sort( $src_langs );
assert_true( ! in_array( 'lv', $src_langs, true ), 'The source/default locale must never be enqueued, got ' . wp_json_encode( $src_langs ) );
assert_true( [ 'de', 'en-us' ] === $src_langs, 'Only the non-default active locales are enqueued, got ' . wp_json_encode( $src_langs ) );
assert_true( 2 === $src_enqueued, 'One object times two non-default locales enqueues two rows, got ' . $src_enqueued );

// --- A spine object whose permalink is a Customizer preview or admin URL is
//     never enqueued as a page ---
wp_test_reset( connected_options() );
wp_test_set_filter( 'translatewp/indexer_objects', static fn( $objects ) => [ 60 => 'page', 61 => 'page', 62 => 'page' ] );
$GLOBALS['wp_test_permalinks'] = [
	60 => 'https://example.com/about/?customize_changeset_uuid=abc-123',
	61 => 'https://example.com/wp-admin/customize.php',
	62 => 'https://example.com/contact/',
];
$queue          = new InMemoryIndexQueue();
$indexer        = make_indexer( $queue );
$guard_enqueued = $indexer->enqueue_for_languages( [ 'es' ] );
assert_true( 1 === $guard_enqueued, 'Only the ordinary permalink must enqueue, got ' . $guard_enqueued );
$guard_ids = array_values( array_unique( array_column( $queue->rows, 'object_id' ) ) );
assert_true( [ 62 ] === $guard_ids, 'The Customizer preview and admin permalinks must never enqueue, got ' . wp_json_encode( $guard_ids ) );
unset( $GLOBALS['wp_test_permalinks'] );

// --- Failed items: dead rows list url + reason, scope by language, retry and clear ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 80, 'es' );
push_row( $queue, $indexer, 81, 'de' );
for ( $attempt = 1; $attempt <= IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$GLOBALS['wp_test_batch_post_failures'] = 2;
	$indexer->drain_tick();
}

$failed = $indexer->failed_items();
assert_true( 2 === count( $failed ), 'Both dead rows must be listed as failed, got ' . wp_json_encode( $failed ) );
$es_failed = $indexer->failed_items( 'es' );
assert_true( 1 === count( $es_failed ), 'The language filter must scope failed items to one language' );
assert_true( 'https://example.com/?p=80' === ( $es_failed[0]['url'] ?? '' ), 'A failed item must carry the page url, got ' . wp_json_encode( $es_failed[0] ?? null ) );
assert_true( 'remote_error' === ( $es_failed[0]['reason'] ?? '' ), 'A failed item must carry the stored failure reason, got ' . wp_json_encode( $es_failed[0] ?? null ) );

$status_failed = $indexer->status()['failed'];
assert_true( 2 === count( $status_failed ), 'Status must expose the failed items for the admin UI' );
assert_true(
	isset( $status_failed[0]['objectType'], $status_failed[0]['objectId'], $status_failed[0]['reason'], $status_failed[0]['url'] ),
	'Status failed items must be camelCase-shaped, got ' . wp_json_encode( $status_failed[0] ?? null )
);

unset( $GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] );
$retried = $indexer->retry_failed( 'es' );
assert_true( 1 === $retried, 'Retry must re-queue exactly the scoped dead rows, got ' . $retried );
$after_retry = $queue->counts();
assert_true( 1 === $after_retry['es']['pending_extract'] && 0 === $after_retry['es']['dead'], 'Retried rows must return to the extract stage with attempts reset, got ' . wp_json_encode( $after_retry['es'] ) );
assert_true( 1 === $after_retry['de']['dead'], 'Retry must not touch other languages' );
assert_true( false !== wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Retry must schedule a drain for the revived work' );

$cleared = $indexer->clear_failed( 'de' );
assert_true( 1 === $cleared, 'Clear must delete exactly the scoped dead rows, got ' . $cleared );
assert_true( ! isset( $queue->counts()['de'] ), 'A cleared language must have no rows left, got ' . wp_json_encode( array_keys( $queue->counts() ) ) );

// --- Endpoint: retry-failed re-queues the scoped dead rows and returns fresh status ---
wp_test_reset( connected_options() );
$queue    = new InMemoryIndexQueue();
$indexer  = make_indexer( $queue );
$indexing = new \TranslateWP\Functionality\Endpoints\Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );
push_row( $queue, $indexer, 90, 'es' );
for ( $attempt = 1; $attempt <= IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$GLOBALS['wp_test_batch_post_failures'] = 1;
	$indexer->drain_tick();
}
$retry_response = $indexing->retry_failed( new WP_REST_Request( 'POST', '/translatewp/v1/indexing/retry-failed', [ 'language' => 'es' ], [], '' ) );
assert_true( 1 === ( $retry_response['retried'] ?? 0 ), 'The retry-failed endpoint must report how many rows were re-queued, got ' . wp_json_encode( $retry_response ) );
assert_true( isset( $retry_response['status']['failed'] ) && 0 === count( $retry_response['status']['failed'] ), 'After a retry the endpoint status must show no failed rows' );

// --- Endpoint: clear-failed removes the dead rows and returns fresh status ---
wp_test_reset( connected_options() );
$queue    = new InMemoryIndexQueue();
$indexer  = make_indexer( $queue );
$indexing = new \TranslateWP\Functionality\Endpoints\Indexing( 'translatewp', TRANSLATEWP_VERSION, $indexer );
push_row( $queue, $indexer, 91, 'es' );
for ( $attempt = 1; $attempt <= IndexQueue::MAX_ATTEMPTS; $attempt++ ) {
	$GLOBALS['wp_test_batch_post_failures'] = 1;
	$indexer->drain_tick();
}
$clear_response = $indexing->clear_failed( new WP_REST_Request( 'POST', '/translatewp/v1/indexing/clear-failed', [], [], '' ) );
assert_true( 1 === ( $clear_response['cleared'] ?? 0 ), 'The clear-failed endpoint must report how many rows were removed, got ' . wp_json_encode( $clear_response ) );
assert_true( isset( $clear_response['status']['failed'] ) && 0 === count( $clear_response['status']['failed'] ), 'After a clear the endpoint status must show no failed rows' );

// --- Scheduled-tasks-late: no waiting work is never flagged late ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
unset( $GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] );
assert_true( false === $indexer->status()['scheduledTasksLate'], 'With no waiting work the tab is never flagged late' );

// --- Scheduled-tasks-late: rows waiting for extraction are the admin tab's work, never late ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 1, 'url' => 'https://example.com/?p=1', 'language' => 'es', 'priority' => 100 ] ] );
unset( $GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] );
assert_true( 1 === $queue->counts()['es']['pending_extract'], 'Precondition: one row waits for extraction' );
assert_true( false === $indexer->status()['scheduledTasksLate'], 'Rows waiting for extraction with no drain scheduled are not late' );

// --- Scheduled-tasks-late case a: push work waits but the drain is not scheduled at all ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 1, 'url' => 'https://example.com/?p=1', 'language' => 'es', 'priority' => 100 ] ] );
$claimed = $indexer->claim_extract_batch( 1 );
$indexer->submit_extraction( $claimed[0]['id'], [ [ 'groupId' => 'group-1', 'markerPayload' => '⟪item_0001⟫Hola' ] ] );
unset( $GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] );
assert_true( 1 === $queue->counts()['es']['pending_push'], 'Precondition: one row waits for the push' );
assert_true( false === wp_next_scheduled( Indexer::DRAIN_HOOK ), 'Precondition: no drain is scheduled' );
assert_true( true === $indexer->status()['scheduledTasksLate'], 'A not-scheduled drain while push work waits must read as late' );

// --- Scheduled-tasks-late: a drain that ran recently is never late, even with nothing scheduled (loopback kick on a no-cron host) ---
update_option( 'translatewp_index_last_drain', time() - 30 );
assert_true( false === $indexer->status()['scheduledTasksLate'], 'A drain that ran 30 s ago keeps the tab not late with no drain scheduled' );
update_option( 'translatewp_index_last_drain', time() - ( Indexer::LAST_DRAIN_STALE_SECONDS + 5 ) );
assert_true( true === $indexer->status()['scheduledTasksLate'], 'A drain older than the stale window with nothing scheduled reads as late' );
delete_option( 'translatewp_index_last_drain' );

// --- Scheduled-tasks-late case b: overdue by more than two intervals ---
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() - ( Indexer::SCHEDULED_LATE_INTERVALS * Indexer::RESCHEDULE_DELAY_SECONDS + 40 );
assert_true( true === $indexer->status()['scheduledTasksLate'], 'A drain overdue by more than two intervals must read as late' );
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() - 30;
assert_true( false === $indexer->status()['scheduledTasksLate'], 'A drain overdue by less than two intervals with a fresh last run is not late' );

// --- Scheduled-tasks-late case c: last drain ran more than the stale window ago ---
$GLOBALS['wp_test_scheduled'][ Indexer::DRAIN_HOOK ] = time() + 30;
update_option( 'translatewp_index_last_drain', time() - ( Indexer::LAST_DRAIN_STALE_SECONDS + 60 ) );
assert_true( true === $indexer->status()['scheduledTasksLate'], 'A stale last drain while work waits must read as late' );
$fresh_run = time() - 30;
update_option( 'translatewp_index_last_drain', $fresh_run );
assert_true( false === $indexer->status()['scheduledTasksLate'], 'A recent last drain is not late' );
assert_true( $fresh_run === $indexer->status()['lastRun'], 'Status must surface the last drain timestamp' );

// --- A drain tick records its run time for the status endpoint ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
assert_true( null === $indexer->status()['lastRun'], 'A queue that never drained reports no last run' );
push_row( $queue, $indexer, 70, 'es' );
$indexer->drain_tick();
assert_true( null !== $indexer->status()['lastRun'], 'A drain tick must record its run time' );

// --- Index pushes leave under the server's fingerprint, claim their segments, and skip a segment a visitor already holds in flight ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$site_id = '00000000-0000-4000-8000-000000000001';
$server_hash = static fn( string $payload ): string => \TranslateWP\Components\TranslationFingerprint::compute_hash(
	$payload,
	\TranslateWP\Components\TranslationProxySettings::default_locale(),
	'es',
	\TranslateWP\Components\TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW,
	\TranslateWP\Components\TranslationProxySettings::ai_settings( 'es' )
);
$two_items = static fn( string $suffix ): string => wp_json_encode( [
	[ 'groupId' => 'g-one', 'markerPayload' => '⟪item_0001⟫One ' . $suffix, 'cache' => [ 'siteId' => $site_id, 'hash' => 'browser-hash-one-' . $suffix ] ],
	[ 'groupId' => 'g-two', 'markerPayload' => '⟪item_0001⟫Two ' . $suffix, 'cache' => [ 'siteId' => $site_id, 'hash' => 'browser-hash-two-' . $suffix ] ],
] );
$one_a = $server_hash( '⟪item_0001⟫One a' );
$two_a = $server_hash( '⟪item_0001⟫Two a' );
$queue->enqueue_push( [ 'object_type' => 'page', 'object_id' => 700, 'url' => 'https://example.com/?p=700', 'language' => 'es', 'priority' => 10, 'payload' => $two_items( 'a' ) ] );
assert_true( true === \TranslateWP\Components\TranslationProxyDedupe::claim_segment( $site_id, 'es', $two_a ), 'Precondition: a visitor holds the second segment in flight under the server fingerprint' );
$GLOBALS['wp_test_remote_posts'] = [];
$indexer->drain_tick();
$claim_batches = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) ) );
assert_true( 1 === count( $claim_batches ), 'The row is pushed once, got ' . count( $claim_batches ) );
$pushed_hashes = array_map( static fn( array $item ): string => (string) ( $item['cache']['hash'] ?? '' ), $claim_batches[0]['body']['items'] ?? [] );
assert_true( [ $one_a ] === $pushed_hashes, 'Only the segment nobody holds is pushed, under the fingerprint the server computes, never the browser one, got ' . wp_json_encode( $pushed_hashes ) );
$held = \TranslateWP\Components\TranslationProxyDedupe::claim_segment( $site_id, 'es', $one_a );
assert_true( is_wp_error( $held ) && 'duplicate_segment' === $held->get_error_code(), 'The pushed segment is held in flight by the index' );
\TranslateWP\Components\TranslationProxyDedupe::release_segments( $site_id, 'es', [ $one_a, $two_a ] );

// --- A row whose every segment is already in flight completes without a push ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue_push( [ 'object_type' => 'page', 'object_id' => 701, 'url' => 'https://example.com/?p=701', 'language' => 'es', 'priority' => 10, 'payload' => $two_items( 'b' ) ] );
$one_b = $server_hash( '⟪item_0001⟫One b' );
$two_b = $server_hash( '⟪item_0001⟫Two b' );
\TranslateWP\Components\TranslationProxyDedupe::claim_segment( $site_id, 'es', $one_b );
\TranslateWP\Components\TranslationProxyDedupe::claim_segment( $site_id, 'es', $two_b );
$GLOBALS['wp_test_remote_posts'] = [];
$indexer->drain_tick();
assert_true( 0 === count( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) ) ), 'Nothing is pushed when every segment is already in flight' );
assert_true( 0 === $queue->counts()['es']['pending_push'] && 1 === ( $queue->counts()['es']['done'] ?? 0 ), 'The row completes without a push, got ' . wp_json_encode( $queue->counts()['es'] ) );
\TranslateWP\Components\TranslationProxyDedupe::release_segments( $site_id, 'es', [ $one_b, $two_b ] );

// --- A failed push releases the claims it took ---
wp_test_reset( connected_options() );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
$queue->enqueue_push( [ 'object_type' => 'page', 'object_id' => 702, 'url' => 'https://example.com/?p=702', 'language' => 'es', 'priority' => 10, 'payload' => $two_items( 'c' ) ] );
$GLOBALS['wp_test_batch_post_failures'] = 1;
$indexer->drain_tick();
$GLOBALS['wp_test_batch_post_failures'] = 0;
$one_c = $server_hash( '⟪item_0001⟫One c' );
assert_true( true === \TranslateWP\Components\TranslationProxyDedupe::claim_segment( $site_id, 'es', $one_c ), 'A failed push releases its segment claims' );
\TranslateWP\Components\TranslationProxyDedupe::release_segments( $site_id, 'es', [ $one_c, $server_hash( '⟪item_0001⟫Two c' ) ] );

// --- A refused job names the reason: a used-up allowance, an hourly cap, or a plain failure ---
foreach ( [
	[ 429, 'limit_exceeded', 'quota_exceeded' ],
	[ 429, 'rate_limited', 'rate_limited' ],
	[ 503, 'portal_unavailable', 'job_create_failed' ],
] as [ $status, $code, $expected_reason ] ) {
	wp_test_reset( connected_options() );
	$GLOBALS['wp_test_remote_posts'] = [];
	// A refused row is retried until it is dead; the refusal is scripted for
	// every attempt and the dead row carries the last reason.
	$refusal = [
		'response' => [ 'code' => $status ],
		'body'     => wp_json_encode( [ 'error' => 'refused', 'code' => $code ] ),
	];
	$GLOBALS['wp_test_translation_job_responses'] = array_fill( 0, 12, $refusal );
	$queue   = new InMemoryIndexQueue();
	$indexer = make_indexer( $queue );
	push_row( $queue, $indexer, 1, 'es' );
	$failed = [];
	for ( $attempt = 0; $attempt < 10 && ! $failed; $attempt++ ) {
		$indexer->drain_tick();
		$failed = $indexer->failed_items( 'es' );
	}
	assert_true( 1 === count( $failed ) && $expected_reason === ( $failed[0]['reason'] ?? '' ), 'A ' . $status . ' ' . $code . ' refusal must fail the row as ' . $expected_reason . ', got ' . wp_json_encode( $failed ) );
}
// --- The quota handoff on the index path: a quota refusal at job opening is retried once with the own providers, the row completes, the job is pinned ---
$own = [ [ 'provider' => 'openai', 'modelId' => 'gpt-5.2', 'apiKey' => 'sk-own' ] ];
$with_handoff = static function () use ( $own ): void {
	wp_test_reset( connected_options() );
	\TranslateWP\Components\QuotaHandoff::set_enabled( true );
	wp_test_set_filter( 'translatewp_quota_handoff_providers', static fn ( $providers, $language ) => 'es' === $language ? $own : $providers );
};
$quota_refusal = [ 'response' => [ 'code' => 429 ], 'body' => wp_json_encode( [ 'error' => 'Translation quota reached', 'code' => 'limit_exceeded', 'quota' => [ 'percent_available' => 0, 'window_ends_at' => '2099-01-01T00:00:00Z' ] ] ) ];

$with_handoff();
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_job_responses'] = [ $quota_refusal, [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'jobId' => 'job-handoff-1', 'sourceLang' => 'en', 'targetLang' => 'es' ] ) ] ];
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 801, 'es' );
$result = $indexer->drain_tick();
$job_posts = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $post ): bool => '/v1/translation/jobs' === $post['path'] ) );
$batch_posts = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] ) ) );
assert_true( 2 === count( $job_posts ) && ! isset( $job_posts[0]['body']['byok'] ) && $own === ( $job_posts[1]['body']['byok'] ?? null ), 'Index path: the quota refusal at job opening is retried once with the own providers, got ' . wp_json_encode( array_map( static fn ( $p ) => isset( $p['body']['byok'] ), $job_posts ) ) );
assert_true( 1 === count( $batch_posts ) && $own === ( $batch_posts[0]['body']['byok'] ?? null ), 'Index path: the batch of the pinned job carries the own providers' );
assert_true( [] === $indexer->failed_items( 'es' ), 'Index path: the row is not failed by the handed-off refusal' );
// An index job is one batch that closes the input, so its pin is released once that batch is accepted.
assert_true( ! \TranslateWP\Components\QuotaHandoff::job_uses_own_providers( 'job-handoff-1' ) && \TranslateWP\Components\QuotaHandoff::is_active(), 'Index path: the closed job is released while the handoff stays active' );

// --- The quota handoff on the index path, no own provider for the language: the row fails as quota_exceeded ---
$with_handoff();
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_job_responses'] = array_fill( 0, 12, $quota_refusal );
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 802, 'fr' );
$failed = [];
for ( $attempt = 0; $attempt < 10 && ! $failed; $attempt++ ) {
	$indexer->drain_tick();
	$failed = $indexer->failed_items( 'fr' );
}
assert_true( 1 === count( $failed ) && 'quota_exceeded' === ( $failed[0]['reason'] ?? '' ), 'Index path: a language without own providers fails as quota_exceeded, got ' . wp_json_encode( $failed ) );
assert_true( ! array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $post ): bool => isset( $post['body']['byok'] ) ), 'Index path: nothing was sent with a key for that language' );

// --- A job still open on the service (the visitor path keeps jobs open across batches) whose key stopped working: refused with the reason, nothing goes to the TranslateWP model ---
$with_handoff();
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_job_responses'] = [ $quota_refusal, [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'jobId' => 'job-handoff-2', 'sourceLang' => 'en', 'targetLang' => 'es' ] ) ] ];
\TranslateWP\Components\TranslationProRetry::post( '/v1/translation/jobs', [ 'sourceLang' => 'en', 'targetLang' => 'es' ], 5 );
assert_true( \TranslateWP\Components\QuotaHandoff::job_uses_own_providers( 'job-handoff-2' ), 'Open job: pinned after the handed-off opening' );
wp_test_set_filter( 'translatewp_quota_handoff_providers', static fn ( $providers ) => [] );
$before = count( $GLOBALS['wp_test_remote_posts'] );
$result = \TranslateWP\Components\TranslationProRetry::post( '/v1/translation/jobs/job-handoff-2/batches', [ 'batchId' => 'late', 'items' => [ [ 'groupId' => 'g' ] ] ], 5, 'es' );
assert_true( is_wp_error( $result ) && count( $GLOBALS['wp_test_remote_posts'] ) === $before, 'Open job: without a usable key nothing is sent' );
assert_true( 'own_providers_missing' === \TranslateWP\Components\TranslationProviderFailure::reason( $result, 'remote_error' ), 'Open job: the reason names the missing providers' );
assert_true( \TranslateWP\Components\QuotaHandoff::job_uses_own_providers( 'job-handoff-2' ), 'Open job: it stays pinned' );

// --- After the window ends, a new job goes back to the TranslateWP model while the pinned one keeps its providers ---
$with_handoff();
$GLOBALS['wp_test_remote_posts'] = [];
\TranslateWP\Components\QuotaHandoff::activate( '2020-01-01T00:00:00Z' );
\TranslateWP\Components\QuotaHandoff::pin_job( 'job-old' );
$GLOBALS['wp_test_translation_job_responses'] = [ [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'jobId' => 'job-after-reset', 'sourceLang' => 'en', 'targetLang' => 'es' ] ) ] ];
$queue   = new InMemoryIndexQueue();
$indexer = make_indexer( $queue );
push_row( $queue, $indexer, 804, 'es' );
$indexer->drain_tick();
$job_posts = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn ( array $post ): bool => '/v1/translation/jobs' === $post['path'] ) );
assert_true( 1 === count( $job_posts ) && ! isset( $job_posts[0]['body']['byok'] ), 'Index path: after the window a new job opens on the TranslateWP model' );
$GLOBALS['wp_test_remote_posts'] = [];
\TranslateWP\Components\TranslationProRetry::post( '/v1/translation/jobs/job-old/batches', [ 'batchId' => 'b', 'items' => [ [ 'groupId' => 'g' ] ] ], 5, 'es' );
assert_true( $own === ( $GLOBALS['wp_test_remote_posts'][0]['body']['byok'] ?? null ), 'Index path: the job pinned before the window ended keeps its providers' );
\TranslateWP\Components\QuotaHandoff::set_enabled( false );
\TranslateWP\Components\QuotaHandoff::clear();

$GLOBALS['wp_test_translation_job_responses'] = [];

if ( $failures > 0 ) {
	fwrite( STDERR, "IndexerTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "IndexerTest: all assertions passed\n";
