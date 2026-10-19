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
		$body      = [
			'ts'  => $timestamp,
			'sig' => self::signature( $token, $timestamp ),
		];
		$url       = rest_url( self::ROUTE );

		do_action( 'translatewp_loopback_kick', $url );

		$response = wp_remote_post(
			$url,
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			ActionLog::record( 'The site could not call itself to run the queue (loopback): ' . $response->get_error_code(), 'warning' );
		}

		return ! is_wp_error( $response );
	}

	/** True when the timestamp is fresh and the signature is the site's own. */
	public static function verify( string $timestamp, string $signature ): bool {
		$token = (string) Options::get( 'site.token', '' );
		if ( '' === $token || ! preg_match( '/^\d{9,12}$/', $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::FRESHNESS_SECONDS ) {
			return false;
		}

		return hash_equals( self::signature( $token, $timestamp ), $signature );
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

	private static function signature( string $token, string $timestamp ): string {
		$key = hash( 'sha256', $token );

		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', "drain\n" . $timestamp, $key, true ) ), '+/', '-_' ), '=' );
	}
}
