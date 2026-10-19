<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\Audience;
use TranslateWP\Components\Options;
use TranslateWP\Components\TextDirection;
use TranslateWP\Components\TranslationUrl;

class SEO {

    private const PRO_VERSION_WITHOUT_LANGUAGE_TAGS = '0.3.38';

    protected $plugin_name;
    protected $plugin_version;

    public function __construct( $plugin_name, $plugin_version ) {
        $this->plugin_name = $plugin_name;
        $this->plugin_version = $plugin_version;

        add_filter( 'language_attributes', [ $this, 'set_html_lang_from_query' ] );
        add_action( 'template_redirect', [ $this, 'prevent_personalized_page_caching' ] );
        // Wait until every plugin file has loaded: the addon defines its
        // version constant at load and builds its SEO class on
        // plugins_loaded priority 5, and the SEO plugin constants must exist.
        add_action( 'plugins_loaded', [ $this, 'register_language_tags' ] );
    }

    public function register_language_tags(): void {
        if ( $this->pro_prints_language_tags() ) {
            return;
        }

        add_action( 'wp_head', [ $this, 'add_hreflang_tags' ] );

        if ( defined( 'WPSEO_VERSION' ) ) {
            add_filter( 'wpseo_canonical', [ $this, 'filter_seo_plugin_canonical' ] );
        }
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            add_filter( 'rank_math/frontend/canonical', [ $this, 'filter_seo_plugin_canonical' ] );
        }
        if ( ! $this->seo_plugin_active() ) {
            // Core's canonical is printed back by add_canonical_tag wherever the
            // per-language one does not apply, so a page always carries exactly one.
            remove_action( 'wp_head', 'rel_canonical' );
            add_action( 'wp_head', [ $this, 'add_canonical_tag' ] );
        }
    }

    public function add_hreflang_tags(): void {
        $languages = Options::get( 'languages.list' );
        if ( empty( $languages ) || ! is_array( $languages ) || ! $this->is_supported_page() ) {
            return;
        }

        $seo = Options::get( 'languages.seo' );
        $seo = is_array( $seo ) ? $seo : [];
        if ( empty( $seo['output_hreflang'] ?? true ) ) {
            return;
        }

        $base_url = $this->current_content_url();
        if ( '' === $base_url ) {
            return;
        }

        foreach ( $languages as $language ) {
            if ( ! is_array( $language ) || empty( $language['active'] ) || empty( $language['locale'] ) ) {
                continue;
            }

            $locale = (string) $language['locale'];
            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr( $this->hreflang( $locale ) ),
                esc_url( $this->localized_url( $base_url, $locale ) )
            );
        }

        $default_locale = $this->default_locale();
        if ( '' !== $default_locale && ! empty( $seo['include_x_default'] ?? true ) ) {
            printf(
                '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
                esc_url( $this->localized_url( $base_url, $default_locale ) )
            );
        }
    }

    public function add_canonical_tag(): void {
        if ( $this->seo_plugin_active() ) {
            return;
        }

        $base_url  = $this->is_supported_page() ? $this->current_content_url() : '';
        $requested = $this->requested_active_locale();
        $locale    = '' !== $requested ? $requested : $this->default_locale();
        if ( '' === $base_url || '' === $locale ) {
            $this->print_core_canonical();
            return;
        }

        printf(
            '<link rel="canonical" href="%s" />' . "\n",
            esc_url( $this->localized_url( $base_url, $locale ) )
        );
    }

    public function filter_seo_plugin_canonical( $canonical ) {
        if ( ! is_string( $canonical ) || '' === $canonical ) {
            return $canonical;
        }

        $locale = $this->requested_active_locale();

        return '' === $locale ? $canonical : $this->localized_url( $canonical, $locale );
    }

    public function localized_url( string $url, string $locale ): string {
        return LanguageUrl::localize( $url, $locale );
    }

    private function pro_prints_language_tags(): bool {
        return defined( 'TRANSLATEWP_PRO_VERSION' )
            && version_compare( (string) TRANSLATEWP_PRO_VERSION, self::PRO_VERSION_WITHOUT_LANGUAGE_TAGS, '<' );
    }

    private function seo_plugin_active(): bool {
        return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' );
    }

    private function print_core_canonical(): void {
        if ( function_exists( 'rel_canonical' ) ) {
            rel_canonical();
        }
    }

    private function is_supported_page(): bool {
        // A page left out of translation has no translated versions to point
        // at, and neither has a visitor the role restriction leaves out.
        if ( PageExclusion::is_current_page_excluded() || ! Audience::allows_current_user() ) {
            return false;
        }

        return is_singular() || is_front_page() || is_home();
    }

    private function current_content_url(): string {
        $post_id = get_queried_object_id();
        if ( $post_id > 0 ) {
            $permalink = get_permalink( $post_id );
            return is_string( $permalink ) ? $permalink : '';
        }

        return home_url( '/' );
    }

    private function hreflang( string $locale ): string {
        $parts = preg_split( '/[-_]/', $locale );
        if ( ! is_array( $parts ) || empty( $parts ) ) {
            return $locale;
        }

        $parts[0] = strtolower( $parts[0] );
        foreach ( array_slice( $parts, 1, null, true ) as $index => $part ) {
            if ( 1 === preg_match( '/^[a-z]{2}$/i', $part ) ) {
                $parts[ $index ] = strtoupper( $part );
            } elseif ( 1 === preg_match( '/^[a-z]{4}$/i', $part ) ) {
                $parts[ $index ] = ucfirst( strtolower( $part ) );
            } else {
                $parts[ $index ] = strtolower( $part );
            }
        }

        return implode( '-', $parts );
    }

    public function set_html_lang_from_query( $output ) {
        $requested_locale = $this->requested_active_locale();
        if ( '' === $requested_locale ) {
            return $output;
        }

        $output = (string) preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $requested_locale ) . '"', $output );
        $dir    = TextDirection::is_rtl( $requested_locale ) ? 'rtl' : 'ltr';

        // The theme may print dir before lang, so the attribute is matched
        // wherever it sits, not only after whitespace.
        if ( preg_match( '/(?:^|\s)dir="[^"]*"/', $output ) ) {
            return (string) preg_replace( '/dir="[^"]*"/', 'dir="' . $dir . '"', $output );
        }

        return 'rtl' === $dir ? $output . ' dir="rtl"' : $output;
    }

    /**
     * A render personalized for the current view must never be stored or served
     * by a page cache or CDN: a signed-in render carries session-specific markup
     * (the manager capability in the inline script, the admin bar), and a ?lang=
     * page renders per-language content under the default-language URL.
     */
    public function prevent_personalized_page_caching(): void {
        if ( ! $this->is_personalized_render() ) {
            return;
        }

        add_filter( 'nocache_headers', [ $this, 'force_private_no_store' ] );
        nocache_headers();
        remove_filter( 'nocache_headers', [ $this, 'force_private_no_store' ] );

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }

    public function force_private_no_store( $headers ): array {
        if ( ! is_array( $headers ) ) {
            $headers = [];
        }

        $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0, no-store, private';

        return $headers;
    }

    private function is_personalized_render(): bool {
        if ( is_user_logged_in() ) {
            return true;
        }

        $requested_locale = $this->requested_active_locale();

        return '' !== $requested_locale && $requested_locale !== $this->default_locale();
    }

    private function default_locale(): string {
        $languages = Options::get( 'languages.list' );
        if ( empty( $languages ) ) {
            return '';
        }

        foreach ( $languages as $language ) {
            if ( ! empty( $language['active'] ) && ! empty( $language['default'] ) ) {
                return (string) ( $language['locale'] ?? '' );
            }
        }

        return '';
    }

    private function requested_active_locale(): string {
        return LanguageUrl::requested_locale();
    }
}
