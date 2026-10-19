<?php

declare(strict_types=1);

class WP_REST_Server {
	const READABLE = 'GET';
	public const CREATABLE = 'POST';
	public const DELETABLE = 'DELETE';
}

class WP_Widget {
	public function __construct( $id_base = '', $name = '', $widget_options = [] ) {
		$GLOBALS['wp_test_widget_definitions'][] = compact( 'id_base', 'name', 'widget_options' );
	}
}

function add_shortcode( string $tag, callable $callback ): void {
	$GLOBALS['wp_test_shortcodes'][ $tag ] = $callback;
}

function register_widget( $widget_class ): void {
	$GLOBALS['wp_test_widgets'][] = $widget_class;
}

function add_meta_box( string $id, string $title, callable $callback, $screen = null, $context = 'advanced', $priority = 'default' ): void {
	$GLOBALS['wp_test_meta_box_definitions'][] = compact( 'id', 'title', 'callback', 'screen', 'context', 'priority' );
	$GLOBALS['wp_test_meta_boxes'][] = $id;
}

class WP_REST_Request {
	private string $method;
	private array $params;
	private array $headers;
	private string $body;
	private string $route;

	public function __construct( string $method, string $route, array $params = [], array $headers = [], string $body = '' ) {
		$this->method  = $method;
		$this->route   = $route;
		$this->params  = $params;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
	}

	public function set_body_params( array $params ): void {
		$this->params = $params;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_header( string $name ): string {
		$name = strtolower( $name );
		return (string) ( $this->headers[ $name ] ?? '' );
	}

	public function get_body(): string {
		return $this->body;
	}

	public function get_route(): string {
		return $this->route;
	}
}

class WP_REST_Response {
	private $data;
	public $status = 200;
	private array $headers = [];

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	public function get_headers(): array {
		return $this->headers;
	}

	public function __construct( $data = null, $status = 200, array $headers = [] ) {
		$this->data    = $data;
		$this->status  = $status;
		$this->headers = $headers;
	}

	public function get_status(): int {
		return (int) $this->status;
	}

	public function get_data() {
		return $this->data;
	}

	public function set_data( $data ): void {
		$this->data = $data;
	}
}

class WP_Error {
	private string $code;
	private string $message;
	private array $data;

	public function __construct( string $code, string $message, array $data = [] ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

class WP_TestProxyStateRow {
	public string $record_key;
	public string $record_kind;
	public int $counter;
	public string $expires_at;
	public ?string $payload;

	public function __construct( string $record_key, string $record_kind, int $counter, string $expires_at, ?string $payload = null ) {
		$this->record_key  = $record_key;
		$this->record_kind = $record_kind;
		$this->counter     = $counter;
		$this->expires_at  = $expires_at;
		$this->payload     = $payload;
	}
}

class WP_Testwpdb {
	public string $prefix      = 'wp_';
	public string $options     = 'wp_options';
	public string $posts       = 'wp_posts';
	public string $postmeta    = 'wp_postmeta';
	public string $term_relationships = 'wp_term_relationships';
	public string $last_error  = '';
	public bool $force_query_failure = false;
	private array $prepared_args = [];
	private string $last_query = '';

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}

	public string $sitemeta    = 'wp_sitemeta';

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->prepared_args = $args;
		$this->last_query      = $query;
		return 'prepared-query';
	}

	public function query( string $query ) {
		unset( $query );

		// The uninstall's prefix delete of options, transients and network options.
		if ( preg_match( '/^DELETE FROM (wp_options|wp_sitemeta) WHERE/', $this->last_query, $table ) ) {
			$names = array_filter( $this->prepared_args, 'is_string' );
			foreach ( $names as $name ) {
				if ( 'wp_sitemeta' === $table[1] ) {
					delete_site_option( $name );
				} elseif ( 0 === strpos( $name, '_transient_timeout_' ) ) {
					unset( $GLOBALS['wp_test_transient_expirations'][ substr( $name, strlen( '_transient_timeout_' ) ) ] );
				} elseif ( 0 === strpos( $name, '_transient_' ) ) {
					delete_transient( substr( $name, strlen( '_transient_' ) ) );
				} else {
					delete_option( $name );
					unset( $GLOBALS['wp_test_raw_options'][ $name ] );
				}
			}
			return count( $names );
		}

		if ( $this->force_query_failure ) {
			$this->last_error = 'forced storage failure';
			return false;
		}

		if ( ! is_array( $GLOBALS['wp_test_proxy_state'] ?? null ) ) {
			$GLOBALS['wp_test_proxy_state'] = [];
		}

		if ( false !== strpos( $this->last_query, 'DELETE FROM' ) && false !== strpos( $this->last_query, 'expires_at <=' ) ) {
			$cutoff = (string) ( $this->prepared_args[0] ?? '' );
			$limit  = (int) ( $this->prepared_args[1] ?? PHP_INT_MAX );
			$deleted = 0;
			foreach ( $GLOBALS['wp_test_proxy_state'] as $key => $row ) {
				if ( $deleted >= $limit ) {
					break;
				}
				if ( $row->expires_at <= $cutoff ) {
					unset( $GLOBALS['wp_test_proxy_state'][ $key ] );
					$deleted++;
				}
			}
			return $deleted;
		}

		if ( false !== strpos( $this->last_query, 'DELETE FROM' ) && false !== strpos( $this->last_query, 'record_key = %s' ) ) {
			$key  = (string) ( $this->prepared_args[0] ?? '' );
			$kind = (string) ( $this->prepared_args[1] ?? '' );
			if ( isset( $GLOBALS['wp_test_proxy_state'][ $key ] ) && $GLOBALS['wp_test_proxy_state'][ $key ]->record_kind === $kind ) {
				unset( $GLOBALS['wp_test_proxy_state'][ $key ] );
				return 1;
			}
			return 0;
		}

		if ( false !== strpos( $this->last_query, 'INSERT INTO' ) && false !== strpos( $this->last_query, 'ON DUPLICATE KEY UPDATE' ) ) {
			$key     = (string) ( $this->prepared_args[0] ?? '' );
			$kind    = (string) ( $this->prepared_args[1] ?? '' );
			$expires = (string) ( $this->prepared_args[2] ?? '' );
			$payload = array_key_exists( 3, $this->prepared_args ) ? (string) ( $this->prepared_args[3] ?? '' ) : null;

			if ( ! isset( $GLOBALS['wp_test_proxy_state'][ $key ] ) ) {
				$GLOBALS['wp_test_proxy_state'][ $key ] = new WP_TestProxyStateRow( $key, $kind, 1, $expires, $payload );
				return 1;
			}

			$GLOBALS['wp_test_proxy_state'][ $key ]->counter    += 1;
			$GLOBALS['wp_test_proxy_state'][ $key ]->expires_at = $expires;
			if ( null !== $payload ) {
				$GLOBALS['wp_test_proxy_state'][ $key ]->payload = $payload;
			}
			return 2;
		}

		if ( false !== strpos( $this->last_query, 'INSERT INTO' ) ) {
			$key     = (string) ( $this->prepared_args[0] ?? '' );
			$kind    = (string) ( $this->prepared_args[1] ?? '' );
			$expires = (string) ( $this->prepared_args[2] ?? '' );
			$payload = array_key_exists( 3, $this->prepared_args ) ? (string) ( $this->prepared_args[3] ?? '' ) : null;

			if ( isset( $GLOBALS['wp_test_proxy_state'][ $key ] ) ) {
				return 0;
			}

			$GLOBALS['wp_test_proxy_state'][ $key ] = new WP_TestProxyStateRow( $key, $kind, 1, $expires, $payload );
			return 1;
		}

		return false;
	}

	public function get_col( string $query ): array {
		unset( $query );

		if ( preg_match( '/^SELECT (option_name FROM wp_options|meta_key FROM wp_sitemeta) WHERE/', $this->last_query, $table ) ) {
			$names = 0 === strpos( $table[1], 'meta_key' ) ? array_keys( $GLOBALS['wp_test_site_options'] ?? [] ) : wp_test_blog_option_names();
			$regex = array_map(
				static fn ( string $like ): string => '/^' . str_replace( [ '\\\\_', '%' ], [ '_', '.*' ], preg_quote( $like, '/' ) ) . '$/',
				array_filter( $this->prepared_args, static fn ( $arg ): bool => is_string( $arg ) && false !== strpos( $arg, '%' ) )
			);
			return array_values(
				array_filter(
					$names,
					static function ( string $name ) use ( $regex ): bool {
						foreach ( $regex as $pattern ) {
							if ( preg_match( $pattern, $name ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}
		// Batched CPT deletes (CleanupInventory::delete_posts_of_type) are covered by
		// UninstallBatchTest against its own table double; here the tables are empty.
		return [];
	}

	public function get_var( string $query ) {
		unset( $query );

		if ( false !== strpos( $this->last_query, 'SELECT payload FROM' ) ) {
			$key     = (string) ( $this->prepared_args[0] ?? '' );
			$kind    = (string) ( $this->prepared_args[1] ?? '' );
			$cutoff  = (string) ( $this->prepared_args[2] ?? '' );
			$row     = $GLOBALS['wp_test_proxy_state'][ $key ] ?? null;
			if ( ! $row || $row->record_kind !== $kind || $row->expires_at <= $cutoff ) {
				return null;
			}

			return $row->payload;
		}

		$key = (string) ( $this->prepared_args[0] ?? '' );
		$row = $GLOBALS['wp_test_proxy_state'][ $key ] ?? null;
		return $row ? (string) $row->counter : null;
	}
}

/** The option names of the current blog, as rows of its options table. */
function wp_test_blog_option_names(): array {
	$blog_id = get_current_blog_id();
	$names   = [];
	foreach ( array_keys( $GLOBALS['wp_test_options_store'] ) as $key ) {
		$key = (string) $key;
		if ( 1 === $blog_id && 0 === preg_match( '/^blog_\d+_/', $key ) ) {
			$names[] = 'translatewp_' . $key;
		} elseif ( 1 !== $blog_id && 0 === strpos( $key, 'blog_' . $blog_id . '_' ) ) {
			$names[] = 'translatewp_' . substr( $key, strlen( 'blog_' . $blog_id . '_' ) );
		}
	}
	if ( 1 === $blog_id ) {
		$names = array_merge( $names, array_map( 'strval', array_keys( $GLOBALS['wp_test_raw_options'] ?? [] ) ) );
		foreach ( array_keys( $GLOBALS['wp_test_transients'] ?? [] ) as $transient ) {
			$names[] = '_transient_' . $transient;
		}
	}
	return $names;
}

function wp_test_proxy_state_store(): array {
	if ( ! isset( $GLOBALS['wp_test_proxy_state'] ) || ! is_array( $GLOBALS['wp_test_proxy_state'] ) ) {
		$GLOBALS['wp_test_proxy_state'] = [];
	}

	return $GLOBALS['wp_test_proxy_state'];
}

$GLOBALS['wp_test_options_store']        = [];
$GLOBALS['wp_test_transients']           = [];
$GLOBALS['wp_test_remote_posts']         = [];
$GLOBALS['wp_test_remote_puts']          = [];
$GLOBALS['wp_test_filters']              = [];
$GLOBALS['wp_test_proxy_state']          = [];
$GLOBALS['wp_test_proxy_schema_version'] = null;
$GLOBALS['wpdb']                         = new WP_Testwpdb();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}

if ( class_exists( \TranslateWP\Includes\TranslationProxySchema::class ) ) {
	\TranslateWP\Includes\TranslationProxySchema::set_table_installer(
		static function (): void {
			// Storage tests exercise claim/rate-limit logic through the wpdb test double.
		}
	);
}

function wp_test_reset( array $options ): void {
	$GLOBALS['wp_test_options_store']        = $options;
	$GLOBALS['wp_test_user_meta']            = [];
	$GLOBALS['wp_test_transients']           = [];
	$GLOBALS['wp_test_shortcodes']           = [];
	$GLOBALS['wp_test_widgets']              = [];
	$GLOBALS['wp_test_meta_boxes']           = [];
	$GLOBALS['wp_test_blocks']               = [];
	$GLOBALS['wp_test_enqueued_scripts']     = [];
	$GLOBALS['wp_test_enqueued_styles']      = [];
	$GLOBALS['wp_test_registered_styles']    = [];
	$GLOBALS['wp_test_inline_styles']        = [];
	$GLOBALS['wp_test_printed_styles']       = [];
	$GLOBALS['wp_test_current_screen']       = null;
	$GLOBALS['wp_test_remote_posts']         = [];
	$GLOBALS['wp_test_remote_puts']          = [];
	$GLOBALS['wp_test_filters']              = [];
	$GLOBALS['wp_test_proxy_state']          = [];
	$GLOBALS['wp_test_proxy_schema_version'] = null;
	$GLOBALS['wpdb']                         = new WP_Testwpdb();
	$GLOBALS['wp_test_posts']                = [];
	$GLOBALS['wp_test_lexicon_posts']        = [];
	$GLOBALS['wp_test_lexicon_meta']         = [];
	$GLOBALS['wp_test_post_types']           = [ 'post', 'page', 'attachment' ];
	$GLOBALS['wp_test_scheduled']            = [];
	$GLOBALS['wp_test_scheduled_log']        = [];
	$GLOBALS['wp_test_index_complete_fail']  = false;
	$GLOBALS['wp_test_translation_job_responses']    = [];
	$GLOBALS['wp_test_translation_batch_responses']  = [];
	$GLOBALS['wp_test_actions_fired']        = [];
	$GLOBALS['wp_test_site_transients']      = [];
	$GLOBALS['wp_test_action_registrations'] = [];
	$GLOBALS['wp_test_nav_menus']            = [];
	$GLOBALS['wp_test_nav_menu_items']       = [];
	$GLOBALS['wp_test_post_statuses']        = [];
	$GLOBALS['wp_test_post_type_map']        = [];
	$GLOBALS['wp_test_url_to_postid']        = [];
	$GLOBALS['wp_test_cache_lookup_items']   = [];
	$GLOBALS['wp_test_subscription_response'] = null;
	$GLOBALS['wp_test_queried_object_id']    = 0;
	$GLOBALS['wp_test_is_singular']          = false;
	$GLOBALS['wp_test_registered_post_meta'] = [];
	$GLOBALS['wp_test_front_page_html']      = null;
	$GLOBALS['wp_test_front_page_fetches']   = 0;
	$GLOBALS['wp_test_remote_get_response']  = null;
	$GLOBALS['wp_test_remote_get_urls']      = [];
	$GLOBALS['wp_test_home_url']             = null;
	$GLOBALS['wp_test_spawn_cron']           = 0;
	$GLOBALS['wp_test_scan_fail']            = false;
	$GLOBALS['wp_test_raw_options']          = [];
	$GLOBALS['wp_test_bloginfo']             = [];
	$GLOBALS['wp_test_cat_names']            = [];
	$GLOBALS['wp_test_inserted_posts']       = [];
	$GLOBALS['wp_test_insert_post_fail']     = false;
	$GLOBALS['wp_test_ext_object_cache']     = false;
	$GLOBALS['wp_test_object_cache']         = [];
	$GLOBALS['wp_test_transient_expirations'] = [];
	$GLOBALS['wp_test_job_missing']          = [];
	$GLOBALS['wp_test_job_states']           = [];
	$GLOBALS['wp_test_job_status_requests']  = [];
	$GLOBALS['wp_test_transient_set_fail']   = false;
	$GLOBALS['wp_test_multisite']            = false;
	$GLOBALS['wp_test_current_blog']         = 1;
	$GLOBALS['wp_test_blog_stack']           = [];
	$GLOBALS['wp_test_sites']                = [];
	$GLOBALS['wp_test_site_options']         = [];
	$GLOBALS['wp_test_caps']                 = [];
	$GLOBALS['wp_test_current_user_id']      = 0;
	$GLOBALS['wp_test_current_user_roles']   = [];
	$GLOBALS['wp_test_current_user']         = 1;
	$GLOBALS['wp_test_super_admin']          = false;
	$GLOBALS['wp_test_redirects']            = [];
	$GLOBALS['wp_test_exchange_posts']       = [];
	$GLOBALS['wp_test_is_revision']          = false;
	$GLOBALS['wp_test_is_autosave']          = false;
	$GLOBALS['wp_test_posts_blog_2']         = [];
	$GLOBALS['wp_test_posts_blog_3']         = [];
	$GLOBALS['wp_test_inserted_posts_blog_2'] = [];
	$GLOBALS['wp_test_inserted_posts_blog_3'] = [];
	$_SERVER['REMOTE_ADDR']            = '203.0.113.10';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
}

function wp_test_set_filter( string $tag, callable $callback ): void {
	$GLOBALS['wp_test_filters'][ $tag ] = $callback;
}

function do_action( $tag, ...$args ) {
	$callback = $GLOBALS['wp_test_filters'][ (string) $tag ] ?? null;
	if ( is_callable( $callback ) ) {
		$callback( ...$args );
	}
	$GLOBALS['wp_test_actions_fired'][] = [ 'tag' => (string) $tag, 'args' => $args ];
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	wp_test_set_filter( (string) $tag, $callback );
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $accepted_args );
	$GLOBALS['wp_test_action_registrations'][] = [ 'tag' => (string) $tag, 'callback' => $callback, 'priority' => (int) $priority ];
	wp_test_set_filter( (string) $tag, $callback );
}

function remove_filter( $tag, $callback = null, $priority = 10 ) {
	unset( $callback, $priority );
	unset( $GLOBALS['wp_test_filters'][ (string) $tag ] );
	return true;
}

function sanitize_text_field( $value ) {
	if ( ! is_string( $value ) ) return '';
	$value = trim( strip_tags( $value ) );
	$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
	return trim( $value );
}

function sanitize_textarea_field( $value ) {
	return sanitize_text_field( $value );
}

function sanitize_key( $value ) {
	return is_string( $value ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ) : '';
}

function absint( $value ): int {
	return abs( (int) $value );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function rest_sanitize_boolean( $value ): bool {
	return (bool) $value;
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ): string {
	if ( isset( $GLOBALS['wp_test_home_url'] ) ) {
		return (string) $GLOBALS['wp_test_home_url'] . $path;
	}

	$blog_id = get_current_blog_id();
	$host    = $GLOBALS['wp_test_site_hosts'][ $blog_id ] ?? ( 1 === $blog_id ? 'example.com' : 'site' . $blog_id . '.example.com' );

	return 'https://' . $host . $path;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/' );
}

function wp_generate_uuid4(): string {
	return '11111111-1111-4111-8111-111111111111';
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth );
}

function wp_using_ext_object_cache(): bool {
	return ! empty( $GLOBALS['wp_test_ext_object_cache'] );
}

function wp_cache_add( string $key, $value, string $group = '', int $ttl = 0 ): bool {
	unset( $ttl );
	$store = &$GLOBALS['wp_test_object_cache'];
	if ( isset( $store[ $group ][ $key ] ) ) {
		return false;
	}
	$store[ $group ][ $key ] = $value;
	return true;
}

function wp_cache_get( string $key, string $group = '' ) {
	return $GLOBALS['wp_test_object_cache'][ $group ][ $key ] ?? false;
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $GLOBALS['wp_test_object_cache'][ $group ][ $key ] );
	return true;
}

function wp_slash( $value ) {
	return $value;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_test_option_key( string $name ): string {
	if ( 0 !== strpos( $name, 'translatewp_' ) ) {
		return $name;
	}

	$blog_id = get_current_blog_id();
	if ( 1 !== $blog_id ) {
		return 'translatewp_blog_' . $blog_id . '_' . substr( $name, strlen( 'translatewp_' ) );
	}

	return $name;
}

function get_option( $name, $default = false ) {
	// Lets a test run another request between this read and what follows it.
	$after_read = $GLOBALS['wp_test_after_option_read'][ (string) $name ] ?? null;
	if ( null !== $after_read ) {
		unset( $GLOBALS['wp_test_after_option_read'][ (string) $name ] );
		$value = get_option( $name, $default );
		$after_read();
		return $value;
	}
	$name = wp_test_option_key( (string) $name );
	if ( 'translatewp_site' === $name ) {
		return $GLOBALS['wp_test_options_store']['site'] ?? $default;
	}
	if ( 'translatewp_languages' === $name ) {
		return $GLOBALS['wp_test_options_store']['languages'] ?? $default;
	}
	if ( 'translatewp_translations' === $name ) {
		return $GLOBALS['wp_test_options_store']['translations'] ?? $default;
	}
	if ( 'translatewp_proxy_state_schema_version' === $name ) {
		return $GLOBALS['wp_test_proxy_schema_version'] ?? $default;
	}
	if ( is_string( $name ) && 0 === strpos( $name, 'translatewp_' ) ) {
		$key = substr( $name, strlen( 'translatewp_' ) );
		return array_key_exists( $key, $GLOBALS['wp_test_options_store'] )
			? $GLOBALS['wp_test_options_store'][ $key ]
			: $default;
	}

	return array_key_exists( (string) $name, $GLOBALS['wp_test_raw_options'] ?? [] )
		? $GLOBALS['wp_test_raw_options'][ (string) $name ]
		: $default;
}

function update_option( $name, $value ) {
	$name = wp_test_option_key( (string) $name );
	if ( 'translatewp_proxy_state_schema_version' === $name ) {
		$GLOBALS['wp_test_proxy_schema_version'] = $value;
		return true;
	}

	if ( is_string( $name ) && 0 === strpos( $name, 'translatewp_' ) ) {
		$key = substr( $name, strlen( 'translatewp_' ) );
		$GLOBALS['wp_test_options_store'][ $key ] = $value;
		return true;
	}

	$GLOBALS['wp_test_raw_options'][ (string) $name ] = $value;
	return true;
}

function delete_option( $name ) {
	$name = wp_test_option_key( (string) $name );
	if ( is_string( $name ) && 0 === strpos( $name, 'translatewp_' ) ) {
		$key = substr( $name, strlen( 'translatewp_' ) );
		// As core: false when there was no such option.
		$existed = array_key_exists( $key, $GLOBALS['wp_test_options_store'] );
		unset( $GLOBALS['wp_test_options_store'][ $key ] );
		return $existed;
	}
	return true;
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( false !== get_option( $name, false ) ) {
			return false;
		}
		return update_option( $name, $value );
	}
}

function flush_rewrite_rules() {
	$GLOBALS['wp_test_flush_count'] = (int) ( $GLOBALS['wp_test_flush_count'] ?? 0 ) + 1;
}

function set_transient( string $key, $value, int $expiration ): bool {
	if ( ! empty( $GLOBALS['wp_test_transient_set_fail'] ) ) {
		return false;
	}
	$GLOBALS['wp_test_transients'][ $key ]           = $value;
	$GLOBALS['wp_test_transient_expirations'][ $key ] = time() + $expiration;
	return true;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['wp_test_transients'][ $key ], $GLOBALS['wp_test_transient_expirations'][ $key ] );
	return true;
}

function get_transient( string $key ) {
	$expires = $GLOBALS['wp_test_transient_expirations'][ $key ] ?? null;
	if ( null !== $expires && $expires <= time() ) {
		unset( $GLOBALS['wp_test_transients'][ $key ], $GLOBALS['wp_test_transient_expirations'][ $key ] );
		return false;
	}
	return $GLOBALS['wp_test_transients'][ $key ] ?? false;
}

function wp_test_blog_store( string $name ): string {
	$blog_id = get_current_blog_id();

	return 1 === $blog_id ? $name : $name . '_blog_' . $blog_id;
}

function get_posts( array $args = [] ) {
	$post_type = $args['post_type'] ?? 'post';

	if ( 'any' === $post_type && isset( $args['meta_key'] ) ) {
		$ids = [];
		foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
			if ( array_key_exists( (string) $args['meta_key'], (array) ( $post['meta_input'] ?? [] ) ) ) {
				$ids[] = (int) $post['ID'];
			}
		}
		return $ids;
	}

	if ( is_array( $post_type ) ) {
		$ids = [];
		foreach ( $post_type as $type ) {
			$ids = array_merge( $ids, get_posts( array_merge( $args, [ 'post_type' => (string) $type ] ) ) );
		}
		return $ids;
	}
	$post_type = (string) $post_type;

	if ( 'lexicon' === $post_type ) {
		$posts = $GLOBALS['wp_test_lexicon_posts'] ?? [];
		$ids   = array_keys( $posts );
		if ( isset( $args['orderby'] ) && 'ID' === $args['orderby'] ) {
			sort( $ids, SORT_NUMERIC );
			if ( isset( $args['order'] ) && 'DESC' === $args['order'] ) {
				$ids = array_reverse( $ids );
			}
		}

		return array_map(
			static function ( int $id ) use ( $posts ): object {
				return (object) [
					'ID'           => $id,
					'post_title'   => (string) ( $posts[ $id ]['title'] ?? '' ),
					'post_content' => (string) ( $posts[ $id ]['content'] ?? '' ),
					'post_date'    => (string) ( $posts[ $id ]['date'] ?? gmdate( 'Y-m-d H:i:s', wp_test_time() ) ),
					'post_status'  => (string) ( $posts[ $id ]['status'] ?? 'publish' ),
				];
			},
			$ids
		);
	}

	$store     = $GLOBALS[ wp_test_blog_store( 'wp_test_posts' ) ] ?? [];
	$ids       = array_map( 'intval', $store[ $post_type ] ?? [] );

	if ( isset( $args['orderby'] ) && 'ID' === $args['orderby'] ) {
		sort( $ids, SORT_NUMERIC );
		if ( isset( $args['order'] ) && 'DESC' === $args['order'] ) {
			$ids = array_reverse( $ids );
		}
	}

	if ( 'ids' !== ( $args['fields'] ?? '' ) && 'twp_index_item' !== $post_type ) {
		$inserted = $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [];
		$objects  = [];
		foreach ( $ids as $post_id ) {
			foreach ( $inserted as $post ) {
				if ( (int) $post['ID'] === $post_id && (string) ( $post['post_type'] ?? '' ) === $post_type ) {
					$objects[] = (object) [
						'ID'           => $post_id,
						'post_title'   => (string) ( $post['post_title'] ?? '' ),
						'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
						'post_content' => (string) ( $post['post_content'] ?? '' ),
						'post_status'  => (string) ( $post['post_status'] ?? 'publish' ),
					];
					break;
				}
			}
		}
		return $objects;
	}

	if ( 'twp_index_item' === $post_type ) {
		$inserted = $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [];
		$ids      = array_values(
			array_filter(
				$ids,
				static function ( int $post_id ) use ( $inserted ): bool {
					foreach ( $inserted as $post ) {
						if ( (int) $post['ID'] === $post_id ) {
							return 'twp_index_item' === (string) ( $post['post_type'] ?? '' );
						}
					}

					return false;
				}
			)
		);

		$meta_query = (array) ( $args['meta_query'] ?? [] );
		if ( $meta_query ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( int $post_id ) use ( $inserted, $meta_query ): bool {
						foreach ( $inserted as $post ) {
							if ( (int) $post['ID'] !== $post_id ) {
								continue;
							}

							foreach ( $meta_query as $clause ) {
								$key   = (string) ( $clause['key'] ?? '' );
								$value = $clause['value'] ?? null;
								if ( '' === $key ) {
									continue;
								}

								if ( (string) ( $post['meta_input'][ $key ] ?? '' ) !== (string) $value ) {
									return false;
								}
							}

							return true;
						}

						return false;
					}
				)
			);
		}
	}

	return $ids;
}

function url_to_postid( $url ) {
	return (int) ( $GLOBALS['wp_test_url_to_postid'][ (string) $url ] ?? 0 );
}

function wp_insert_post( array $postarr, $wp_error = false ) {
	unset( $wp_error );
	if ( ! empty( $GLOBALS['wp_test_insert_post_fail'] ) ) {
		return false;
	}
	$store                                = $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [];
	$id                                   = count( $store ) + 9000;
	$postarr['ID']                        = $id;
	$postarr['post_content']              = (string) ( $postarr['post_content'] ?? '' );
	$postarr['meta_input']                = $postarr['meta_input'] ?? [];
	$store[]                              = $postarr;
	$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] = $store;
	$GLOBALS[ wp_test_blog_store( 'wp_test_posts' ) ][ (string) ( $postarr['post_type'] ?? 'post' ) ][] = $id;
	return $id;
}

function wp_update_post( array $postarr, $wp_error = false ) {
	unset( $wp_error );
	$store = $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [];
	foreach ( $store as $index => $post ) {
		if ( (int) $post['ID'] === (int) ( $postarr['ID'] ?? 0 ) ) {
			$store[ $index ] = array_merge( $post, $postarr );
			$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] = $store;
			return (int) $postarr['ID'];
		}
	}
	return (int) ( $postarr['ID'] ?? 0 );
}

function wp_delete_post( $post_id, $force = false ) {
	unset( $force );
	$post_id = (int) $post_id;

	$inserted_key = wp_test_blog_store( 'wp_test_inserted_posts' );
	if ( isset( $GLOBALS[ $inserted_key ] ) && is_array( $GLOBALS[ $inserted_key ] ) ) {
		$GLOBALS[ $inserted_key ] = array_values(
			array_filter(
				$GLOBALS[ $inserted_key ],
				static function ( array $post ) use ( $post_id ): bool {
					return (int) ( $post['ID'] ?? 0 ) !== $post_id;
				}
			)
		);
	}

	foreach ( $GLOBALS['wp_test_nav_menu_items'] ?? [] as $menu_key => $menu_items ) {
		$GLOBALS['wp_test_nav_menu_items'][ $menu_key ] = array_values(
			array_filter(
				(array) $menu_items,
				static function ( $item ) use ( $post_id ): bool {
					return (int) ( $item->ID ?? 0 ) !== $post_id;
				}
			)
		);
	}

	$posts_key = wp_test_blog_store( 'wp_test_posts' );
	if ( isset( $GLOBALS[ $posts_key ] ) && is_array( $GLOBALS[ $posts_key ] ) ) {
		foreach ( $GLOBALS[ $posts_key ] as $type => $ids ) {
			$GLOBALS[ $posts_key ][ $type ] = array_values(
				array_filter(
					array_map( 'intval', (array) $ids ),
					static function ( int $id ) use ( $post_id ): bool {
						return $id !== $post_id;
					}
				)
			);
		}
	}

	return true;
}

function register_post_type( $post_type, $args = [] ) {
	unset( $post_type, $args );
	return true;
}

function get_post_field( $field, $post_id ) {
	foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
		if ( (int) $post['ID'] === (int) $post_id ) {
			return (string) ( $post[ $field ] ?? '' );
		}
	}
	return '';
}

function get_post_meta( $post_id, $key, $single = false ) {
	unset( $single );
	$lexicon_meta = $GLOBALS['wp_test_lexicon_meta'][ (int) $post_id ] ?? null;
	if ( is_array( $lexicon_meta ) && array_key_exists( $key, $lexicon_meta ) ) {
		return $lexicon_meta[ $key ];
	}
	foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
		if ( (int) $post['ID'] === (int) $post_id ) {
			return $post['meta_input'][ $key ] ?? '';
		}
	}
	foreach ( $GLOBALS['wp_test_nav_menu_items'] ?? [] as $menu_items ) {
		foreach ( $menu_items as $item ) {
			if ( (int) ( $item->ID ?? 0 ) === (int) $post_id ) {
				return $item->meta_input[ $key ] ?? '';
			}
		}
	}
	return '';
}

function update_post_meta( $post_id, $key, $value ) {
	$store = $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [];
	foreach ( $store as $index => $post ) {
		if ( (int) $post['ID'] === (int) $post_id ) {
			$store[ $index ]['meta_input'][ $key ] = $value;
			$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] = $store;
			return true;
		}
	}
	foreach ( $GLOBALS['wp_test_nav_menu_items'] ?? [] as $menu_key => $menu_items ) {
		foreach ( $menu_items as $index => $item ) {
			if ( (int) ( $item->ID ?? 0 ) === (int) $post_id ) {
				$item->meta_input[ $key ] = $value;
				$GLOBALS['wp_test_nav_menu_items'][ $menu_key ][ $index ] = $item;
				return true;
			}
		}
	}
	return false;
}

function delete_post_meta( $post_id, $key ) {
	$store = $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [];
	foreach ( $store as $index => $post ) {
		if ( (int) $post['ID'] === (int) $post_id ) {
			unset( $store[ $index ]['meta_input'][ $key ] );
			$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] = $store;
			return true;
		}
	}
	return false;
}

function update_meta_cache( $type, $ids ) {
	unset( $type, $ids );
	return true;
}

function wp_get_nav_menus() {
	return $GLOBALS['wp_test_nav_menus'] ?? [];
}

function wp_get_nav_menu_items( $menu ) {
	$items = $GLOBALS['wp_test_nav_menu_items'][ is_object( $menu ) ? $menu->term_id : (string) $menu ] ?? [];
	return $items ?: false;
}

function wp_update_nav_menu_item( $menu_id, $menu_item_db_id, $menu_item_data ) {
	unset( $menu_id );
	$items = $GLOBALS['wp_test_nav_menu_items']['main'] ?? [];

	$mapped = [
		'type'      => $menu_item_data['menu-item-type'] ?? 'custom',
		'object'    => $menu_item_data['menu-item-object'] ?? '',
		'object_id' => (int) ( $menu_item_data['menu-item-object-id'] ?? 0 ),
		'title'     => $menu_item_data['menu-item-title'] ?? '',
		'url'       => $menu_item_data['menu-item-url'] ?? '',
	];

	if ( 0 === (int) $menu_item_db_id ) {
		$id = count( $items ) + 1;
		$items[] = (object) array_merge(
			[
				'ID'         => $id,
				'type'       => 'custom',
				'object'     => '',
				'object_id'  => 0,
				'title'      => '',
				'url'        => '',
				'meta_input' => [],
			],
			$mapped
		);
		$GLOBALS['wp_test_nav_menu_items']['main'] = $items;
		return $id;
	}

	foreach ( $items as $index => $item ) {
		if ( (int) $item->ID === (int) $menu_item_db_id ) {
			$items[ $index ] = (object) array_merge( (array) $item, $mapped );
			$GLOBALS['wp_test_nav_menu_items']['main'] = $items;
			return (int) $menu_item_db_id;
		}
	}

	return 0;
}

function wp_setup_nav_menu_item( $menu_item ) {
	return $menu_item;
}

function get_post_status( $post_id ) {
	return $GLOBALS['wp_test_post_statuses'][ (int) $post_id ] ?? 'publish';
}

function get_the_title( $post_id ) {
	if ( is_object( $post_id ) ) {
		return (string) ( $post_id->post_title ?? '' );
	}
	foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
		if ( (int) ( $post['ID'] ?? 0 ) === (int) $post_id && '' !== (string) ( $post['post_title'] ?? '' ) ) {
			return (string) $post['post_title'];
		}
	}
	return 'Title ' . (int) $post_id;
}

function get_bloginfo( $show = '' ) {
	return (string) ( $GLOBALS['wp_test_bloginfo'][ (string) $show ] ?? '' );
}

function get_cat_name( $cat_id ) {
	return (string) ( $GLOBALS['wp_test_cat_names'][ (int) $cat_id ] ?? '' );
}

function get_post_type( $post_id ) {
	return $GLOBALS['wp_test_post_type_map'][ (int) $post_id ] ?? 'page';
}

function get_permalink( $post_id ) {
	$overrides = $GLOBALS['wp_test_permalinks'] ?? [];
	if ( array_key_exists( (int) $post_id, $overrides ) ) {
		return $overrides[ (int) $post_id ];
	}
	return 'https://example.com/?p=' . (int) $post_id;
}

function get_post_types( array $args = [], string $output = 'names' ) {
	unset( $args, $output );
	$types = $GLOBALS['wp_test_post_types'] ?? [ 'post', 'page', 'attachment' ];
	return array_combine( $types, $types );
}

function wp_generate_password( int $length = 12, bool $special_chars = true ) {
	unset( $special_chars );
	return substr( md5( (string) ( $GLOBALS['wp_test_password_seed'] = ( $GLOBALS['wp_test_password_seed'] ?? 0 ) + 1 ) ), 0, $length );
}

function wp_next_scheduled( string $hook ) {
	return $GLOBALS['wp_test_scheduled'][ $hook ] ?? false;
}

function wp_unschedule_event( int $timestamp, string $hook ): bool {
	unset( $timestamp );
	unset( $GLOBALS['wp_test_scheduled'][ $hook ] );
	return true;
}

function wp_unschedule_hook( string $hook ): int {
	$cleared = isset( $GLOBALS['wp_test_scheduled'][ $hook ] ) ? 1 : 0;
	unset( $GLOBALS['wp_test_scheduled'][ $hook ] );
	return $cleared;
}

function wp_clear_scheduled_hook( string $hook ): int {
	$cleared = isset( $GLOBALS['wp_test_scheduled'][ $hook ] ) ? 1 : 0;
	unset( $GLOBALS['wp_test_scheduled'][ $hook ] );
	return $cleared;
}

function get_current_screen() {
	return $GLOBALS['wp_test_current_screen'] ?? null;
}

function wp_enqueue_style( string $handle, string $src = '', array $deps = [], $ver = false ): void {
	unset( $src, $deps, $ver );
	$GLOBALS['wp_test_enqueued_styles'][] = $handle;
}

function wp_enqueue_script( string $handle, string $src = '', array $deps = [], $ver = false, $args = false ): void {
	unset( $src, $deps, $ver, $args );
	$GLOBALS['wp_test_enqueued_scripts'][] = $handle;
}

function wp_set_script_translations( string $handle, string $domain, string $path ): bool {
	unset( $handle, $domain, $path );
	return true;
}

function register_block_type( string $block_type, array $args = [] ) {
	$GLOBALS['wp_test_blocks'][ $block_type ] = $args;
	return (object) [ 'name' => $block_type ];
}

function register_block_type_from_metadata( string $path, array $args = [] ) {
	$metadata = json_decode( (string) file_get_contents( $path . '/block.json' ), true );
	if ( isset( $metadata['editorScript'] ) ) {
		$metadata['editor_script'] = $metadata['editorScript'];
	}
	return register_block_type( (string) ( $metadata['name'] ?? '' ), array_merge( $metadata ?? [], $args ) );
}

function wp_register_style( string $handle, $src = '', array $deps = [], $ver = false ): bool {
	unset( $src, $deps, $ver );
	$GLOBALS['wp_test_registered_styles'][ $handle ] = true;
	return true;
}

function wp_add_inline_style( string $handle, string $data ): void {
	if ( empty( $GLOBALS['wp_test_registered_styles'][ $handle ] ) ) {
		return;
	}
	$GLOBALS['wp_test_inline_styles'][ $handle ] = $data;
}

function wp_print_styles( $handles = false ): void {
	unset( $handles );
	foreach ( $GLOBALS['wp_test_enqueued_styles'] ?? [] as $handle ) {
		if ( ! empty( $GLOBALS['wp_test_inline_styles'][ $handle ] ) ) {
			$GLOBALS['wp_test_printed_styles'][] = $GLOBALS['wp_test_inline_styles'][ $handle ];
		}
	}
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ) {
	$GLOBALS['wp_test_scheduled'][ $hook ] = $timestamp;
	$GLOBALS['wp_test_scheduled_log'][]    = [ 'hook' => $hook, 'timestamp' => $timestamp, 'args' => $args ];
	return true;
}

function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
	$GLOBALS['wp_test_mails'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
	$GLOBALS['wp_test_mail_from']      = apply_filters( 'wp_mail_from', 'wordpress@example.com' );
	$GLOBALS['wp_test_mail_from_name'] = apply_filters( 'wp_mail_from_name', 'WordPress' );
	return $GLOBALS['wp_test_mail_result'] ?? true;
}

function apply_filters( $tag, $value, ...$args ) {
	$callback = $GLOBALS['wp_test_filters'][ $tag ] ?? null;
	if ( is_callable( $callback ) ) {
		return $callback( $value, ...$args );
	}

	return $value;
}

function dbDelta( $queries = '' ) {
	unset( $queries );
}

function wp_remote_post( string $url, array $args = [] ) {
	return wp_remote_request( $url, array_merge( $args, [ 'method' => 'POST' ] ) );
}

function wp_remote_request( string $url, array $args = [] ) {
	$path   = (string) parse_url( $url, PHP_URL_PATH );
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$body   = json_decode( (string) ( $args['body'] ?? '' ), true );

	if ( '/v1/translation/telemetry/served' === $path ) {
		return apply_filters( 'pre_http_request', false, $args, $url );
	}

	if ( 'PUT' === $method && '/v1/site/languages' === $path ) {
		$GLOBALS['wp_test_remote_puts'][] = [
			'method'  => $method,
			'path'    => $path,
			'body'    => is_array( $body ) ? $body : [],
			'blog_id' => get_current_blog_id(),
		];

		if ( ! empty( $GLOBALS['wp_test_site_language_sync_fail'] ) ) {
			return [
				'response' => [ 'code' => 503 ],
				'body'     => wp_json_encode( [ 'error' => 'Site languages unavailable', 'code' => 'site_languages_unavailable' ] ),
			];
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'siteId'    => '00000000-0000-4000-8000-000000000001',
					'languages' => $body['languages'] ?? [],
				]
			),
		];
	}

	if ( 'POST' === $method && '/v1/connect/exchange' === $path ) {
		$GLOBALS['wp_test_exchange_posts'][] = [
			'blog_id' => get_current_blog_id(),
			'body'    => is_array( $body ) ? $body : [],
		];

		if ( ! empty( $GLOBALS['wp_test_exchange_fail'] ) ) {
			return [
				'response' => [ 'code' => 401 ],
				'body'     => wp_json_encode( [ 'error' => 'Connect code is invalid or expired', 'code' => 'invalid_connect_code' ] ),
			];
		}

		$host = (string) ( $body['host'] ?? 'example.com' );
		$uuid = '00000000-0000-4000-8000-' . substr( md5( $host ), 0, 12 );

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'siteId'       => $uuid,
					'token'        => 'token-' . $host,
					'accountLabel' => 'owner@example.com',
					'reusedSiteId' => in_array( $host, (array) ( $GLOBALS['wp_test_exchange_reused_hosts'] ?? [] ), true ),
				]
			),
		];
	}

	if ( 'POST' === $method && '/v1/site/disconnect' === $path ) {
		$GLOBALS['wp_test_remote_disconnect_posts'][] = [
			'method'  => $method,
			'path'    => $path,
			'body'    => is_array( $body ) ? $body : [],
			'timeout' => $args['timeout'] ?? null,
			'token'   => (string) ( $args['headers']['Authorization'] ?? '' ),
		];

		$override = $GLOBALS['wp_test_disconnect_response'] ?? null;
		if ( is_array( $override ) ) {
			return $override;
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'ok' => true ] ),
		];
	}
	if ( 'POST' === $method && '/v1/translation/cache/clear' === $path ) {
		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : [];
		$GLOBALS['wp_test_remote_posts'][] = [
			'path'    => $path,
			'body'    => is_array( $body ) ? $body : [],
			'headers' => $headers,
		];

		if ( 0 !== strpos( (string) ( $headers['Authorization'] ?? '' ), 'Bearer ' ) ) {
			return [
				'response' => [ 'code' => 401 ],
				'body'     => wp_json_encode( [ 'error' => 'Site authentication required', 'code' => 'invalid_site_token' ] ),
			];
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'ok' => true, 'deleted' => 12 ] ),
		];
	}

	if ( 'POST' === $method && '/v1/scanning/context' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [
			'path'    => $path,
			'body'    => is_array( $body ) ? $body : [],
			'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [],
		];

		if ( ! empty( $GLOBALS['wp_test_scan_fail'] ) ) {
			return new WP_Error( 'remote_error', 'scan rejected' );
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'context'    => 'A site about translation',
					'language'   => 'LV',
					'style'      => 'neutral',
					'tone'       => 'friendly',
					'formality'  => 5,
					'creativity' => 5,
					'lexicon'    => [
						[ 'term' => 'Karabiner', 'description' => 'A product name, never translated.', 'category' => 'Product' ],
						[ 'term' => 'Seilpartner', 'description' => 'The brand name.', 'category' => 'Brand' ],
					],
				]
			),
		];
	}

	if ( 'POST' === $method && '/v1/site/index-complete' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [
			'path' => $path,
			'body' => is_array( $body ) ? $body : [],
		];

		if ( ! empty( $GLOBALS['wp_test_index_complete_fail'] ) ) {
			return new WP_Error( 'remote_error', 'index-complete rejected' );
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'siteId'                => '00000000-0000-4000-8000-000000000001',
					'language'              => (string) ( $body['language'] ?? '' ),
					'initialIndexCompleted' => false,
				]
			),
		];
	}

	if ( 'POST' === $method && '/v1/translation/cache/lookup-batch' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [
			'path' => $path,
			'body' => is_array( $body ) ? $body : [],
		];

		if ( ! empty( $GLOBALS['wp_test_cache_lookup_rate_limited'] ) ) {
			return [
				'response' => [ 'code' => 429 ],
				'body'     => wp_json_encode( [ 'error' => 'Too many cache lookup requests', 'code' => 'rate_limited', 'retryAfterSeconds' => 40 ] ),
			];
		}

		$hashes = is_array( $body['hashes'] ?? null ) ? $body['hashes'] : [];
		$items  = [];
		foreach ( $hashes as $hash ) {
			$items[ (string) $hash ] = $GLOBALS['wp_test_cache_lookup_items'][ (string) $hash ] ?? null;
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'siteId'   => (string) ( $body['siteId'] ?? '' ),
					'language' => (string) ( $body['language'] ?? '' ),
					'items'    => $items,
				]
			),
		];
	}

	if ( 'POST' === $method && '/v1/translation/cache/subscriptions' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [
			'path'    => $path,
			'body'    => is_array( $body ) ? $body : [],
			'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [],
		];

		if ( null !== $GLOBALS['wp_test_subscription_response'] ) {
			return $GLOBALS['wp_test_subscription_response'];
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'url'       => 'https://api.translatewp.ai/v1/translation/cache/events?token=opaque-token',
					'expiresAt' => gmdate( 'c', time() + 300 ),
				]
			),
		];
	}

	if ( '/v1/translation/jobs' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [
			'path' => $path,
			'body' => is_array( $body ) ? $body : [],
		];

		if ( ! empty( $GLOBALS['wp_test_translation_job_responses'] ) ) {
			return array_shift( $GLOBALS['wp_test_translation_job_responses'] );
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'jobId'      => '00000000-0000-4000-8000-000000000099',
					'sourceLang' => $body['sourceLang'] ?? 'en',
					'targetLang' => $body['targetLang'] ?? 'fr',
				]
			),
		];
	}

	if ( 'POST' === $method && '/v1/pro/byok/verify' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [ 'path' => $path, 'body' => is_array( $body ) ? $body : [] ];
		$decide = $GLOBALS['wp_test_byok_verify'] ?? null;
		$answer = is_callable( $decide ) ? $decide( is_array( $body ) ? $body : [] ) : null;
		if ( $answer instanceof WP_Error ) {
			$data = (array) $answer->get_error_data();
			return [ 'response' => [ 'code' => (int) ( $data['status'] ?? 422 ) ], 'body' => wp_json_encode( $data['payload'] ?? [ 'error' => 'byok_provider_error' ] ) ];
		}

		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'ok' => true, 'model' => (string) ( $body['modelId'] ?? '' ) ] ) ];
	}

	if ( 'POST' === $method && '/v1/pro/byok/models' === $path ) {
		$GLOBALS['wp_test_remote_posts'][] = [ 'path' => $path, 'body' => is_array( $body ) ? $body : [] ];
		$answers  = $GLOBALS['wp_test_byok_model_lists'] ?? [];
		$provider = (string) ( $body['provider'] ?? '' );
		$answer   = $answers[ $provider ] ?? $answers['*'] ?? null;
		if ( null === $answer ) {
			return [ 'response' => [ 'code' => 404 ], 'body' => wp_json_encode( [ 'error' => 'not found' ] ) ];
		}
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}

		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $answer ) ];
	}

	if ( 'POST' === $method && preg_match( '#^/v1/translation/jobs/([^/]+)/cancel$#', $path ) ) {
		$GLOBALS['wp_test_remote_posts'][] = [ 'path' => $path, 'body' => [] ];

		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'ok' => true ] ) ];
	}

	if ( preg_match( '#^/v1/translation/jobs/([^/]+)/batches$#', $path, $matches ) ) {
		if ( ! empty( $GLOBALS['wp_test_batch_post_failures'] ) ) {
			$GLOBALS['wp_test_batch_post_failures']--;
			return new WP_Error( 'remote_error', 'Upstream batch rejected' );
		}

		$GLOBALS['wp_test_remote_posts'][] = [
			'path' => $path,
			'body' => is_array( $body ) ? $body : [],
		];

		if ( ! empty( $GLOBALS['wp_test_translation_batch_responses'] ) ) {
			return array_shift( $GLOBALS['wp_test_translation_batch_responses'] );
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'ok'       => true,
					'batchId'  => $body['batchId'] ?? 'batch_1',
					'accepted' => count( $body['items'] ?? [] ),
				]
			),
		];
	}

	if ( 0 === strpos( $path, '/v1/site/lexicon/' ) ) {
		$GLOBALS['wp_test_remote_posts'][] = [
			'method' => $method,
			'path'   => $path,
			'body'   => is_array( $body ) ? $body : [],
		];
		if ( isset( $GLOBALS['wp_test_remote_response'] ) ) {
			return $GLOBALS['wp_test_remote_response'];
		}
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [] ),
		];
	}

	return [
		'response' => [ 'code' => 404 ],
		'body'     => wp_json_encode( [ 'error' => 'not found' ] ),
	];
}

function wp_remote_get( string $url, array $args = [] ) {
	unset( $args );
	$path = (string) parse_url( $url, PHP_URL_PATH );

	$GLOBALS['wp_test_remote_get_urls'][] = $url;

	if ( isset( $GLOBALS['wp_test_remote_get_response'] ) ) {
		return $GLOBALS['wp_test_remote_get_response'];
	}

	if ( null !== ( $GLOBALS['wp_test_front_page_html'] ?? null ) && false !== strpos( $url, 'example.com' ) && false === strpos( $url, '/v1/' ) ) {
		$GLOBALS['wp_test_front_page_fetches'] = (int) ( $GLOBALS['wp_test_front_page_fetches'] ?? 0 ) + 1;
		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) $GLOBALS['wp_test_front_page_html'],
		];
	}

	if ( preg_match( '#^/v1/translation/jobs/([^/]+)/status$#', $path, $job_match ) ) {
		$GLOBALS['wp_test_job_status_requests'][] = $job_match[1];
		if ( in_array( $job_match[1], $GLOBALS['wp_test_job_missing'] ?? [], true ) ) {
			return [
				'response' => [ 'code' => 404 ],
				'body'     => wp_json_encode( [ 'error' => 'Unknown job' ] ),
			];
		}
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'state'     => $GLOBALS['wp_test_job_states'][ $job_match[1] ] ?? 'open',
					'createdAt' => '2026-08-15T00:00:00Z',
				]
			),
		];
	}

	return [
		'response' => [ 'code' => 404 ],
		'body'     => wp_json_encode( [ 'error' => 'not found' ] ),
	];
}

function wp_remote_retrieve_response_code( array $response ): int {
	if ( $response instanceof WP_Error ) {
		return 502;
	}

	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	if ( $response instanceof WP_Error ) {
		return wp_json_encode( [ 'error' => $response->get_error_message() ] );
	}

	return (string) ( $response['body'] ?? '' );
}

function wp_test_gmdate( string $format, int $timestamp = null ): string {
	if ( null === $timestamp ) {
		$timestamp = wp_test_time();
	}

	return \gmdate( $format, $timestamp );
}

function wp_test_time(): int {
	if ( isset( $GLOBALS['wp_test_now'] ) ) {
		return (int) $GLOBALS['wp_test_now'];
	}

	return \time();
}

function current_time( string $type, int $gmt = 0 ) {
	unset( $gmt );
	$timestamp = wp_test_time();

	if ( 'timestamp' === $type || 'U' === $type ) {
		return $timestamp;
	}

	if ( 'mysql' === $type ) {
		return \gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	return \gmdate( $type, $timestamp );
}

function is_multisite(): bool {
	return ! empty( $GLOBALS['wp_test_multisite'] );
}

function get_main_site_id(): int {
	return (int) ( $GLOBALS['wp_test_main_site'] ?? 1 );
}

function get_current_blog_id(): int {
	return (int) ( $GLOBALS['wp_test_current_blog'] ?? 1 );
}

function get_sites( array $args = [] ) {
	$sites = $GLOBALS['wp_test_sites'] ?? [];
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_map(
			static function ( array $site ): int {
				return (int) $site['blog_id'];
			},
			$sites
		);
	}

	return $sites;
}

function get_site( $site_id ) {
	$site_id = (int) $site_id;
	foreach ( $GLOBALS['wp_test_sites'] ?? [] as $site ) {
		if ( (int) $site['blog_id'] === $site_id ) {
			return (object) $site;
		}
	}

	return null;
}

function get_blog_details( $blog_id ) {
	return get_site( $blog_id );
}

function switch_to_blog( int $blog_id ): bool {
	$GLOBALS['wp_test_blog_stack'][] = get_current_blog_id();
	$GLOBALS['wp_test_current_blog'] = $blog_id;
	return true;
}

function restore_current_blog(): bool {
	$stack = $GLOBALS['wp_test_blog_stack'] ?? [];
	if ( empty( $stack ) ) {
		return false;
	}

	$GLOBALS['wp_test_current_blog'] = (int) array_pop( $stack );
	$GLOBALS['wp_test_blog_stack']   = $stack;
	return true;
}

function get_current_network_id(): int {
	return 1;
}

function get_site_option( string $option, $default = false ) {
	return $GLOBALS['wp_test_site_options'][ $option ] ?? $default;
}

function update_site_option( string $option, $value ): bool {
	$GLOBALS['wp_test_site_options'][ $option ] = $value;
	return true;
}

function delete_site_option( string $option ): bool {
	unset( $GLOBALS['wp_test_site_options'][ $option ] );
	return true;
}

function current_user_can( string $capability ): bool {
	return in_array( $capability, $GLOBALS['wp_test_caps'] ?? [], true );
}

function current_user_can_for_site( int $blog_id, string $capability ): bool {
	return in_array( $capability, $GLOBALS['wp_test_site_caps'][ $blog_id ] ?? $GLOBALS['wp_test_caps'] ?? [], true );
}

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	class WP_Admin_Bar {
		public object $user;
		private array $nodes = [];

		public function __construct() {
			$this->user = (object) [ 'blogs' => [] ];
		}

		public function add_node( array $node ): void {
			$this->nodes[ (string) $node['id'] ] = (object) $node;
		}

		public function add_group( array $node ): void {
			$this->add_node( $node + [ 'group' => true ] );
		}

		public function get_node( string $id ): ?object {
			return $this->nodes[ $id ] ?? null;
		}

		public function get_nodes(): array {
			return $this->nodes;
		}
	}
}

function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
	$value = $GLOBALS['wp_test_user_meta'][ $user_id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? [] : [ $value ] );
}

function update_user_meta( int $user_id, string $key, $value ): bool {
	$GLOBALS['wp_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['wp_test_current_user'] ?? 1 );
}

function is_user_logged_in(): bool {
	return ! empty( $GLOBALS['wp_test_logged_in'] );
}

function is_network_admin(): bool {
	return ! empty( $GLOBALS['wp_test_network_admin'] );
}

function get_users( array $args = [] ): array {
	$blog_id = (int) ( $args['blog_id'] ?? 0 );
	return array_values( array_filter(
		$GLOBALS['wp_test_users'] ?? [],
		static fn( $user ) => 0 === $blog_id || in_array( $blog_id, $user->blog_ids ?? [], true )
	) );
}

function is_super_admin( int $user_id = 0 ): bool {
	unset( $user_id );
	return ! empty( $GLOBALS['wp_test_super_admin'] );
}

function user_can( int $user_id, string $capability ): bool {
	// Capabilities of a named user when a test sets them; the current user's otherwise.
	if ( isset( $GLOBALS['wp_test_user_caps'][ $user_id ] ) ) {
		return in_array( $capability, $GLOBALS['wp_test_user_caps'][ $user_id ], true );
	}
	return in_array( $capability, $GLOBALS['wp_test_caps'] ?? [], true );
}

function network_admin_url( string $path = '' ): string {
	return 'https://example.com/wp-admin/network/' . ltrim( $path, '/' );
}

function admin_url( string $path = '' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}

function get_admin_url( $blog_id, string $path = '' ): string {
	unset( $blog_id );
	return admin_url( $path );
}

// Like WordPress: a safe redirect only leaves for the site's own host; any
// other host falls back to the admin home.
function wp_safe_redirect( string $location, int $status = 302 ): bool {
	unset( $status );
	$host = (string) wp_parse_url( $location, PHP_URL_HOST );
	$own  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$GLOBALS['wp_test_redirects'][] = ( '' === $host || $host === $own ) ? $location : admin_url();
	return true;
}

function wp_redirect( string $location, int $status = 302 ): bool {
	unset( $status );
	$GLOBALS['wp_test_redirects'][] = $location;
	return true;
}

function add_query_arg( ...$args ) {
	if ( 2 === count( $args ) && is_array( $args[0] ) ) {
		$url      = (string) $args[1];
		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}
		$query_pos = strpos( $url, '?' );
		$base      = false === $query_pos ? $url : substr( $url, 0, $query_pos );
		$existing  = [];
		if ( false !== $query_pos ) {
			parse_str( substr( $url, $query_pos + 1 ), $existing );
		}
		$merged = array_merge( $existing, $args[0] );
		return $base . '?' . http_build_query( $merged ) . $fragment;
	}

	$url      = (string) ( $args[2] ?? '' );
	$fragment = '';
	$hash_pos = strpos( $url, '#' );
	if ( false !== $hash_pos ) {
		$fragment = substr( $url, $hash_pos );
		$url      = substr( $url, 0, $hash_pos );
	}
	$query_pos = strpos( $url, '?' );
	$base      = false === $query_pos ? $url : substr( $url, 0, $query_pos );
	$existing  = [];
	if ( false !== $query_pos ) {
		parse_str( substr( $url, $query_pos + 1 ), $existing );
	}
	$existing[ (string) ( $args[0] ?? '' ) ] = (string) ( $args[1] ?? '' );
	return $base . '?' . http_build_query( $existing ) . $fragment;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ): bool {
	unset( $action, $query_arg );
	return true;
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	unset( $query_arg, $stop );
	$GLOBALS['wp_test_ajax_referers'][] = $action;
	return 1;
}

function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ): void {
	unset( $flags );
	$GLOBALS['wp_test_json_sent'][] = [ 'type' => 'success', 'data' => $data, 'status' => $status_code ];
}

function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ): void {
	unset( $flags );
	$GLOBALS['wp_test_json_sent'][] = [ 'type' => 'error', 'data' => $data, 'status' => $status_code ];
}

function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	$GLOBALS['wp_test_inline_scripts'][] = compact( 'handle', 'data', 'position' );
	return true;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ): string {
	unset( $action, $name, $referer, $display );
	return '';
}

function wp_get_referer(): string {
	return 'https://example.com/wp-admin/network/settings.php?page=translatewp-network-settings';
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ): void {
	unset( $type, $name, $wrap, $other_attributes );
	echo (string) $text;
}

function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ): string {
	return (string) $url;
}

function esc_url_raw( $url ): string {
	return (string) $url;
}

function is_singular( $post_types = '' ): bool {
	unset( $post_types );
	return ! empty( $GLOBALS['wp_test_is_singular'] );
}

function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
	$GLOBALS['wp_test_registered_post_meta'][ $post_type ][ $meta_key ] = $args;
	return true;
}

function get_queried_object_id(): int {
	return (int) ( $GLOBALS['wp_test_queried_object_id'] ?? 0 );
}

function esc_html__( $text, $domain = 'default' ): string {
	unset( $domain );
	return esc_html( $text );
}

function esc_html_e( $text, $domain = 'default' ): void {
	echo esc_html__( $text, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function esc_attr__( $text, $domain = 'default' ): string {
	unset( $domain );
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = 'default' ): void {
	echo esc_attr__( $text, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function _n( $single, $plural, $number, $domain = 'default' ): string {
	unset( $domain );
	return 1 === (int) $number ? $single : $plural;
}

function get_userdata( int $user_id ) {
	unset( $user_id );
	return null;
}

class WP_Post {
	public int $ID = 0;
	public string $post_type = 'post';
	public string $post_status = 'publish';
}

function wp_is_post_revision( $post ): bool {
	unset( $post );
	return ! empty( $GLOBALS['wp_test_is_revision'] );
}

function wp_is_post_autosave( $post ): bool {
	unset( $post );
	return ! empty( $GLOBALS['wp_test_is_autosave'] );
}

function wp_die( $message = '', $title = '', $args = [] ): void {
	unset( $title, $args );
	throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'error' ) );
}

function nocache_headers(): void {
	$headers = apply_filters( 'nocache_headers', [ 'Cache-Control' => 'no-cache, must-revalidate, max-age=0' ] );
	foreach ( (array) $headers as $name => $value ) {
		$GLOBALS['wp_test_sent_headers'][] = is_string( $name ) ? $name . ': ' . $value : (string) $value;
	}
}

function wp_create_nonce( $action = -1 ): string {
	return 'wp_rest' === $action || -1 === $action ? 'test-nonce' : 'nonce-' . $action;
}

function rest_url( string $path = '' ): string {
	return 'https://example.com/wp-json/' . ltrim( $path, '/' );
}

function has_action( $tag, $function_to_check = false ) {
	$callback = $GLOBALS['wp_test_filters'][ (string) $tag ] ?? null;
	if ( false === $function_to_check ) {
		return null !== $callback;
	}
	return $callback === $function_to_check;
}

function is_admin(): bool {
	return ! empty( $GLOBALS['wp_test_is_admin'] );
}

function load_plugin_textdomain( $domain = '', $deprecated = false, $plugin_rel_path = false ): bool {
	unset( $domain, $deprecated, $plugin_rel_path );
	return true;
}

function get_post( $post_id ) {
	$post_id = (int) $post_id;
	foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
		if ( (int) $post['ID'] === $post_id ) {
			return (object) [
				'ID'           => $post_id,
				'post_title'   => (string) ( $post['post_title'] ?? '' ),
				'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
				'post_content' => (string) ( $post['post_content'] ?? '' ),
				'post_status'  => (string) ( $post['post_status'] ?? 'publish' ),
			];
		}
	}
	return null;
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	unset( $remove_breaks );
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	return trim( preg_replace( '/<[^>]+>/', '', (string) $text ) );
}

function get_the_excerpt( $post ) {
	return is_object( $post ) ? (string) ( $post->post_excerpt ?? '' ) : '';
}

function get_term_by( $field, $value, $taxonomy ) {
	unset( $field, $value, $taxonomy );
	return false;
}

function wp_insert_term( $term, $taxonomy, $args = [] ) {
	unset( $term, $taxonomy, $args );
	return [ 'term_id' => 1 ];
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	unset( $object_id, $terms, $taxonomy, $append );
	return [];
}

function spawn_cron() {
	$GLOBALS['wp_test_spawn_cron'] = (int) ( $GLOBALS['wp_test_spawn_cron'] ?? 0 ) + 1;
	return true;
}

function wp_remote_retrieve_header( $response, $header ) {
	if ( is_array( $response ) && isset( $response['headers'][ $header ] ) ) {
		return (string) $response['headers'][ $header ];
	}
	return '';
}

function wp_verify_nonce( $nonce, $action = -1 ): bool {
	return wp_create_nonce( $action ) === (string) $nonce || 'test-nonce' === (string) $nonce;
}

function self_admin_url( string $path = '' ): string {
	return admin_url( $path );
}

function wp_nonce_url( string $url, string $action = '' ): string {
	return esc_html( add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url ) );
}

function download_url( string $url ) {
	$GLOBALS['wp_test_download_urls'][] = $url;

	return '/tmp/translatewp-test-download.zip';
}

function activate_plugin( string $plugin, string $redirect = '', bool $network_wide = false ) {
	$GLOBALS['wp_test_activated_plugins'][] = $plugin;
	$GLOBALS['wp_test_activated_network'][ $plugin ] = $network_wide;

	return null;
}

function is_plugin_active_for_network( string $plugin ): bool {
	return in_array( $plugin, $GLOBALS['wp_test_network_active_plugins'] ?? [], true );
}

function is_ssl(): bool {
	return (bool) ( $GLOBALS['wp_test_is_ssl'] ?? false );
}

function set_url_scheme( string $url, ?string $scheme = null ): string {
	if ( null === $scheme ) {
		return $url;
	}
	return preg_replace( '#^https?://#', $scheme . '://', $url ) ?? $url;
}

function wp_normalize_path( string $path ): string {
	return str_replace( '\\\\', '/', $path );
}

$GLOBALS['wp_test_post_meta'] = $GLOBALS['wp_test_post_meta'] ?? [];
function delete_metadata( $type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	unset( $type, $object_id, $meta_value, $delete_all );
	unset( $GLOBALS['wp_test_post_meta'][ $meta_key ] );
	return true;
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		unset( $timezone );
		return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
	}
}

function set_site_transient( string $key, $value, int $expiration = 0 ): bool {
	unset( $expiration );
	$GLOBALS['wp_test_site_transients'][ $key ] = $value;
	return true;
}

function get_site_transient( string $key ) {
	return $GLOBALS['wp_test_site_transients'][ $key ] ?? false;
}

function delete_site_transient( string $key ): bool {
	unset( $GLOBALS['wp_test_site_transients'][ $key ] );
	return true;
}

if ( ! function_exists( 'get_user_locale' ) ) {
	function get_user_locale(): string {
		return $GLOBALS['wp_test_user_locale'] ?? 'en_US';
	}
}

/**
 * The request asks for a language, as the router records it from the
 * language folder. Accepts a locale or a slug; '' is a plain request.
 */
function seo_test_request_language( string $value ): void {
	\TranslateWP\Components\LanguageUrl::set_requested_locale(
		'' === $value ? '' : \TranslateWP\Components\LanguageUrl::locale_of_parameter( $value )
	);
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return ! empty( $GLOBALS['wp_test_doing_ajax'] );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ): bool {
		return is_string( $file ) && file_exists( $file ) ? unlink( $file ) : false;
	}
}

// --- Roles and the current user ---

if ( ! class_exists( 'WP_Roles' ) ) {
	class WP_Roles {
		public function get_names(): array {
			return $GLOBALS['wp_test_role_names'] ?? [
				'administrator' => 'Administrator',
				'editor'        => 'Editor',
				'author'        => 'Author',
				'contributor'   => 'Contributor',
				'subscriber'    => 'Subscriber',
			];
		}
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles(): WP_Roles {
		return new WP_Roles();
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		$user             = new stdClass();
		$user->ID         = (int) ( $GLOBALS['wp_test_current_user_id'] ?? 0 );
		$user->roles      = (array) ( $GLOBALS['wp_test_current_user_roles'] ?? [] );
		$user->user_email = (string) ( $GLOBALS['wp_test_current_user_email'] ?? 'owner@example.com' );
		return $user;
	}
}

if ( ! function_exists( 'wp_dequeue_style' ) ) {
	function wp_dequeue_style( string $handle ): void {
		$GLOBALS['wp_test_dequeued_styles'][] = $handle;
		$GLOBALS['wp_test_enqueued_styles']   = array_values( array_diff( $GLOBALS['wp_test_enqueued_styles'] ?? [], [ $handle ] ) );
	}
}

if ( ! function_exists( 'wp_styles' ) ) {
	function wp_styles() {
		if ( ! isset( $GLOBALS['wp_test_styles'] ) ) {
			$GLOBALS['wp_test_styles'] = (object) [ 'registered' => [] ];
		}
		return $GLOBALS['wp_test_styles'];
	}
}
