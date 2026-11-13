<?php

namespace TranslateWP\Functionality\Endpoints {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ) {
		if ( ! empty( $GLOBALS['scan_test_schedule_false'] ) ) {
			return false;
		}
		return \wp_schedule_single_event( $timestamp, $hook, $args );
	}
	function wp_remote_get( string $url, array $args = [] ) {
		$GLOBALS['scan_test_self_checks'][] = [ 'url' => $url, 'args' => $args ];
		return \wp_remote_get( $url, $args );
	}
	function get_posts( array $args = [] ) {
		$GLOBALS['scan_test_queries'][] = $args;
		if ( 'lexicon' === ( $args['post_type'] ?? '' ) && 'ids' === ( $args['fields'] ?? '' ) ) {
			return array_column( array_filter( $GLOBALS['wp_test_inserted_posts'], static fn( $post ) => 'lexicon' === $post['post_type'] ), 'ID' );
		}
		$override = $GLOBALS['scan_test_post_query'] ?? null;
		$posts = is_callable( $override ) ? $override( $args ) : \get_posts( $args );
		$statuses = isset( $args['post_status'] ) ? (array) $args['post_status'] : [];
		return array_values( array_filter( $posts, static function ( $post ) use ( $statuses ) {
			return ! is_object( $post ) || ! $statuses || in_array( 'any', $statuses, true ) || in_array( $post->post_status, $statuses, true );
		} ) );
	}

	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$GLOBALS['scan_test_sample_progress'][] = \TranslateWP\Components\Options::get( 'translations.context.scan_progress', [] );
		return \wp_strip_all_tags( $text, $remove_breaks );
	}

	function get_post_types( array $args = [], string $output = 'names' ) {
		$GLOBALS['scan_test_post_type_queries'][] = [ $args, $output ];
		return \get_post_types( $args, $output );
	}
	function wp_insert_post( array $post, $wp_error = false ) {
		return $GLOBALS['scan_test_insert_result'] ?? \wp_insert_post( $post, $wp_error );
	}
	function get_term_by( $field, $value, $taxonomy ) {
		return $GLOBALS['scan_test_term_result'] ?? \get_term_by( $field, $value, $taxonomy );
	}
	function wp_insert_term( $term, $taxonomy, $args = [] ) {
		$GLOBALS['scan_test_term_inserts'][] = [ $term, $taxonomy, $args ];
		return $GLOBALS['scan_test_term_insert_result'] ?? \wp_insert_term( $term, $taxonomy, $args );
	}
	function wp_set_object_terms( $id, $terms, $taxonomy, $append = false ) {
		$GLOBALS['scan_test_term_assignments'][] = [ $id, $terms, $taxonomy, $append ];
		return \wp_set_object_terms( $id, $terms, $taxonomy, $append );
	}
	function spawn_cron() {
		$GLOBALS['scan_test_spawn_locks'][] = get_transient( 'translatewp_context_scan_lock' );
		return \spawn_cron();
	}

}

namespace TranslateWP\Components {
	function wp_remote_post( string $url, array $args = [] ) {
		$GLOBALS['scan_test_http_requests'][] = [ 'url' => $url, 'args' => $args ];
		$before_response = $GLOBALS['scan_test_before_response'] ?? null;
		if ( is_callable( $before_response ) ) {
			$before_response();
		}
		return \wp_remote_post( $url, $args );
	}
}

namespace {
	function scan_test_reset_boundaries(): void {
		foreach ( [ 'scan_test_post_type_queries', 'scan_test_term_inserts', 'scan_test_term_assignments', 'scan_test_spawn_locks', 'scan_test_sample_progress' ] as $key ) {
			$GLOBALS[$key] = [];
		}
		foreach ( [ 'scan_test_insert_result', 'scan_test_term_result', 'scan_test_term_insert_result', 'scan_test_post_query' ] as $key ) {
			$GLOBALS[$key] = null;
		}
	}

	require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	require dirname( __DIR__ ) . '/wp-test-stubs.php';
	if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
		define( 'TRANSLATEWP_VERSION', '1.0.3' );
	}
}
