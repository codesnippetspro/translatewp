<?php

namespace TranslateWP\Components;

/**
 * Canonical URL keying shared by the translation store, the miss-report
 * endpoint, and the page-map read so a page is keyed identically wherever it is
 * written or looked up: the scheme forced to https, the host lower-cased, the
 * trailing slash removed (bar the root), the language folder and the old `lang`
 * parameter dropped,
 * any other query kept, and the fragment removed.
 */
class TranslationUrl {

	public const LANG_QUERY_PARAM = 'lang';

	private const BLOCKED_FIRST_PATH_SEGMENTS = [ 'wp-admin', 'wp-login.php' ];

	private const BLOCKED_QUERY_PARAMS = [
		'customize_changeset_uuid',
		'customize_theme',
		'customize_messenger_channel',
		'preview',
		'preview_id',
		'preview_nonce',
		'elementor-preview',
		'et_fb',
		'vc_editable',
		'fl_builder',
		'wp_customize',
	];

	/**
	 * A queued page keeps its path when the site's address changes;
	 * a stored URL on another host is rebased onto the current home address so
	 * indexing never fetches a host that no longer serves the site. A canonical
	 * URL on the home host takes the home scheme too, since canonicalizing
	 * forces https and a site served over http cannot be fetched that way.
	 */
	public static function rebase_to_home( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! is_array( $home ) || empty( $home['host'] ) ) {
			return $url;
		}
		$scheme = (string) ( $home['scheme'] ?? 'https' );
		if ( strtolower( (string) $parts['host'] ) === strtolower( (string) $home['host'] )
			&& strtolower( (string) ( $parts['scheme'] ?? $scheme ) ) === strtolower( $scheme ) ) {
			return $url;
		}

		$port   = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		$path   = (string) ( $parts['path'] ?? '/' );
		$query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return $scheme . '://' . strtolower( (string) $home['host'] ) . $port . $path . $query;
	}

	public static function canonicalize( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		$path = untrailingslashit( LanguageUrl::split_path( (string) ( $parts['path'] ?? '' ) )['path'] );
		if ( '' === $path ) {
			$path = '/';
		}

		$query = self::canonical_query( (string) ( $parts['query'] ?? '' ) );

		return 'https://' . $host . $port . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * A browser also fetches scripts, data, media and robots files from the
	 * site; a path ending in a file name with an extension (up to 16 letters
	 * and digits, at least one letter) is never a page,
	 * except an .html, .htm or .php page. A trailing slash is a directory.
	 *
	 * @param string $path The URL path.
	 */
	public static function is_file_path( string $path ): bool {
		if ( '' === $path || '/' === substr( $path, -1 ) ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return 1 === preg_match( '/^(?=.*[a-z])[a-z0-9]{1,16}$/', $extension ) && ! in_array( $extension, [ 'html', 'htm', 'php' ], true );
	}

	/**
	 * False for a WordPress preview/builder or admin URL: a Customizer preview
	 * (`customize_changeset_uuid`, `customize_theme`, `customize_messenger_channel`),
	 * a post/page-builder preview (`preview`, `preview_id`, `preview_nonce`,
	 * `elementor-preview`, `et_fb`, `vc_editable`, `fl_builder`), the
	 * `wp_customize` flag, a path under wp-admin, or the wp-login.php script.
	 * Shared by the indexer and the miss-report path so neither ever records
	 * such a URL as a page.
	 */
	public static function is_indexable_url( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$path     = strtolower( (string) ( $parts['path'] ?? '' ) );
		if ( self::is_file_path( $path ) ) {
			return false;
		}

		$segments = array_values( array_filter( explode( '/', $path ), static fn( string $segment ): bool => '' !== $segment ) );
		if ( in_array( $segments[0] ?? '', self::BLOCKED_FIRST_PATH_SEGMENTS, true ) ) {
			return false;
		}

		$query = (string) ( $parts['query'] ?? '' );
		if ( '' === $query ) {
			return true;
		}

		$params = [];
		parse_str( $query, $params );

		foreach ( self::BLOCKED_QUERY_PARAMS as $blocked_param ) {
			if ( array_key_exists( $blocked_param, $params ) ) {
				return false;
			}
		}

		return true;
	}

	private static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$params = [];
		parse_str( $query, $params );
		unset( $params[ self::LANG_QUERY_PARAM ] );

		return $params ? http_build_query( $params ) : '';
	}
}
