<?php

namespace TranslateWP\Components;

/**
 * The address of a page in a translation language: its language folder put
 * in front of the page path, /de/about/ for the page at /about/. The default
 * language keeps the plain address. On a site that lives in a folder the
 * language folder follows the site folder.
 */
class LanguageUrl {

	private static string $requested_locale = '';

	/** The translation language the current request asked for, '' for the default language. */
	public static function requested_locale(): string {
		return self::$requested_locale;
	}

	public static function set_requested_locale( string $locale ): void {
		self::$requested_locale = '' !== self::slug_of( $locale ) ? $locale : '';
	}

	/**
	 * Splits a request path into the translation language its folder names
	 * and the path of the page itself.
	 *
	 * @return array{locale: string, path: string}
	 */
	public static function split_path( string $path ): array {
		$path      = '/' . ltrim( $path, '/' );
		$home_path = self::home_path();
		$unchanged = [
			'locale' => '',
			'path'   => $path,
		];

		if ( '' !== $home_path && 0 !== strpos( $path . '/', $home_path . '/' ) ) {
			return $unchanged;
		}

		$rest     = (string) substr( $path, strlen( $home_path ) );
		$segments = explode( '/', ltrim( $rest, '/' ), 2 );
		$locale   = self::locale_of( (string) $segments[0] );
		if ( '' === $locale ) {
			return $unchanged;
		}

		return [
			'locale' => $locale,
			'path'   => $home_path . '/' . ( $segments[1] ?? '' ),
		];
	}

	/** The address of the same page in the given language. */
	public static function localize( string $url, string $locale ): string {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] )
			|| strtolower( (string) $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return $url;
		}

		$home_path = self::home_path();
		$path      = self::split_path( (string) ( $parts['path'] ?? '/' ) )['path'];
		if ( '' !== $home_path && 0 !== strpos( $path . '/', $home_path . '/' ) ) {
			return $url;
		}

		$slug = self::slug_of( $locale );
		$rest = '/' . ltrim( (string) substr( $path, strlen( $home_path ) ), '/' );
		$path = $home_path . ( '' !== $slug ? '/' . $slug : '' ) . $rest;

		$query = [];
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		unset( $query[ TranslationUrl::LANG_QUERY_PARAM ] );

		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host']
			. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. $path
			. ( $query ? '?' . http_build_query( $query ) : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/** The default-language address a translated address stands for. */
	public static function source( string $url ): string {
		return self::localize( $url, self::default_locale() );
	}

	public static function default_locale(): string {
		foreach ( self::active_languages() as $language ) {
			if ( ! empty( $language['default'] ) ) {
				return (string) $language['locale'];
			}
		}

		return '';
	}

	/** The folder of a translation language, '' for the default or an inactive language. */
	public static function slug_of( string $locale ): string {
		foreach ( self::active_languages() as $language ) {
			if ( (string) $language['locale'] === $locale ) {
				return empty( $language['default'] ) ? self::slug( $language ) : '';
			}
		}

		return '';
	}

	/**
	 * The language an old ?lang= value names: the locale or the slug of an
	 * active language, the default language included. '' for anything else.
	 */
	public static function locale_of_parameter( string $value ): string {
		$value = strtolower( $value );
		foreach ( self::active_languages() as $language ) {
			if ( strtolower( (string) $language['locale'] ) === $value || self::slug( $language ) === $value ) {
				return (string) $language['locale'];
			}
		}

		return '';
	}

	private static function locale_of( string $segment ): string {
		$segment = strtolower( $segment );
		if ( '' === $segment ) {
			return '';
		}

		foreach ( self::active_languages() as $language ) {
			if ( empty( $language['default'] ) && self::slug( $language ) === $segment ) {
				return (string) $language['locale'];
			}
		}

		return '';
	}

	private static function slug( array $language ): string {
		$slug = (string) ( $language['slug'] ?? '' );

		return strtolower( '' !== $slug ? $slug : LanguageSlugs::from_locale( (string) $language['locale'] ) );
	}

	private static function active_languages(): array {
		$languages = Options::get( 'languages.list', [] );

		return array_values(
			array_filter(
				is_array( $languages ) ? $languages : [],
				static function ( $language ): bool {
					return is_array( $language ) && ! empty( $language['active'] ) && ! empty( $language['locale'] );
				}
			)
		);
	}

	private static function home_path(): string {
		return untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	}
}
