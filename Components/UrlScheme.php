<?php

namespace TranslateWP\Components;

/**
 * When a site is served over https while its WordPress address is
 * still http (a moved site, a tunnel, a proxy), the browser blocks every
 * http resource and request on the https page as mixed content, which
 * silently disables the admin app and the visitor runtime. The plugin's own
 * script and REST addresses therefore follow the scheme of the current
 * request, never the stored address scheme.
 */
class UrlScheme {

	public static function register(): void {
		add_filter( 'rest_url', [ self::class, 'match_request_scheme' ], 20 );
		add_filter( 'plugins_url', [ self::class, 'match_plugin_asset_scheme' ], 20, 3 );
	}

	/**
	 * Behind a tunnel or a proxy that terminates TLS, WordPress sees a plain
	 * http request; the forwarded-proto headers tell the real scheme.
	 */
	public static function request_is_https(): bool {
		if ( is_ssl() ) {
			return true;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) : '';
		if ( 'https' === trim( explode( ',', $forwarded )[0] ) ) {
			return true;
		}

		$forwarded_ssl = isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) ) : '';
		if ( 'on' === $forwarded_ssl ) {
			return true;
		}

		$visitor = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_VISITOR'] ) ) : '';

		return false !== strpos( $visitor, '"https"' );
	}

	public static function match_request_scheme( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		return set_url_scheme( $url, self::request_is_https() ? 'https' : 'http' );
	}

	public static function match_plugin_asset_scheme( $url, $path = '', $plugin = '' ) {
		unset( $path );
		if ( ! is_string( $url ) || '' === $url || ! is_string( $plugin ) ) {
			return $url;
		}

		$own      = defined( 'TRANSLATEWP_PLUGIN_DIR' ) ? (string) TRANSLATEWP_PLUGIN_DIR : dirname( __DIR__ );
		$own_path = wp_normalize_path( $own );
		$own_slug = basename( $own_path );
		// Match on the plugin file when WordPress passes one, otherwise on the
		// plugin directory inside the address itself (asset helpers that build
		// the address without naming the plugin file).
		$by_file = '' !== $plugin && false !== strpos( wp_normalize_path( $plugin ), $own_path );
		$by_url  = '' !== $own_slug && false !== strpos( $url, '/plugins/' . $own_slug . '/' );
		if ( ! $by_file && ! $by_url ) {
			return $url;
		}

		return set_url_scheme( $url, self::request_is_https() ? 'https' : 'http' );
	}
}
