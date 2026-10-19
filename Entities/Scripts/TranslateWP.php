<?php

namespace TranslateWP\Entities\Scripts;

use TranslateWP\Components\Audience;
use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\Options;
use TranslateWP\Components\PageAreas;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\RemoteApi;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\SwitcherPlacement;
use TranslateWP\Components\Sites;
use TranslateWP\Components\TranslationAiSettings;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Entities\Script;

class TranslateWP extends Script {

    protected string $type = 'frontend';
    protected string $name = 'translatewp';
    protected array $settings = [ 'in_footer' => true ];

    public function __construct( $plugin_name, $plugin_version ) {
        parent::__construct( $plugin_name, $plugin_version );

        $this->inline_content = [ $this, 'frontend_data' ];

        add_action( 'wp_head', [ $this, 'browser_notranslate_meta' ], 1 );
    }

    /**
     * Whenever the visitor runtime is enqueued, tell browsers not to
     * layer their own page translation over it. The document element itself
     * is never marked untranslatable, because the runtime's own extraction
     * honours that mark and would skip the whole page.
     */
    public function browser_notranslate_meta(): void {
        if ( ! self::visitor_runtime_ready() || PageExclusion::is_current_page_excluded() ) {
            return;
        }

        if ( ! apply_filters( 'translatewp_browser_notranslate', true ) ) {
            return;
        }

        echo '<meta name="google" content="notranslate">' . "\n";
    }

    /**
     * A manager on a site that already holds its languages and credentials
     * gets the runtime before setup ends, so the translated pages the wizard
     * links to show translated.
     */
    public static function is_setup_preview(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $languages = Options::get( 'languages.list', [] );

        return is_array( $languages ) && count( $languages ) > 0 && SiteConnection::has_credentials();
    }

    public function conditions(): bool {
        return self::loads_on_front_end();
    }

    /**
     * Visitors are served only on a ready site. A user who can manage the
     * plugin also gets the bundle on a site that is not ready, so the switcher
     * can say why visitors see nothing and link to the fix.
     */
    public static function loads_on_front_end(): bool {
        // A page left out of translation is sent no runtime at all, so its
        // text is neither translated nor reported, for a manager either.
        if ( PageExclusion::is_current_page_excluded() ) {
            return false;
        }

        // A crawler on a translated address is served a finished page.
        if ( \TranslateWP\Functionality\CrawlerResponse::is_active() ) {
            return false;
        }

        return self::visitor_runtime_ready() || self::is_setup_preview() || '' !== self::manager_setup_state();
    }

    /**
     * Why a site that is not ready serves nobody, for a user who can manage
     * the plugin: "reconnect" when setup finished and the credentials are
     * gone, "setup" otherwise. Empty for a visitor and on a ready site.
     */
    public static function manager_setup_state(): string {
        if ( self::visitor_runtime_ready() || self::is_setup_preview() || ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        $languages = Options::get( 'languages.list', [] );
        $set_up    = Options::get( 'setup.ended', false ) && is_array( $languages ) && count( $languages ) > 0;

        return $set_up && ! SiteConnection::has_credentials() ? 'reconnect' : 'setup';
    }

    /**
     * Whether visitors are served at all: the visitor bundle and every
     * front-end asset that exists for it load only on a site that finished
     * setup, holds a language list and holds credentials. An unpaired or
     * disconnected site sends visitors nothing.
     */
    public static function visitor_runtime_ready(): bool {
        // A restarted wizard re-opens setup on a site that is
        // still connected and configured; visitors keep being served until it
        // finishes.
        $setup_ended = Options::get( 'setup.ended', false ) || Options::get( 'setup.restarted', false );
        $languages   = Options::get( 'languages.list', [] );

        // The visitor runtime keeps loading and serving from the store
        // whenever the site holds credentials, so a merely unverified or
        // address-changed connection never stops translating for visitors. The
        // moved/rejected verdict clears on the next successful status call.
        return $setup_ended
            && is_array( $languages ) && count( $languages ) > 0
            && SiteConnection::has_credentials()
            && Audience::allows_current_user();
    }

    public function frontend_data(): string {
        $home_url = home_url();

        return 'window.TranslateWP = ' . wp_json_encode(
            [
				'context' => array_merge(
                    [
						'home_url'        => $home_url,
						'domain'          => (string) wp_parse_url( $home_url, PHP_URL_HOST ),
						'site_id'         => Sites::registered_id(),
						'nonce'           => wp_create_nonce( 'wp_rest' ),
						'remote_api_base' => RemoteApi::base_url(),
						'admin_base_url'  => rest_url( 'translatewp/v1/admin' ),
						'translation_base_url' => rest_url( 'translatewp/v1/translation' ),
						// The runtime serves whenever credentials exist, so a
						// merely unverified state never stops visitor translation.
						'connected'       => SiteConnection::has_credentials(),
						'cache_version'   => TranslationStore::cache_generation_fallback(),
						'cache_versions'  => (object) TranslationStore::cache_generations(),
						'page_placement'  => SwitcherPlacement::page_placement( get_queried_object_id() ),
						// The translation language the address names, '' on a plain address.
						'requested_locale' => LanguageUrl::requested_locale(),
						'orbital'          => (bool) ( ProEligibility::cached()['entitled'] ?? false ),
					],
                    $this->setup_notice(),
                    self::opens_edit_mode() ? [
						'page_object'       => self::page_object(),
						'settings_revision' => \TranslateWP\Functionality\Endpoints\Admin::settings_revision_value(),
						'pro'               => [
							'entitled'    => (bool) ( ProEligibility::cached()['entitled'] ?? false ),
							'upgrade_url' => ProEligibility::upgrade_url_with_return(),
							'installed'   => ProEligibility::is_installed(),
							'active'      => ProEligibility::is_active(),
							'install_url' => admin_url( 'admin.php?page=translatewp' ),
						],
					] : []
                ),
				'options'   => $this->get_frontend_options(),
				'settingsUrl' => \TranslateWP\Components\Access::can( \TranslateWP\Components\Access::SETTINGS ) ? admin_url( 'admin.php?page=translatewp-settings#switcher' ) : '',
			] + self::manager_flags(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';';
    }

    /**
     * What the switcher may show the current user: `can` per capability, and
     * `canManage`, the full manager an older addon bundle reads.
     *
     * @return array{can: array{edit: bool, retranslate: bool, settings: bool, lexicon: bool}, canManage: bool}
     */
    public static function manager_flags(): array {
        $access = \TranslateWP\Components\Access::class;

        return [
            'can'       => [
                'edit'        => $access::can( $access::EDIT ),
                'retranslate' => $access::can( $access::RETRANSLATE ),
                'settings'    => $access::can( $access::SETTINGS ),
                'lexicon'     => $access::can( $access::LEXICON ),
            ],
            'canManage' => $access::can_manage_translations(),
        ];
    }

    /**
     * The single post or page on screen, for a user who may retranslate it
     * from edit mode; null anywhere else.
     *
     * @return array{type: string, id: int, url: string}|null
     */
    public static function page_object(): ?array {
        $access = \TranslateWP\Components\Access::class;
        if ( ! $access::can( $access::RETRANSLATE ) || ! is_singular() ) {
            return null;
        }
        $id   = (int) get_queried_object_id();
        $type = $id > 0 ? (string) get_post_type( $id ) : '';
        if ( '' === $type ) {
            return null;
        }

        return [
            'type' => $type,
            'id'   => $id,
            'url'  => (string) get_permalink( $id ),
        ];
    }

    /** Edit mode opens for editing, retranslating or the switcher settings. */
    private static function opens_edit_mode(): bool {
        $flags = self::manager_flags()['can'];

        return $flags['edit'] || $flags['retranslate'] || $flags['settings'];
    }

    private function setup_notice(): array {
        $state = self::manager_setup_state();
        if ( '' === $state ) {
            return [];
        }

        return [
            'setup_notice' => [
                'state' => $state,
                'url'   => 'reconnect' === $state
                    ? admin_url( 'admin.php?page=translatewp-settings#connection' )
                    : admin_url( 'admin.php?page=translatewp' ),
            ],
        ];
    }

    public function get_frontend_options(): array {
        $translation_settings   = array_merge(
            [
				'auto_translate'            => true,
				'preserve_html_attributes'  => false,
				'translate_image_alt_tags'  => true,
				'preserve_branded_terms'    => true,
				'localized_date_time_formats' => true,
				'enable_units_conversion'   => true,
			],
            is_array( Options::get( 'translations.settings' ) ) ? Options::get( 'translations.settings' ) : []
        );

        $context_settings       = array_merge(
            [
				'text_context' => '',
			],
            is_array( Options::get( 'translations.context' ) ) ? Options::get( 'translations.context' ) : []
        );

        $style_settings         = array_merge(
            [
				'type'       => 'neutral',
				'tone'       => 'friendly',
				'formality'  => 5,
				'creativity' => 5,
			],
            is_array( Options::get( 'translations.style' ) ) ? Options::get( 'translations.style' ) : []
        );

        $default_switcher = [
            'type'             => 'dropdown',
            'trigger'          => 'name_flag',
            'show_flag'        => true,
            'native_names'     => true,
            'regional_names'   => 'full',
            'pending_message'  => '',
            'icon_style'       => 'rectangle',
            'text_color'       => '#393535',
            'background_color' => '#FFFFFF',
            'background_style' => 'solid',
            'highlight_text'   => '#FFFFFF',
            'highlight_bg'     => \TranslateWP\Functionality\Endpoints\Management::DEFAULT_SWITCHER_HIGHLIGHT_BG,
            'offset'           => 24,
            'icon_size'        => 24,
            'padding'          => 12,
        ];
        $default_seo = [
			'url_structure'              => 'clean',
            'force_lowercase'            => true,
            'remember_last_choice'       => true,
            'cookie_ttl'                 => 30,
            'redirect_after_switch'      => false,
            'output_hreflang'            => true,
            'include_x_default'          => true,
            'sitemaps'                   => true,
            'exclude_disabled_languages' => true,
            'redirect_changed_slugs'     => '301',
            'ai_crawlers'                => [
				'preset_answer_engines' => true,
				'bots'                  => [],
			],
        ];
        $raw_languages = is_array( Options::get( 'languages' ) ) ? Options::get( 'languages' ) : [];
        $switcher      = array_merge( $default_switcher, is_array( $raw_languages['switcher'] ?? null ) ? $raw_languages['switcher'] : [] );
        // Branding stays on a Terrestrial site; an Orbital site hides it unless it chose to show it.
        // The Orbital plan's choice, made in the free plugin: it holds with or without the Pro addon.
        $switcher['hide_branding'] = ! empty( ProEligibility::cached()['entitled'] ) && false !== ( $switcher['hide_branding'] ?? true );
        $switcher['selected_type'] = \TranslateWP\Components\SwitcherPlacement::selected_type( $switcher );
        $switcher['type']          = \TranslateWP\Components\SwitcherPlacement::effective_type( $switcher );
        $languages     = [
            'list'     => $raw_languages['list'] ?? [],
            'switcher' => $switcher,
            'seo'      => array_merge( $default_seo, is_array( $raw_languages['seo'] ?? null ) ? $raw_languages['seo'] : [] ),
        ];

        return [
            'languages'      => $languages,
            'auto_translate' => $translation_settings['auto_translate'],
            'extraction_options' => [
                // Translating image alt text is a Pro feature.
                'includeAlt'        => (bool) $translation_settings['translate_image_alt_tags'] && ProEligibility::unlocks_pro_features(),
                'includeAttributes' => ! (bool) $translation_settings['preserve_html_attributes'],
                'excludedAreas'     => PageAreas::excluded( $translation_settings ),
            ],
            'ai_settings'    => TranslationAiSettings::build(),
        ];
    }
}
