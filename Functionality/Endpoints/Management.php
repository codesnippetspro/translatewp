<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\CleanupInventory;
use TranslateWP\Components\Options;
use TranslateWP\Components\PageCachePurge;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\SiteLanguageSync;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Entities\CPTs\Lexicon;
use TranslateWP\Functionality\Endpoints\BaseApiEndpoints;

class Management extends BaseApiEndpoints {
    const DEFAULT_SWITCHER_HIGHLIGHT_BG = '#0e3ecd';

    public function __construct( $plugin_name, $plugin_version ) {
        parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/management' );
    }

    public function define_endpoints() {
        $cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

        $finish_setup = new PostEndpoint(
            $this->namespace,
            'finish-setup',
            [ $this, 'finish_setup' ],
            [ $this, 'admin_permissions_check' ]
        );
        $finish_setup->useMiddleware( $cors );

        $reconnect = new PostEndpoint(
            $this->namespace,
            'reconnect',
            [ $this, 'reconnect' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $reconnect->useMiddleware( $cors );

        $save_step = new PostEndpoint(
            $this->namespace,
            'save-step',
            [ $this, 'save_step' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $save_step->useMiddleware( $cors );

        $restart_setup = new PostEndpoint(
            $this->namespace,
            'restart-setup',
            [ $this, 'restart_setup' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $restart_setup->useMiddleware( $cors );

        $reset_data = new PostEndpoint(
            $this->namespace,
            'reset-data',
            [ $this, 'reset_data' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $reset_data->useMiddleware( $cors );

        $dismiss_finish_setup = new PostEndpoint(
            $this->namespace,
            'dismiss-finish-setup',
            [ $this, 'dismiss_finish_setup' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $dismiss_finish_setup->useMiddleware( $cors );

        $dismiss_pro_welcome = new PostEndpoint(
            $this->namespace,
            'dismiss-pro-welcome',
            [ $this, 'dismiss_pro_welcome' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $dismiss_pro_welcome->useMiddleware( $cors );

        return [
            $finish_setup,
            $reconnect,
            $save_step,
            $restart_setup,
            $reset_data,
            $dismiss_finish_setup,
            $dismiss_pro_welcome,
        ];
    }

    public function dismiss_finish_setup( $request ) {
        unset( $request );
        SiteConnection::dismiss_finish_setup();

        return [ 'success' => true ];
    }

    public function dismiss_pro_welcome( $request ) {
        unset( $request );
        ProEligibility::dismiss_welcome();

        return [ 'success' => true ];
    }

    public function reconnect( $request ) {
        $locked = \TranslateWP\Components\SettingsScope::refusal_for_group( 'account' );
        if ( null !== $locked ) {
            return $locked;
        }

        $path = sanitize_key( (string) $request->get_param( 'path' ) );
        if ( 'relink' === $path ) {
            SiteConnection::relink_after_invalidation();
            return [
				'success' => true,
				'path' => 'relink',
			];
        }

        if ( 'clone' === $path ) {
            SiteConnection::reconnect_as_new_site();
            return [
				'success' => true,
				'path' => 'clone',
			];
        }

        return new \WP_Error( 'invalid_reconnect_path', 'Reconnect path must be relink or clone', [ 'status' => 400 ] );
    }

    public function restart_setup( $request ) {
        unset( $request );
        Options::update( 'setup.ended', false );
        Options::update( 'setup.restarted', true );
        \TranslateWP\Components\ActionLog::record( 'Setup restarted by the administrator' );
        delete_transient( 'translatewp_pairing_verified' );

        return [ 'success' => true ];
    }

    public function reset_data( $request ) {
        unset( $request );
        $locked = \TranslateWP\Components\SettingsScope::refusal_for_group( 'account' );
        if ( null !== $locked ) {
            return $locked;
        }

        // A site without a pairing wipes locally; the remote revoke runs for a paired site only.
        if ( SiteConnection::has_credentials() ) {
            $remote = SiteRequests::post( '/v1/site/disconnect', [] );

            if ( is_wp_error( $remote ) ) {
                $status = (int) ( $remote->get_error_data()['status'] ?? 0 );
                if ( 401 !== $status ) {
                    return new \WP_Error(
                        'disconnect_failed',
                        __( 'Disconnect failed. Try again or disconnect from your account portal.', 'translate' ),
                        [ 'status' => 502 ]
                    );
                }
            }
        }

        SiteConnection::disconnect();
        \TranslateWP\Components\ActionLog::record( 'All plugin data reset by the administrator', 'warning' );
        CleanupInventory::wipe_all();

        return [ 'success' => true ];
    }

    public function save_step( $request ) {
        $step = sanitize_key( (string) $request->get_param( 'step' ) );

        if ( '' === $step ) {
            return new \WP_Error( 'missing_step', 'Step is required', [ 'status' => 400 ] );
        }

        $resume_step = sanitize_key( (string) $request->get_param( 'resume_step' ) );
        Options::update( 'setup.step', '' !== $resume_step ? $resume_step : $step );

        if ( 'languages' === $step ) {
            if ( null !== $request->get_param( 'context' ) ) {
                Options::update( 'translations.context.text_context', sanitize_textarea_field( (string) $request->get_param( 'context' ) ) );
            }
            $default_language = $this->normalize_locale( (string) $request->get_param( 'default_language' ) );

            if ( '' !== $default_language ) {
                $languages_list = $this->languages_list( $default_language, (array) $request->get_param( 'languages' ) );
                Options::update( 'languages.list', $languages_list );
                // Translation starts the moment the languages are chosen.
                SiteLanguageSync::sync_active_languages( true );
            }
        }

        if ( 'style-tone' === $step ) {
            $style = sanitize_key( (string) $request->get_param( 'style' ) );
            $tone  = sanitize_key( (string) $request->get_param( 'tone' ) );
            if ( in_array( $style, Scanning::STYLE_PRESETS, true ) ) {
                Options::update( 'translations.style.type', $style );
            }
            if ( in_array( $tone, Scanning::TONE_PRESETS, true ) ) {
                Options::update( 'translations.style.tone', $tone );
            }
        }

        if ( 'translation' === $step ) {
            $auto_translate = (bool) $request->get_param( 'auto_translate' );
            $current        = Options::get( 'translations.settings', [] );
            if ( ! is_array( $current ) ) {
                $current = [];
            }
            $current['auto_translate'] = $auto_translate;
            Options::update( 'translations.settings', \TranslateWP\Components\SettingsScope::keep_locked( 'translations.settings', $current ) );
        }

        if ( 'context' === $step ) {
            $context = (string) $request->get_param( 'context' );
            Options::update( 'translations.context.text_context', $context );
        }

        return [
            'success' => true,
            'step'    => $step,
        ];
    }

    public function finish_setup( $request ) {
        $default_language = $this->normalize_locale( (string) $request->get_param( 'default_language' ) );
        $auto_translate   = (bool) $request->get_param( 'auto_translate' );
        $context          = (string) $request->get_param( 'context' );

        if ( '' === $default_language ) {
            return new \WP_Error( 'invalid_default_language', 'A default language is required', [ 'status' => 400 ] );
        }

        $languages_list = $this->languages_list( $default_language, (array) $request->get_param( 'languages' ) );

        Options::update( 'languages.list', $languages_list );
        SiteLanguageSync::sync_active_languages( true );

        // Backfill the language scope of lexicon terms auto-created during the scan, now
        // that the final language list is known. Only terms with no scope yet are touched
        // so any manual edits are preserved.
        $this->backfill_lexicon_languages( array_keys( $languages_list ) );

        // Seed default switcher settings if not already set.
        if ( ! Options::get( 'languages.switcher' ) ) {
            $switcher_seed = [
				'type'             => 'dropdown',
				'trigger'          => 'name_flag',
				'show_flag'        => true,
				'native_names'     => true,
				'regional_names'   => 'full',
				'icon_style'       => 'rectangle',
				'text_color'       => '#393535',
				'background_color' => '#FFFFFF',
				'border_color'     => '#E2E2E4',
				'highlight_text'   => '#FFFFFF',
				'highlight_bg'     => self::DEFAULT_SWITCHER_HIGHLIGHT_BG,
				'loading_border_color' => self::DEFAULT_SWITCHER_HIGHLIGHT_BG,
				'offset'           => 24,
				'icon_size'        => 24,
				'padding'          => 12,
            ];
            Options::update( 'languages.switcher', \TranslateWP\Components\SettingsScope::keep_locked( 'languages.switcher', $switcher_seed ) );
        }

        // Update the translations settings (full defaults merged with user choice).
        $default_translation_settings = [
            'translate_all_content'       => true,
            'post_types'                  => [],
            'categories'                  => [],
            'auto_translate'              => $auto_translate,
            'translate_image_alt_tags'    => true,
            'translate_protected_pages'   => false,
            'preserve_html_attributes'    => false,
            'preserve_branded_terms'      => true,
            'localized_date_time_formats' => true,
            'enable_units_conversion'     => true,
        ];
        $existing_translation_settings = Options::get( 'translations.settings' );
        Options::update(
            'translations.settings',
            \TranslateWP\Components\SettingsScope::keep_locked(
                'translations.settings',
                array_merge(
                    $default_translation_settings,
                    is_array( $existing_translation_settings ) ? $existing_translation_settings : [],
                    [ 'auto_translate' => $auto_translate ]
                )
            )
        );

        // Update the translations context.
        Options::update( 'translations.context.text_context', $context );

        // Mark setup as complete; a restarted wizard is over once it finishes.
        Options::update( 'setup.ended', true );
        \TranslateWP\Components\ActionLog::record( 'Setup finished' );
        Options::update( 'setup.restarted', false );
        Options::update( 'setup.step', 'done' );
        PageCachePurge::purge_all( 'setup_finished' );

        return [ 'success' => true ];
    }

    private function normalize_locale( string $locale ): string {
        return str_replace( '_', '-', trim( $locale ) );
    }

    private function slug_from_locale( string $locale, array $taken = [] ): string {
        return \TranslateWP\Components\LanguageSlugs::for_setup( $locale, $taken );
    }

    private function languages_list( string $default_language, array $languages ): array {
        $languages = array_values( array_filter( array_map( [ $this, 'normalize_locale' ], $languages ) ) );

        $languages_list = [];
        $taken          = [];
        foreach ( array_unique( array_merge( [ $default_language ], $languages ) ) as $language ) {
            $slug                        = $this->slug_from_locale( $language, $taken );
            $taken[]                     = $slug;
            $languages_list[ $language ] = [
                'locale'  => $language,
                'slug'    => $slug,
                'active'  => true,
                'default' => $language === $default_language,
            ];
        }

        return $languages_list;
    }

    /**
     * Apply the given locales to any lexicon term that does not yet have a language scope.
     */
    private function backfill_lexicon_languages( array $locales ): void {
        $locales = array_values( array_unique( array_filter( array_map( 'strval', $locales ) ) ) );

        if ( empty( $locales ) ) {
            return;
        }

        $lexicon_ids = get_posts(
            [
				'post_type'      => Lexicon::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
        );

        foreach ( $lexicon_ids as $post_id ) {
            $current = get_post_meta( $post_id, Lexicon::LANGUAGES_META_KEY, true );

            if ( is_array( $current ) && ! empty( $current ) ) {
                continue;
            }

            update_post_meta( $post_id, Lexicon::LANGUAGES_META_KEY, $locales );
        }
    }
}
