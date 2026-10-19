<?php

declare(strict_types=1);

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ): bool {
		unset( $post_types );
		return ! empty( $GLOBALS['wp_test_is_singular'] );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool {
		return ! empty( $GLOBALS['wp_test_is_front_page'] );
	}
}

if ( ! function_exists( 'is_home' ) ) {
	function is_home(): bool {
		return ! empty( $GLOBALS['wp_test_is_home'] );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $key, $query = false ) {
		$keys = array_map( 'strval', (array) $key );
		$url  = false === $query ? (string) ( $_SERVER['REQUEST_URI'] ?? '' ) : (string) $query;

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

		foreach ( $keys as $remove_key ) {
			unset( $existing[ $remove_key ] );
		}

		return $base . ( $existing ? '?' . http_build_query( $existing ) : '' ) . $fragment;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $tag, $callback = null, $priority = 10 ) {
		unset( $priority );
		$GLOBALS['wp_test_removed_actions'][] = [ (string) $tag, $callback ];
		return true;
	}
}

if ( ! function_exists( 'rel_canonical' ) ) {
	function rel_canonical(): void {
		echo '<link rel="canonical" href="core-canonical" />' . "\n";
	}
}

function seo_test_registered( string $tag, string $method ): bool {
	foreach ( $GLOBALS['wp_test_action_registrations'] ?? [] as $entry ) {
		if ( $tag === $entry['tag'] && is_array( $entry['callback'] ) && $method === ( $entry['callback'][1] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

function seo_test_filter_is( string $tag, string $method ): bool {
	$callback = $GLOBALS['wp_test_filters'][ $tag ] ?? null;

	return is_array( $callback ) && $method === ( $callback[1] ?? '' );
}

function seo_test_capture( callable $callback ): string {
	ob_start();
	$callback();
	return (string) ob_get_clean();
}
