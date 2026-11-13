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
		$posts = \get_posts( $args );
		$statuses = isset( $args['post_status'] ) ? (array) $args['post_status'] : [];
		return array_values( array_filter( $posts, static function ( $post ) use ( $statuses ) {
			return ! is_object( $post ) || ! $statuses || in_array( 'any', $statuses, true ) || in_array( $post->post_status, $statuses, true );
		} ) );
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
	require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	require dirname( __DIR__ ) . '/wp-test-stubs.php';
	if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
		define( 'TRANSLATEWP_VERSION', '1.0.3' );
	}
}
