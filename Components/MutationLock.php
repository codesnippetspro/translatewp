<?php

namespace TranslateWP\Components;

class MutationLock {

	private const TIMEOUT_SECONDS = 1;
	public const FAILURE_TRANSIENT = 'translatewp_database_lock_failure';

	public static function run( string $purpose, callable $mutation, int $timeout_seconds = self::TIMEOUT_SECONDS ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$name = 'twp_' . md5( ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' ) . '|' . $wpdb->prefix . '|' . $purpose );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout_seconds ) ) );
		if ( '1' !== (string) $acquired ) {
			if ( '0' !== (string) $acquired ) {
				set_transient( self::FAILURE_TRANSIENT, true, 300 );
			}
			return false;
		}
		if ( 'index_drain' === $purpose ) {
			delete_transient( self::FAILURE_TRANSIENT );
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
