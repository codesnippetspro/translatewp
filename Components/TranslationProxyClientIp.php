<?php

namespace TranslateWP\Components;

class TranslationProxyClientIp {

	public static function resolve(): string {
		$trusted_hops = (int) apply_filters( 'translatewp_trusted_proxy_hops', 0 );
		$remote_addr    = self::server_ip( 'REMOTE_ADDR' );

		if ( $trusted_hops > 0 ) {
			if ( ! self::is_trusted_proxy( $remote_addr ) ) {
				return '';
			}

			$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
				? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
				: '';

			if ( '' === $forwarded ) {
				return '';
			}

			$parts = array_values(
				array_filter(
					array_map( 'trim', explode( ',', $forwarded ) ),
					static function ( string $part ): bool {
						return '' !== $part;
					}
				)
			);

			$client_index = count( $parts ) - $trusted_hops - 1;
			if ( $client_index < 0 ) {
				return '';
			}

			$candidate = (string) ( $parts[ $client_index ] ?? '' );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}

			return '';
		}

		if ( filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return $remote_addr;
		}

		return '';
	}

	public static function is_trusted_proxy( string $remote_addr ): bool {
		if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$trusted_proxies = apply_filters( 'translatewp_trusted_proxies', [] );
		if ( ! is_array( $trusted_proxies ) || ! $trusted_proxies ) {
			return false;
		}

		foreach ( $trusted_proxies as $trusted_proxy ) {
			if ( ! is_string( $trusted_proxy ) || '' === $trusted_proxy ) {
				continue;
			}

			if ( self::ip_matches( $remote_addr, $trusted_proxy ) ) {
				return true;
			}
		}

		return false;
	}

	private static function server_ip( string $key ): string {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
	}

	private static function ip_matches( string $ip, string $trusted ): bool {
		if ( false === strpos( $trusted, '/' ) ) {
			return $ip === $trusted;
		}

		[ $subnet, $mask_bits ] = array_pad( explode( '/', $trusted, 2 ), 2, null );
		if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) || ! is_numeric( $mask_bits ) ) {
			return false;
		}

		$mask_bits = (int) $mask_bits;
		if ( $mask_bits < 0 || $mask_bits > 128 ) {
			return false;
		}

		if ( false !== strpos( $ip, ':' ) || false !== strpos( $subnet, ':' ) ) {
			return self::ipv6_matches( $ip, $subnet, $mask_bits );
		}

		if ( $mask_bits > 32 ) {
			return false;
		}

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = -1 << ( 32 - $mask_bits );
		$mask = $mask & 0xFFFFFFFF;

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	private static function ipv6_matches( string $ip, string $subnet, int $mask_bits ): bool {
		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		$bytes = (int) floor( $mask_bits / 8 );
		$bits  = $mask_bits % 8;

		if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( ! $bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $bits ) ) & 0xFF;

		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}
}
