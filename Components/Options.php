<?php

namespace TranslateWP\Components;

/**
 * Options general schema:
 *
 * - languages.list.{language_locale}: List of languages. Each one has:
 *      - slug: string
 *      - active: boolean
 *      - default: boolean
 *      - switcher_label?: string
 *
 * - languages.switcher: Switcher options
 *      - type: 'dropdown' | 'native_select' | 'side_tab' (side_tab is Pro; served as dropdown without Pro, the stored value kept)
 *      - show_flag: boolean
 *      - icon_style: string
 *      - text_color: string
 *      - background_color: string
 *      - border_color: string (hex, optional alpha; default #E2E2E4)
 *      - background_style: 'solid' | 'transparent' | 'glass' (default solid; glass is a frosted, see-through surface)
 *      - highlight_text: string
 *      - highlight_bg: string
 *      - loading_border_color: string (hex, optional alpha; the ring shown while translating; defaults to highlight_bg)
 *      - offset: int (px corner inset; default 32)
 *      - icon_size: int (px closed-trigger icon; default 24)
 *      - padding: int (px padding around icon; default 12)
 *      - pending_message: string (shown while a page still translates; empty means the default line)
 *      - hide_branding: boolean (Orbital; the switcher's "by TranslateWP" line, shown as Support TranslateWP: hidden by default on an Orbital site, with or without the Pro addon, always shown on Terrestrial)
 *      - anchor_fallback: 'floating' | 'none' (what a page without the anchored element shows)
 *      - floating_position: { anchor, x, y } (where the owner dragged the floating switcher on a wide screen)
 *      - floating_position_mobile: { anchor, x, y } (the same on a phone-width screen; the bottom corner until set)
 *      - side_tab_position: { edge, offset } (Pro Side tab: the edge, top|right|bottom|left, and 0-100 along it; bottom 85 until set)
 *      - side_tab_position_mobile: { edge, offset } (the same on a phone-width screen; never the top edge)
 *
 * - languages.seo: SEO options
 *      - url_structure: string (default clean)
 *      - force_lowercase: boolean
 *      - remember_last_choice: boolean
 *      - cookie_ttl: int
 *      - redirect_after_switch: boolean
 *      - output_hreflang: boolean
 *      - include_x_default: boolean
 *      - sitemaps: boolean (also covers translated-language sitemap entries)
 *      - exclude_disabled_languages: boolean
 *      - redirect_changed_slugs: string
 *      - ai_crawlers: AI crawler access control
 *           - preset_answer_engines: boolean
 *           - bots: { [bot_name]: { default: allow|deny, languages: { [locale]: allow|deny|inherit } } }
 *             (bot_name limited to a fixed allow-list, locale limited to configured languages.list entries)
 *
 * `languages.seo` is written as a deep merge (Functionality/Endpoints/Admin.php::update_data): a save that
 * only submits some of these keys leaves the rest of the stored option untouched.
 *
 * - translations.settings: Translations settings
 *      - translate_all_content: boolean
 *      - post_types: array
 *      - categories: array (term IDs as strings)
 *      - auto_translate: boolean
 *      - translate_image_alt_tags: boolean
 *      - translate_protected_pages: boolean
 *      - preserve_html_attributes: boolean
 *      - translate_comments, translate_menus, translate_widgets: boolean (page areas; on unless switched off)
 *      - visible_roles: string[] (retired: a stored value is ignored and every visitor sees translations)
 *      - preserve_branded_terms: boolean
 *      - localized_date_time_formats: boolean
 *      - enable_units_conversion: boolean
 *      - own_providers_after_quota: boolean (Pro; the own providers serve once the plan's quota is exhausted)
 *
 * - translations.context: Translations context
 *      - last_scan_time: timestamp
 *      - files: TODO IN LATER VERSIONS
 *      - pages: TODO IN LATER VERSIONS
 *      - text_context: string
 *
 * - translations.style: Translations style
 *      - type: string
 *      - tone: string
 *      - formality: int
 *      - creativity: int
 *      - audience: string, length: match|shorter|longer, cultural_adaptation: keep|adapt (Pro)
 *
 * - ai.models.{model_uuid}: AI model settings
 *      - provider: string
 *      - id: string
 *      - name: string
 *      - api_key_encrypted: string
 *      - api_key_mask: string
 *      - api_key_set: boolean
 *      - primary: boolean
 *      - connected: boolean
 *      - fallback_model: string?
 *
 * - site.id: API-issued Site UUID for metering and translation-cache attribution
 * - site.token: Opaque site bearer token issued at connect time
 * - site.home_url: Home URL captured when the site was connected
 *
 * - setup: Setup settings
 *      - ended: boolean
 */

/**
 * Options class
 * This class is an abstraction for reading, updating, and deleting plugin options by dot-path.
 *
 * @package TranslateWP\Components
 * @since 1.0.0
 * @version 1.0.0
 * @author TranslateWP
 * @link https://translatewp.ai/
 * @license GPL-3.0+
 * @copyright 2026 TranslateWP
 */
class Options {

    private const PREFIX    = 'translatewp_';
    private const SEPARATOR = '.';

    public static function get( $path, $default = null ) {
        $exploded_path = explode( self::SEPARATOR, $path );
        $option_name   = self::PREFIX . $exploded_path[0];
        $current       = get_option( $option_name, [] );
        $path_count    = count( $exploded_path );

        if ( 1 === $path_count ) {
            if ( $current ) {
                return $current;
            }

            return $default;
        }

        for ( $i = 1; $i < $path_count; $i++ ) {
            $key = $exploded_path[ $i ];
            if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
                return $default;
            }
            $current = $current[ $key ];
        }

        return $current;
    }

    public static function update( $path, $value ) {
        $exploded_path = explode( self::SEPARATOR, $path );
        $option_name   = self::PREFIX . $exploded_path[0];
        $current       = get_option( $option_name, [] );
        $path_count    = count( $exploded_path );

        if ( 1 === $path_count ) {
            update_option( $option_name, $value );

            return [ 'success' => true ];
        }

        if ( ! is_array( $current ) ) {
            $current = [];
        }

        $ref = &$current;
        for ( $i = 1; $i < $path_count - 1; $i++ ) {
            $key = $exploded_path[ $i ];
            if ( ! is_array( $ref[ $key ] ?? null ) ) {
                $ref[ $key ] = [];
            }
            $ref = &$ref[ $key ];
        }
        $ref[ $exploded_path[ $path_count - 1 ] ] = $value;

        update_option( $option_name, $current );

        return [ 'success' => true ];
    }

    public static function delete( $path ) {
        $exploded_path = explode( self::SEPARATOR, $path );
        $option_name   = self::PREFIX . $exploded_path[0];
        $path_count    = count( $exploded_path );

        if ( 1 === $path_count ) {
            delete_option( $option_name );
            return;
        }

        $current = get_option( $option_name, [] );
        if ( ! is_array( $current ) ) {
            return;
        }

        $ref = &$current;
        for ( $i = 1; $i < $path_count - 1; $i++ ) {
            $key = $exploded_path[ $i ];
            if ( ! is_array( $ref[ $key ] ?? null ) ) {
                return;
            }
            $ref = &$ref[ $key ];
        }
        $leaf_key = $exploded_path[ $path_count - 1 ];
        if ( array_key_exists( $leaf_key, $ref ) ) {
            unset( $ref[ $leaf_key ] );
            update_option( $option_name, $current );
        }

        return [ 'success' => true ];
    }
}
