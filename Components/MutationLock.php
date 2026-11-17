<?php

namespace TranslateWP\Components;

class MutationLock {

	private const TIMEOUT_SECONDS = 1;

	public static function run( string $purpose, callable $mutation, int $timeout_seconds = self::TIMEOUT_SECONDS ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$name = 'twp_' . md5( ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' ) . '|' . $wpdb->prefix . '|' . $purpose );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout_seconds ) ) ) ) {
			return false;
		}
		try {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'translatewp_cache', 'options' );
			$mutation();
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}
