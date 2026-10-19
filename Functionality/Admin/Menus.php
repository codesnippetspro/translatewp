<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\Access;
use TranslateWP\Components\IndexQueue;

use TranslateWP\Components\Options;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteConnection;

class Menus {

    // Unquoted on purpose: a style or script element decodes no entities,
    // so an escaped quote would make the selector invalid.
    private const UPGRADE_LINK_SELECTOR = 'a[href*=' . ProEligibility::START_UPGRADE_ACTION . ']';

    protected $plugin_name;
    protected $plugin_version;

    public function __construct( $plugin_name, $plugin_version ) {
        $this->plugin_name = $plugin_name;
        $this->plugin_version = $plugin_version;

        // The check that denies an unregistered submenu page runs while the
        // menu loads, before admin_init, so the setup redirect is hooked there too.
        add_action( 'admin_menu', [ $this, 'redirect_to_setup' ], 1 );
        add_action( 'admin_init', [ $this, 'redirect_to_setup' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_head', [ $this, 'render_upgrade_menu_styles' ] );
        add_action( 'admin_print_footer_scripts', [ $this, 'render_upgrade_menu_script' ] );
    }

    public function add_admin_menu() {
        // A user who is not an administrator gets the menu with the pages
        // their capabilities open, the Settings and the Lexicon; setup and
        // the rest stay with administrators.
        $delegate = Access::is_delegate() && ! SiteConnection::needs_onboarding();
        add_menu_page(
            'Dashboard',
            esc_html__( 'TranslateWP', 'translate' ),
            $delegate ? ( Access::can( Access::SETTINGS ) ? Access::SETTINGS : Access::LEXICON ) : 'manage_options',
            'translatewp',
            [ $this, 'render_admin_page' ],
            \TranslateWP\Components\BrandIcon::menu_icon(),
            25
        );

        if ( SiteConnection::needs_onboarding() ) {
            return;
        }

        if ( $delegate ) {
            // The same screens under the same menu, so each keeps the screen id
            // its scripts and styles load for. The menu's own entry is the
            // Dashboard, which is not theirs: it is taken out of the menu and
            // refused when opened by address.
            // The menu opens on its first entry, the page Access::admin_page() names.
            if ( Access::can( Access::SETTINGS ) ) {
                add_submenu_page( 'translatewp', esc_html__( 'Settings', 'translate' ), esc_html__( 'Settings', 'translate' ), Access::SETTINGS, 'translatewp-settings', [ $this, 'render_dashboard_page' ] );
            }
            if ( Access::can( Access::LEXICON ) ) {
                add_submenu_page( 'translatewp', esc_html__( 'Lexicon', 'translate' ), esc_html__( 'Lexicon', 'translate' ), Access::LEXICON, 'translatewp-lexicon', [ $this, 'render_dashboard_page' ] );
            }
            remove_submenu_page( 'translatewp', 'translatewp' );
            // Before any output, so the refusal carries its status.
            add_action( 'load-toplevel_page_translatewp', [ $this, 'refuse_non_administrator' ] );
            return;
        }

        // Five entries. Dashboard, SEO Tools, Lexicon, Settings and,
        // on Free, Upgrade.
        add_submenu_page(
            'translatewp',
            esc_html__( 'Dashboard', 'translate' ),
            esc_html__( 'Dashboard', 'translate' ),
            'manage_options',
            'translatewp',
            [ $this, 'render_dashboard_page' ]
        );

        $entitled  = (bool) ProEligibility::cached()['entitled'];
        $seo_label = $entitled && ProEligibility::is_active()
            ? esc_html__( 'SEO Tools', 'translate' )
            : esc_html__( 'SEO Tools', 'translate' ) . ' <span class="translatewp-pro-pill" aria-label="' . esc_attr__( 'Pro feature', 'translate' ) . '">PRO</span>';

        add_submenu_page(
            'translatewp',
            esc_html__( 'SEO Tools', 'translate' ),
            $seo_label,
            'manage_options',
            'translatewp-seo',
            [ $this, 'render_dashboard_page' ]
        );

        add_submenu_page(
            'translatewp',
            esc_html__( 'Lexicon', 'translate' ),
            esc_html__( 'Lexicon', 'translate' ),
            Access::LEXICON,
            'translatewp-lexicon',
            [ $this, 'render_dashboard_page' ]
        );

        add_submenu_page(
            'translatewp',
            esc_html__( 'Settings', 'translate' ),
            esc_html__( 'Settings', 'translate' ),
            Access::SETTINGS,
            'translatewp-settings',
            [ $this, 'render_dashboard_page' ]
        );

		$this->add_upgrade_menu_link();
    }

    public function redirect_to_setup() {
        if ( is_network_admin() || ! SiteConnection::needs_onboarding() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        if ( 0 !== strpos( $page, 'translatewp-' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=translatewp&translatewp_notice=finish-setup' ) );
        exit;
    }

    private function add_upgrade_menu_link() {
        global $submenu;

        if ( ! isset( $submenu['translatewp'] ) ) {
            return;
        }

        // An account that already includes Pro but has no active
        // addon on this site gets "Install Pro" or "Activate Pro", pointing at
        // the Dashboard card with the one-click action; an account without
        // Pro gets "Upgrade".
        if ( (bool) ProEligibility::cached()['entitled'] ) {
            if ( ProEligibility::should_show_install_prompt() ) {
                $label = esc_html__( 'Install Pro', 'translate' );
            } elseif ( SiteConnection::is_connected() && ProEligibility::is_installed() && ! ProEligibility::is_active() ) {
                $label = esc_html__( 'Activate Pro', 'translate' );
            } else {
                return;
            }

            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Raw $submenu href keeps the Overview tab hash.
            $submenu['translatewp'][] = [
                $label,
                'manage_options',
                admin_url( 'admin.php?page=translatewp#overview' ),
            ];

            return;
        }

        // Append the "Upgrade to Pro" link directly to the submenu. Using the global
        // $submenu (rather than add_submenu_page) keeps the raw URL as the anchor href
        // so WordPress links out to the portal instead of loading an admin page.
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Upgrade URL requires raw $submenu href.
        $submenu['translatewp'][] = [
            esc_html__( 'Upgrade', 'translate' ),
            'manage_options',
            ProEligibility::upgrade_url_with_return(),
        ];
    }

    public function render_upgrade_menu_styles() {
        echo '<style>
            #adminmenu .translatewp-pro-pill { display: inline-block; margin-left: 6px; padding: 0 6px; border-radius: 3px; background: #0e3ecd; color: #fff; font-size: 10px; font-weight: 700; line-height: 16px; vertical-align: middle; }
            #adminmenu ' . esc_html( self::UPGRADE_LINK_SELECTOR ) . ',
            #adminmenu a[href$="page=translatewp#overview"] {
                color: #0e3ecd;
                font-weight: 600;
            }
            #adminmenu ' . esc_html( self::UPGRADE_LINK_SELECTOR ) . ':hover,
            #adminmenu a[href$="page=translatewp#overview"]:hover {
                color: #0b31a4;
            }
        </style>';
    }

    public function render_upgrade_menu_script() {
        echo '<script>
            ( function() {
                var link = document.querySelector( \'#adminmenu ' . esc_js( self::UPGRADE_LINK_SELECTOR ) . '\' );
                if ( link ) {
                    link.setAttribute( "target", "_blank" );
                    link.setAttribute( "rel", "noopener noreferrer" );
                }
            } )();
        </script>';
    }

    public function refuse_non_administrator() {
        if ( ! Access::can_administer() ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'translate' ), '', [ 'response' => 403 ] );
        }
    }

    public function render_admin_page() {
        $this->refuse_non_administrator();

        if ( SiteConnection::needs_onboarding() ) {
            $this->render_setup_page();
        } else {
            $this->render_dashboard_page();
        }
    }

    public function render_setup_page() {
        $languages_list = Options::get( 'languages.list', [] );
        $default_language = get_locale();
        if ( is_array( $languages_list ) ) {
            foreach ( $languages_list as $language ) {
                if ( is_array( $language ) && ! empty( $language['locale'] ) && ! empty( $language['default'] ) ) {
                    $default_language = (string) $language['locale'];
                    break;
                }
            }
        }

        $saved_languages = [];
        if ( is_array( $languages_list ) ) {
            foreach ( $languages_list as $language ) {
                if ( is_array( $language ) && ! empty( $language['locale'] ) ) {
                    $saved_languages[] = (string) $language['locale'];
                }
            }
        }

        $translation_settings = Options::get( 'translations.settings', [] );
        $saved_step = Options::get( 'setup.step', '' );

        $notice = isset( $_GET['translatewp_notice'] ) ? sanitize_key( wp_unslash( $_GET['translatewp_notice'] ) ) : '';
        if ( 'finish-setup' === $notice ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Finish the setup first. The plugin pages open as soon as the setup is complete.', 'translate' ) . '</p></div>';
        }

        echo '<style>
                #translatewp-setup {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: #F0F0F1;
                    z-index: 100000;
                }
            </style>

            <script>
                window.setupData = ' . wp_json_encode(
            [
				'defaultLanguage' => $default_language,
				'languages'       => $saved_languages,
				'autoTranslate'   => (bool) ( is_array( $translation_settings ) ? ( $translation_settings['auto_translate'] ?? true ) : true ),
				'context'         => (string) Options::get( 'translations.context.text_context', '' ),
				'proposedContext' => (string) ( is_array( Options::get( 'translations.context.scan_result' ) ) ? ( Options::get( 'translations.context.scan_result' )['context'] ?? '' ) : '' ),
				'style'           => (string) Options::get( 'translations.style.type', 'neutral' ),
				'tone'            => (string) Options::get( 'translations.style.tone', 'friendly' ),
				'step'            => is_string( $saved_step ) ? $saved_step : '',
				'homeUrl'         => home_url( '/' ),
				'siteRead'        => ( new IndexQueue() )->read_counts()['pages'] > 0,
				'userLocale'      => get_user_locale(),
			],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';
            </script>
            
            <div id="translatewp-setup"></div>';
    }

    public function render_dashboard_page() {
        if ( SiteConnection::needs_onboarding() ) {
            $this->render_setup_page();
            return;
        }

        echo '<div id="translatewp-admin"></div>';
    }
}
