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
	public const HEALTH_TRANSIENT = 'translatewp_loopback_healthy';
	public const FAILURE_TRANSIENT = 'translatewp_loopback_failure';
	public const FIRST_DISPATCH_OPTION = 'translatewp_loopback_first_dispatch';
	private const PROBE_TRANSIENT = 'translatewp_loopback_probe';
	private const HEALTH_SECONDS = 300;
	public const LOGGED_FAILURE_TRANSIENT = 'translatewp_loopback_logged_failure';
	private const UNKNOWN_SECONDS = 60;
	private const PROBE_SECONDS = 0.25;

	public static function fire(): bool {
		if ( BackgroundSwitch::off() ) {
			return false;
		}
		$token = (string) Options::get( 'site.token', '' );
		if ( '' === $token || ! function_exists( 'rest_url' ) ) {
			return false;
		}

		add_option( self::FIRST_DISPATCH_OPTION, time(), '', false );
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
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			self::record_failure( $response );
			set_transient( \TranslateWP\Functionality\Indexer::LOOPBACK_ERROR_TRANSIENT, self::failure_reason( $response ), self::FRESHNESS_SECONDS );
			$failure = self::failure_reason( $response ) . ':' . $response->get_error_code();
			if ( get_transient( self::LOGGED_FAILURE_TRANSIENT ) !== $failure ) {
				set_transient( self::LOGGED_FAILURE_TRANSIENT, $failure, self::HEALTH_SECONDS );
				ActionLog::record( 'The site could not call itself to run the queue (loopback): ' . $response->get_error_code(), 'warning' );
			}
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

	public static function run_locked( callable $work ): bool {
		return QueueExecution::run( $work );
	}

	public static function acknowledge(): void {
		set_transient( self::HEALTH_TRANSIENT, time(), self::HEALTH_SECONDS );
		delete_transient( self::FAILURE_TRANSIENT );
		delete_transient( self::LOGGED_FAILURE_TRANSIENT );
	}

	public static function healthy(): bool {
		return false !== get_transient( self::HEALTH_TRANSIENT ) && false === get_transient( self::FAILURE_TRANSIENT );
	}

	public static function unhealthy(): bool {
		$first = (int) get_option( self::FIRST_DISPATCH_OPTION, 0 );
		return ! self::healthy() && ( false !== get_transient( self::FAILURE_TRANSIENT ) || ( $first > 0 && $first <= time() - self::UNKNOWN_SECONDS ) );
	}

	public static function probe(): void {
		if ( BackgroundSwitch::off() || self::healthy() || get_transient( self::PROBE_TRANSIENT ) || ! function_exists( 'rest_url' ) ) {
			return;
		}
		$token = (string) Options::get( 'site.token', '' );
		if ( '' === $token ) {
			return;
		}
		set_transient( self::PROBE_TRANSIENT, true, self::UNKNOWN_SECONDS );
		$timestamp = (string) time();
		$nonce = bin2hex( random_bytes( 16 ) );
		$response = wp_remote_post(
            rest_url( self::ROUTE ),
            [
				'timeout' => self::PROBE_SECONDS,
				'blocking' => true,
				'redirection' => 0,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body' => wp_json_encode(
                    [
						'ts' => $timestamp,
						'nonce' => $nonce,
						'sig' => self::signature( $token, $timestamp, $nonce ),
						'probe' => true,
					]
                ),
			]
        );
		if ( is_wp_error( $response ) ) {
			self::record_failure( $response );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code && true === ( $body['healthy'] ?? false ) ) {
				self::acknowledge();
			} else {
				set_transient( self::FAILURE_TRANSIENT, 401 === $code ? 'http_auth' : 'request_blocked', self::HEALTH_SECONDS );
			}
		}
	}

	private static function record_failure( \WP_Error $error ): void {
		set_transient( self::FAILURE_TRANSIENT, 'page_tls_failed' === self::failure_reason( $error ) ? 'certificate' : 'request_blocked', self::HEALTH_SECONDS );
	}

	private static function signature( string $token, string $timestamp, string $nonce ): string {
		$key = hash( 'sha256', $token );

		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', "drain\n" . $timestamp . "\n" . $nonce, $key, true ) ), '+/', '-_' ), '=' );
	}
}
