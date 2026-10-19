<?php

namespace TranslateWP\Components;

class TranslationSubscription {

	public static function mint( string $language, array $hashes ): ?array {
		if ( ! $hashes ) {
			return null;
		}

		$payload = SiteRequests::post(
			'/v1/translation/cache/subscriptions',
			[
				'language' => $language,
				'hashes'   => $hashes,
			],
			5
		);
		if ( is_wp_error( $payload ) || ! is_array( $payload ) ) {
			return null;
		}

		return self::sanitize( $payload );
	}

	private static function sanitize( array $payload ): ?array {
		$url        = esc_url_raw( trim( (string) ( $payload['url'] ?? '' ) ) );
		$expires_at = sanitize_text_field( (string) ( $payload['expiresAt'] ?? '' ) );
		$parsed     = wp_parse_url( $url );
		$base       = wp_parse_url( RemoteApi::base_url() );
		if ( ! is_array( $parsed ) || ! is_array( $base ) ) {
			return null;
		}

		$url_port  = isset( $parsed['port'] ) ? (int) $parsed['port'] : 443;
		$base_port = isset( $base['port'] ) ? (int) $base['port'] : 443;
		if (
			'https' !== strtolower( (string) ( $parsed['scheme'] ?? '' ) )
			|| 'https' !== strtolower( (string) ( $base['scheme'] ?? '' ) )
			|| strtolower( (string) ( $parsed['host'] ?? '' ) ) !== strtolower( (string) ( $base['host'] ?? '' ) )
			|| $url_port !== $base_port
			|| '/v1/translation/cache/events' !== (string) ( $parsed['path'] ?? '' )
			|| isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['fragment'] )
		) {
			return null;
		}

		parse_str( (string) ( $parsed['query'] ?? '' ), $query );
		$token = isset( $query['token'] ) && is_string( $query['token'] )
			? sanitize_text_field( $query['token'] )
			: '';
		if ( 1 !== count( $query ) || '' === $token || strlen( $token ) > 16384 ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $expires_at ) ) {
			return null;
		}
		$expiry = strtotime( $expires_at );
		if ( false === $expiry || $expiry <= time() || $expiry > time() + ( 10 * MINUTE_IN_SECONDS ) ) {
			return null;
		}

		return [
			'url'       => RemoteApi::endpoint( '/v1/translation/cache/events' ) . '?token=' . rawurlencode( $token ),
			'expiresAt' => $expires_at,
		];
	}
}
