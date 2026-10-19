<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\Audience;
use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\TranslationUrl;

/**
 * Serves a translated page at its language folder. A request for /de/about/
 * is the page /about/ in German: the folder is taken off the request before
 * WordPress resolves it, and the language is recorded for the rest of the
 * request. The old ?lang= form redirects permanently to the folder form.
 */
class LanguageRouting {

	private string $requested_uri = '';

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_filter( 'do_parse_request', [ $this, 'route' ], 1 );
		add_action( 'template_redirect', [ $this, 'redirect_lang_parameter' ], 0 );
		add_filter( 'redirect_canonical', [ $this, 'keep_language_on_canonical_redirect' ] );
	}

	/**
	 * WordPress reads the page to resolve from REQUEST_URI, so the language
	 * folder is taken off there, before it parses the request.
	 */
	public function route( $parse ) {
		$uri                 = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$this->requested_uri = $uri;
		if ( '' === $uri || is_admin() ) {
			return $parse;
		}

		$question = strpos( $uri, '?' );
		$path     = false === $question ? $uri : substr( $uri, 0, $question );
		$query    = false === $question ? '' : substr( $uri, $question );
		$split    = LanguageUrl::split_path( $path );
		if ( '' === $split['locale'] ) {
			return $parse;
		}

		// A visitor the role restriction leaves out gets the page itself, as
		// written: the address still resolves, but nothing treats it as translated.
		if ( Audience::allows_current_user() ) {
			LanguageUrl::set_requested_locale( $split['locale'] );
		}
		$_SERVER['REQUEST_URI'] = $split['path'] . $query;

		return $parse;
	}

	/** The address the visitor asked for, language folder included. */
	public function requested_url(): string {
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}

		return ( $home['scheme'] ?? 'https' ) . '://' . $home['host']
			. ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' )
			. $this->requested_uri;
	}

	/**
	 * Where a request carrying ?lang= belongs, '' when the value is none of
	 * the site's languages. Another plugin may own a lang parameter of its
	 * own, so only a value naming one of this site's languages is acted on.
	 */
	public function lang_parameter_redirect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only address parameter.
		$requested = isset( $_GET[ TranslationUrl::LANG_QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ TranslationUrl::LANG_QUERY_PARAM ] ) ) : '';
		if ( '' === $requested ) {
			return '';
		}

		$locale = LanguageUrl::locale_of_parameter( $requested );
		if ( '' === $locale ) {
			return '';
		}

		return LanguageUrl::localize( $this->requested_url(), $locale );
	}

	/**
	 * A translated page has one address, so a folder written in another case
	 * (/DE/about/) redirects to it. Only the folder is compared and replaced;
	 * the rest of the address goes back exactly as it was written.
	 */
	public function folder_case_redirect(): string {
		$slug = LanguageUrl::slug_of( LanguageUrl::requested_locale() );
		if ( '' === $slug ) {
			return '';
		}

		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$written   = (string) substr( $this->requested_uri, strlen( $home_path ) + 1, strlen( $slug ) );
		if ( $written === $slug || strtolower( $written ) !== $slug ) {
			return '';
		}

		$home = wp_parse_url( home_url() );

		return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' )
			. ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' )
			. $home_path . '/' . $slug . substr( $this->requested_uri, strlen( $home_path ) + 1 + strlen( $slug ) );
	}

	public function redirect_lang_parameter(): void {
		$target = $this->lang_parameter_redirect();
		if ( '' === $target ) {
			$target = $this->folder_case_redirect();
		}
		if ( '' === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * WordPress compares the page it resolved with the address it believes was
	 * asked for, which no longer carries the folder; its redirect is put back
	 * into the requested language.
	 */
	public function keep_language_on_canonical_redirect( $redirect_url ) {
		$locale = LanguageUrl::requested_locale();
		if ( ! is_string( $redirect_url ) || '' === $redirect_url || '' === $locale ) {
			return $redirect_url;
		}

		$localized = LanguageUrl::localize( $redirect_url, $locale );

		return $localized === $this->requested_url() ? false : $localized;
	}
}
