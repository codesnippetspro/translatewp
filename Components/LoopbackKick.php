<?php

namespace TranslateWP\Components;

/**
 * After work is queued, the site asks itself to run the next drain
 * batch right away with a non-blocking request to its own REST route, so a
 * site whose scheduled tasks never fire still starts within seconds. The
 * request is signed with the site's credential key and is fresh for a short
 * window; the route runs one bounded batch under a lock.
 */
class LoopbackKick {

	public const ROUTE            = 'translatewp/v1/indexing/drain-now';
	public const FRESHNESS_SECONDS = 120;
	public const LOCK_TRANSIENT   = 'translatewp_drain_now_lock';
	public const LOCK_SECONDS     = 30;

	public static function fire(): bool {
		$token = (string) Options::get( 'site.token', '' );
		if ( '' === $token || ! function_exists( 'rest_url' ) ) {
			return false;
		}

		$timestamp = (string) time();
		$nonce = bin2hex( random_bytes( 16 ) );
		$body      = [
			'ts'  => $timestamp,
			'sig' => self::signature( $token, $timestamp, $nonce ),
			'nonce' => $nonce,
		];
		$url       = rest_url( self::ROUTE );

		do_action( 'translatewp_loopback_kick', $url );

		$response = wp_remote_post(
			$url,
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			set_transient( \TranslateWP\Functionality\Indexer::LOOPBACK_ERROR_TRANSIENT, self::failure_reason( $response ), self::FRESHNESS_SECONDS );
			ActionLog::record( 'The site could not call itself to run the queue (loopback): ' . $response->get_error_code(), 'warning' );
		}

		return ! is_wp_error( $response );
	}

	public static function failure_reason( \WP_Error $error ): string {
		return false !== strpos( $error->get_error_message(), 'cURL error 60' ) ? 'page_tls_failed' : 'page_fetch_failed';
	}

	/** True when the timestamp is fresh and the signature is the site's own. */
	public static function verify( string $timestamp, string $signature, string $nonce = '' ): bool {
		$token = (string) Options::get( 'site.token', '' );
		if ( '' === $token || ! preg_match( '/^\d{9,12}$/', $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::FRESHNESS_SECONDS ) {
			return false;
		}

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $nonce ) || ! hash_equals( self::signature( $token, $timestamp, $nonce ), $signature ) ) {
			return false;
		}
		$key = 'translatewp_drain_nonce_' . $nonce;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		return set_transient( $key, true, self::FRESHNESS_SECONDS );
	}

	/** Run the callback once; a second kick inside the lock window does nothing. */
	public static function run_locked( callable $work ): bool {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS );
		try {
			$work();
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}

		return true;
	}

	private static function signature( string $token, string $timestamp, string $nonce ): string {
		$key = hash( 'sha256', $token );

		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', "drain\n" . $timestamp . "\n" . $nonce, $key, true ) ), '+/', '-_' ), '=' );
	}
}
