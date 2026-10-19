<?php
/**
 * Minimal WordPress double for the uninstall tests. It is separate from
 * wp-test-stubs.php so the uninstall path runs with only what WordPress has
 * loaded in a delete-plugin request, and so every row, query, deletion and
 * remote call can be counted.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class Uninstall_Test_wpdb {
	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $term_relationships = 'wp_term_relationships';
	public string $options            = 'wp_options';
	public string $sitemeta           = 'wp_sitemeta';

	/** @var array<int,string> post ID => post_type */
	public array $post_rows = [];
	/** @var array<int,array<string,mixed>> post ID => meta */
	public array $meta_rows = [];
	/** @var array<int,int[]> object ID => term taxonomy IDs */
	public array $term_rows = [];
	/** @var array<string,int> table => DELETE count */
	public array $deletes = [];
	public int $selects   = 0;
	/** @var string[] option and site option SELECTs, in order */
	public array $option_selects = [];
	/** @var int|null Most rows one posts DELETE removes; null means no limit. */
	public ?int $posts_delete_cap = null;

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( $args, &$index ): string {
				$value = $args[ $index++ ] ?? '';
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_col( string $query ): array {
		if ( preg_match( '/LIMIT (\d+)$/', $query, $limit ) && preg_match( '/^SELECT option_name FROM wp_options WHERE/', $query ) ) {
			return array_slice( $this->get_col( preg_replace( '/ ORDER BY.*$/s', '', $query ) ), 0, (int) $limit[1] );
		}
		if ( preg_match( '/^SELECT (option_name FROM wp_options|meta_key FROM wp_sitemeta) WHERE/', $query, $table ) ) {
			$this->option_selects[] = $query;
			$network = 0 === strpos( $table[1], 'meta_key' );
			if ( $network && ! preg_match( '/site_id = 1 AND/', $query ) ) {
				throw new RuntimeException( 'Network option query without the network id: ' . $query );
			}
			$names = array_keys( $network ? $GLOBALS['uninstall_test_site_options'] : uninstall_test_blog_store() );
			return array_values( array_filter( $names, static fn ( $name ): bool => uninstall_test_like_any( $query, (string) $name ) ) );
		}
		$this->selects++;
		if ( ! preg_match( "/SELECT ID FROM wp_posts WHERE post_type = '([^']+)'.*LIMIT (\d+)/s", $query, $m ) ) {
			throw new RuntimeException( 'Unexpected get_col query: ' . $query );
		}
		$ids = array_keys( array_filter( $this->post_rows, static fn ( string $type ): bool => $type === $m[1] ) );
		sort( $ids );
		return array_map( 'strval', array_slice( $ids, 0, (int) $m[2] ) );
	}

	public function query( string $query ) {
		if ( preg_match( "/^DELETE FROM (wp_options|wp_sitemeta) WHERE (?:site_id = 1 AND )?(?:option_name|meta_key) IN \\((.*)\\)$/s", $query, $m ) ) {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $m[2], $quoted );
			$removed = 0;
			foreach ( array_map( 'stripslashes', $quoted[1] ) as $name ) {
				if ( 'wp_sitemeta' === $m[1] ) {
					$removed += isset( $GLOBALS['uninstall_test_site_options'][ $name ] ) ? 1 : 0;
					unset( $GLOBALS['uninstall_test_site_options'][ $name ] );
					continue;
				}
				$store    = &uninstall_test_blog_store();
				$removed += array_key_exists( $name, $store ) ? 1 : 0;
				unset( $store[ $name ] );
				unset( $store );
			}
			return $removed;
		}
		if ( ! preg_match( '/DELETE FROM (wp_\w+) WHERE (ID|post_id|object_id) IN \(([\d,\s]+)\)/', $query, $m ) ) {
			throw new RuntimeException( 'Unexpected query: ' . $query );
		}
		$table                   = $m[1];
		$ids                     = array_map( 'intval', explode( ',', $m[3] ) );
		$this->deletes[ $table ] = ( $this->deletes[ $table ] ?? 0 ) + 1;
		$removed                 = 0;
		foreach ( $ids as $id ) {
			if ( 'wp_posts' === $table && null !== $this->posts_delete_cap && $removed >= $this->posts_delete_cap ) {
				continue;
			}
			if ( 'wp_posts' === $table && isset( $this->post_rows[ $id ] ) ) {
				unset( $this->post_rows[ $id ] );
				$removed++;
			} elseif ( 'wp_postmeta' === $table && isset( $this->meta_rows[ $id ] ) ) {
				$removed += count( $this->meta_rows[ $id ] );
				unset( $this->meta_rows[ $id ] );
			} elseif ( 'wp_term_relationships' === $table && isset( $this->term_rows[ $id ] ) ) {
				$removed += count( $this->term_rows[ $id ] );
				unset( $this->term_rows[ $id ] );
			}
		}
		return $removed;
	}

	public function seed( string $post_type, int $count, int $meta_per_row = 8 ): void {
		$next = $this->post_rows ? max( array_keys( $this->post_rows ) ) + 1 : 1;
		for ( $i = 0; $i < $count; $i++ ) {
			$id                     = $next + $i;
			$this->post_rows[ $id ] = $post_type;
			for ( $k = 0; $k < $meta_per_row; $k++ ) {
				$this->meta_rows[ $id ][ '_twp_meta_' . $k ] = 'v';
			}
			if ( 'lexicon' === $post_type ) {
				$this->term_rows[ $id ] = [ 7 ];
			}
		}
	}

	public function count_type( string $post_type ): int {
		return count( array_filter( $this->post_rows, static fn ( string $type ): bool => $type === $post_type ) );
	}
}

$GLOBALS['wpdb']                   = new Uninstall_Test_wpdb();
$GLOBALS['uninstall_test_options'] = [];
$GLOBALS['uninstall_test_actions'] = [];
$GLOBALS['uninstall_test_deletes'] = 0;
$GLOBALS['uninstall_test_remote']  = [];
$GLOBALS['uninstall_test_cleared'] = [];
$GLOBALS['uninstall_test_cleaned'] = [];
$GLOBALS['uninstall_test_site_options']  = [];
$GLOBALS['uninstall_test_blog_options']  = [];
$GLOBALS['uninstall_test_cron']          = [];
$GLOBALS['uninstall_test_unscheduled']   = [];
$GLOBALS['uninstall_test_get_sites']     = [];
$GLOBALS['uninstall_test_cache_deletes'] = [];
$GLOBALS['uninstall_test_filters']       = [];
$GLOBALS['uninstall_test_meta_deletes']  = [];
$GLOBALS['uninstall_test_mails']         = [];
$GLOBALS['uninstall_test_as_unscheduled'] = [];

function clean_post_cache( $post ): void {
	$GLOBALS['uninstall_test_cleaned'][] = (int) $post;
}

/**
 * The options of the current blog: blog 1 is uninstall_test_options, every other
 * blog of a network has its own store, as each has its own options table.
 */
function &uninstall_test_blog_store(): array {
	$blog_id = get_current_blog_id();
	if ( 1 === $blog_id ) {
		return $GLOBALS['uninstall_test_options'];
	}
	if ( ! isset( $GLOBALS['uninstall_test_blog_options'][ $blog_id ] ) ) {
		$GLOBALS['uninstall_test_blog_options'][ $blog_id ] = [];
	}
	return $GLOBALS['uninstall_test_blog_options'][ $blog_id ];
}

/** Whether a name matches one of the LIKE patterns of a prepared query. */
function uninstall_test_like_any( string $query, string $name ): bool {
	preg_match_all( "/LIKE '((?:[^'\\\\]|\\\\.)*)'/", $query, $patterns );
	foreach ( $patterns[1] as $pattern ) {
		$pattern = stripslashes( $pattern );
		$regex   = preg_replace_callback(
			'/\\\\(.)|(%)|(_)|(.)/s',
			static function ( array $m ): string {
				if ( isset( $m[4] ) && '' !== $m[4] ) {
					return preg_quote( $m[4], '/' );
				}
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					return '.';
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return '.*';
				}
				return preg_quote( $m[1], '/' );
			},
			$pattern
		);
		if ( preg_match( '/^' . $regex . '$/s', $name ) ) {
			return true;
		}
	}
	return false;
}

function get_option( $name, $default = false ) {
	$store = uninstall_test_blog_store();
	return array_key_exists( $name, $store ) ? $store[ $name ] : $default;
}

// As core: false when there was no such option.
function delete_option( $name ): bool {
	$store   = &uninstall_test_blog_store();
	$existed = array_key_exists( $name, $store );
	unset( $store[ $name ] );
	return $existed;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ): bool {
	unset( $deprecated, $autoload );
	$store = &uninstall_test_blog_store();
	if ( array_key_exists( $name, $store ) ) {
		return false;
	}
	$store[ $name ] = $value;
	return true;
}

function get_site_option( $name, $default = false ) {
	if ( ! is_multisite() ) {
		return get_option( $name, $default );
	}
	return array_key_exists( $name, $GLOBALS['uninstall_test_site_options'] ) ? $GLOBALS['uninstall_test_site_options'][ $name ] : $default;
}

function update_site_option( $name, $value ): bool {
	if ( ! is_multisite() ) {
		return update_option( $name, $value );
	}
	$GLOBALS['uninstall_test_site_options'][ $name ] = $value;
	return true;
}

function delete_site_option( $name ): bool {
	if ( ! is_multisite() ) {
		return delete_option( $name );
	}
	unset( $GLOBALS['uninstall_test_site_options'][ $name ] );
	return true;
}

function delete_transient( $name ): bool {
	delete_option( '_transient_timeout_' . $name );
	return delete_option( '_transient_' . $name );
}

function wp_cache_delete( $key, $group = '' ): bool {
	$GLOBALS['uninstall_test_cache_deletes'][] = $group . ':' . $key;
	return true;
}

/** Schedules one event on the current blog, as wp_schedule_single_event does. */
function uninstall_test_schedule( string $hook, array $args = [] ): void {
	$GLOBALS['uninstall_test_cron'][ get_current_blog_id() ][] = [
		'hook' => $hook,
		'args' => $args,
	];
}

/** The hooks still scheduled on one blog. */
function uninstall_test_scheduled( int $blog_id ): array {
	return array_column( $GLOBALS['uninstall_test_cron'][ $blog_id ] ?? [], 'hook' );
}

// As core: only the events whose arguments equal $args are cleared.
function wp_clear_scheduled_hook( $hook, $args = [] ): int {
	$GLOBALS['uninstall_test_cleared'][] = $hook;
	$blog_id = get_current_blog_id();
	$before  = count( $GLOBALS['uninstall_test_cron'][ $blog_id ] ?? [] );
	$GLOBALS['uninstall_test_cron'][ $blog_id ] = array_values(
		array_filter(
			$GLOBALS['uninstall_test_cron'][ $blog_id ] ?? [],
			static fn ( array $event ): bool => ! ( $event['hook'] === $hook && $event['args'] === $args )
		)
	);
	return $before - count( $GLOBALS['uninstall_test_cron'][ $blog_id ] );
}

// As core: every event of the hook, whatever its arguments.
function wp_unschedule_hook( $hook ): int {
	$GLOBALS['uninstall_test_unscheduled'][] = get_current_blog_id() . ':' . $hook;
	// Lets a test make the local cleanup take time.
	$GLOBALS['uninstall_test_now'] = ( $GLOBALS['uninstall_test_now'] ?? 0.0 ) + ( $GLOBALS['uninstall_test_cleanup_seconds'] ?? 0.0 );
	$blog_id = get_current_blog_id();
	$before  = count( $GLOBALS['uninstall_test_cron'][ $blog_id ] ?? [] );
	$GLOBALS['uninstall_test_cron'][ $blog_id ] = array_values(
		array_filter(
			$GLOBALS['uninstall_test_cron'][ $blog_id ] ?? [],
			static fn ( array $event ): bool => $event['hook'] !== $hook
		)
	);
	return $before - count( $GLOBALS['uninstall_test_cron'][ $blog_id ] );
}

function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ): bool {
	$GLOBALS['uninstall_test_meta_deletes'][] = get_current_blog_id() . ':' . $meta_type . ':' . $meta_key;
	if ( 'post' !== $meta_type ) {
		return true;
	}
	foreach ( $GLOBALS['wpdb']->meta_rows as $id => $meta ) {
		unset( $GLOBALS['wpdb']->meta_rows[ $id ][ $meta_key ] );
	}
	return true;
}

function is_multisite(): bool {
	return ! empty( $GLOBALS['uninstall_test_multisite'] );
}

function get_current_blog_id(): int {
	return (int) ( $GLOBALS['uninstall_test_blog'] ?? 1 );
}

function get_current_network_id(): int {
	return 1;
}

// Pages as core does: number and offset; number 0 means every site.
function get_sites( array $args = [] ): array {
	$GLOBALS['uninstall_test_get_sites'][] = $args;
	$ids    = $GLOBALS['uninstall_test_sites'] ?? [ 1 ];
	$number = (int) ( $args['number'] ?? 100 );
	$ids    = array_slice( $ids, (int) ( $args['offset'] ?? 0 ), $number > 0 ? $number : null );
	return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( static fn ( int $id ): object => (object) [ 'blog_id' => $id ], $ids );
}

// Each blog has its own tables: switching swaps the wpdb double.
function uninstall_test_enter_blog( int $blog_id ): void {
	$GLOBALS['uninstall_test_wpdbs'][ get_current_blog_id() ] = $GLOBALS['wpdb'];
	$GLOBALS['uninstall_test_blog']                           = $blog_id;
	$GLOBALS['wpdb']  = $GLOBALS['uninstall_test_wpdbs'][ $blog_id ] ?? new Uninstall_Test_wpdb();
	$GLOBALS['uninstall_test_wpdbs'][ $blog_id ] = $GLOBALS['wpdb'];
}

/** The table double of one blog. */
function uninstall_test_wpdb( int $blog_id ): Uninstall_Test_wpdb {
	if ( get_current_blog_id() === $blog_id ) {
		return $GLOBALS['wpdb'];
	}
	if ( ! isset( $GLOBALS['uninstall_test_wpdbs'][ $blog_id ] ) ) {
		$GLOBALS['uninstall_test_wpdbs'][ $blog_id ] = new Uninstall_Test_wpdb();
	}
	return $GLOBALS['uninstall_test_wpdbs'][ $blog_id ];
}

function switch_to_blog( $blog_id ): bool {
	$GLOBALS['uninstall_test_blog_stack'][] = get_current_blog_id();
	uninstall_test_enter_blog( (int) $blog_id );
	return true;
}

function restore_current_blog(): bool {
	if ( empty( $GLOBALS['uninstall_test_blog_stack'] ) ) {
		return false;
	}
	uninstall_test_enter_blog( (int) array_pop( $GLOBALS['uninstall_test_blog_stack'] ) );
	return true;
}

function do_action( $tag, ...$args ): void {
	$GLOBALS['uninstall_test_actions'][ $tag ] = ( $GLOBALS['uninstall_test_actions'][ $tag ] ?? 0 ) + 1;
}

function did_action( $tag ): int {
	return (int) ( $GLOBALS['uninstall_test_actions'][ $tag ] ?? 0 );
}

function add_action( ...$args ): bool {
	return true;
}

function add_filter( ...$args ): bool {
	return true;
}

function remove_filter( ...$args ): bool {
	return true;
}

function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ): bool {
	$GLOBALS['uninstall_test_mails'][] = [
		'blog'    => get_current_blog_id(),
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	];
	// Lets a test make each email take time.
	$GLOBALS['uninstall_test_now'] = ( $GLOBALS['uninstall_test_now'] ?? 0.0 ) + ( $GLOBALS['uninstall_test_mail_seconds'] ?? 0.0 );
	return $GLOBALS['uninstall_test_mail_result'] ?? true;
}

// Action Scheduler, as WooCommerce loads it.
function as_unschedule_all_actions( $hook, $args = [], $group = '' ): void {
	$GLOBALS['uninstall_test_as_unscheduled'][] = [
		'blog'  => get_current_blog_id(),
		'hook'  => $hook,
		'args'  => $args,
		'group' => $group,
	];
}

function apply_filters( $tag, $value, ...$args ) {
	return array_key_exists( $tag, $GLOBALS['uninstall_test_filters'] ) ? $GLOBALS['uninstall_test_filters'][ $tag ] : $value;
}

function wp_raise_memory_limit( $context = 'admin' ) {
	return false;
}

function wp_suspend_cache_addition( $suspend = null ): bool {
	return (bool) $suspend;
}

function get_posts( array $args = [] ): array {
	$ids = [];
	foreach ( $GLOBALS['wpdb']->post_rows as $id => $type ) {
		if ( $type === ( $args['post_type'] ?? 'post' ) ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

function wp_delete_post( $post_id, $force = false ) {
	$GLOBALS['uninstall_test_deletes']++;
	$post_id = (int) $post_id;
	unset( $GLOBALS['wpdb']->post_rows[ $post_id ], $GLOBALS['wpdb']->meta_rows[ $post_id ], $GLOBALS['wpdb']->term_rows[ $post_id ] );
	return true;
}

function uninstall_test_remote( string $fn, string $url, array $args ): array {
	$GLOBALS['uninstall_test_remote'][] = [
		'fn'      => $fn,
		'url'     => $url,
		'timeout' => $args['timeout'] ?? null,
		'headers' => (array) ( $args['headers'] ?? [] ),
	];
	return $GLOBALS['uninstall_test_remote_response'] ?? [
		'response' => [ 'code' => 200 ],
		'body'     => '{}',
	];
}

function wp_remote_request( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

function wp_remote_get( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

function wp_remote_post( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

function wp_remote_head( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

function wp_safe_remote_request( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

function wp_safe_remote_get( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

function wp_safe_remote_post( $url, $args = [] ) {
	return uninstall_test_remote( __FUNCTION__, (string) $url, (array) $args );
}

if ( ! class_exists( 'WP_Error' ) ) {
	// Core loads WP_Error in every request, the delete-plugin one included.
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}

if ( ! function_exists( 'get_file_data' ) ) {
	// As WordPress reads a plugin header: "Name: value" lines in the file's first 8 KiB.
	function get_file_data( $file, $default_headers ) {
		$head = (string) file_get_contents( (string) $file, false, null, 0, 8192 );
		$data = [];
		foreach ( (array) $default_headers as $field => $label ) {
			$data[ $field ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( (string) $label, '/' ) . ':(.*)$/mi', $head, $match ) ? trim( $match[1] ) : '';
		}
		return $data;
	}
}

// Core functions the delete-time disconnect of a connected site reaches.
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ): string {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$store          = &uninstall_test_blog_store();
		$store[ $name ] = $value;
		return true;
	}
}
