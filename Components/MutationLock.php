<?php

namespace TranslateWP\Components;

class MutationLock {

	private const TIMEOUT_SECONDS = 1;
	private const LEASE_SECONDS = 60;
	private const RETRY_MICROSECONDS = 20000;
	private static array $held = [];
	public const FAILURE_TRANSIENT = 'translatewp_database_lock_failure';

	public static function run( string $purpose, callable $mutation, int $timeout_seconds = self::TIMEOUT_SECONDS ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$scope = $wpdb->prefix . '|' . $purpose;
		if ( isset( self::$held[ $scope ] ) ) {
			$mutation();
			return true;
		}
		$name = 'twp_' . md5( ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' ) . '|' . $wpdb->prefix . '|' . $purpose );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout_seconds ) ) );
		if ( '1' !== (string) $acquired ) {
			return '0' === (string) $acquired ? false : self::option_lock( $purpose, $mutation, $timeout_seconds );
		}
		if ( 'index_drain' === $purpose ) {
			delete_transient( self::FAILURE_TRANSIENT );
		}
		try {
			wp_cache_delete( 'translatewp_cache', 'options' );
			self::execute( $purpose, $mutation );
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	private static function execute( string $purpose, callable $mutation ): void {
		global $wpdb;
		$scope = $wpdb->prefix . '|' . $purpose;
		self::$held[ $scope ] = true;
		try {
			$mutation();
		} finally {
			unset( self::$held[ $scope ] );
		}
	}

	private static function option_lock( string $purpose, callable $mutation, int $timeout_seconds ): bool {
		global $wpdb;
		$name = 'translatewp_lock_' . md5( $purpose );
		$owner = time() + self::LEASE_SECONDS . ':' . bin2hex( random_bytes( 16 ) );
		$deadline = microtime( true ) + max( 0, $timeout_seconds );
		do {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $owner, 'no' ) );
			if ( 1 === $inserted ) {
				try {
					delete_transient( self::FAILURE_TRANSIENT );
					self::execute( $purpose, $mutation );
					return true;
				} finally {
					self::delete_lease( $name, $owner );
				}
			}
			$current = get_option( $name, '' );
			if ( '' === $current ) {
				set_transient( self::FAILURE_TRANSIENT, true, 300 );
				return false;
			}
			if ( is_string( $current ) && (int) $current < time() ) {
				self::delete_lease( $name, $current );
			} elseif ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( self::RETRY_MICROSECONDS );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	private static function delete_lease( string $name, string $owner ): void {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $owner ) );
		wp_cache_delete( $name, 'options' );
		if ( false === $deleted ) {
			set_transient( self::FAILURE_TRANSIENT, true, 300 );
		}
	}
}
