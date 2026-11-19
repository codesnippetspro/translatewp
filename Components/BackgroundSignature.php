<?php

namespace TranslateWP\Components;

class BackgroundSignature {

	public const KEY_OPTION = 'translatewp_engine_key';
	public const REFRESH_OPTION = 'translatewp_engine_key_refresh_at';
	private const REFRESH_SECONDS = 300;
	private const REFRESH_TIMEOUT_SECONDS = 3;
	public const NONCES_OPTION = 'translatewp_engine_nonces';
	private const FRESH_SECONDS = 120;
	private const NONCE_SECONDS = 300;
	private const MAX_NONCES = 256;
	public const MAX_BODY_BYTES = 6291456;

	public static function observe( array $response ): void {
		$key = $response['engineKey'] ?? null;
		if ( is_string( $key ) && preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			update_option( self::KEY_OPTION, $key, false );
		}
	}

	public static function refresh_after_failure(): void {
		$refresh = false;
		MutationLock::run(
            'engine_key_refresh',
            static function () use ( &$refresh ): void {
				wp_cache_delete( self::REFRESH_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( (int) get_option( self::REFRESH_OPTION, 0 ) > time() - self::REFRESH_SECONDS ) {
					return;
				}
				update_option( self::REFRESH_OPTION, time(), false );
				$refresh = true;
			},
            0
        );
		if ( $refresh && SiteConnection::is_connected() ) {
			ProEligibility::refresh( self::REFRESH_TIMEOUT_SECONDS );
		}
	}

	public static function verify( \WP_REST_Request $request, ?int $now = null ): bool {
		$now = $now ?? time();
		$raw = $request->get_body();
		$signature = $request->get_header( 'X-TranslateWP-Signature' );
		if ( strlen( $raw ) > self::MAX_BODY_BYTES || ! preg_match( '/^v2=([a-f0-9]{64})$/D', $signature, $match )
			|| ! preg_match( '~^application/json(?:\s*;|$)~i', $request->get_header( 'Content-Type' ) ) ) {
			return false;
		}
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || ! is_int( $body['ts'] ?? null ) || ! is_string( $body['nonce'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{32}$/D', $body['nonce'] ) || abs( $now - $body['ts'] ) > self::FRESH_SECONDS
			|| ( array_key_exists( 'pause', $body ) && ! is_bool( $body['pause'] ) ) ) {
			return false;
		}
		$key = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			return false;
		}
		$signed = $body['ts'] . '.' . $body['nonce'] . '.' . hash( 'sha256', $raw );
		$expected = hash_hmac( 'sha256', $signed, hex2bin( $key ) );
		if ( ! hash_equals( $expected, $match[1] ) ) {
			return false;
		}
		$accepted = false;
		MutationLock::run(
			'engine_nonce',
			static function () use ( $body, $now, &$accepted ): void {
				wp_cache_delete( self::NONCES_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$nonces = (array) get_option( self::NONCES_OPTION, [] );
				$nonces = array_filter( $nonces, static fn ( $expires ): bool => (int) $expires > $now );
				if ( isset( $nonces[ $body['nonce'] ] ) || count( $nonces ) >= self::MAX_NONCES ) {
					return;
				}
				$nonces[ $body['nonce'] ] = $now + self::NONCE_SECONDS;
				$accepted = update_option( self::NONCES_OPTION, $nonces, false );
			},
			0
		);
		return $accepted;
	}
}
