<?php

namespace TranslateWP\Entities\Scripts;

use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\Access;
use TranslateWP\Components\RemoteApi;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\Sites;
use TranslateWP\Entities\CPTs\Lexicon;
use TranslateWP\Entities\Script;

class Admin extends Script {

    protected string $type = 'admin';
    protected string $name = 'admin';
    protected array $settings = [ 'in_footer' => true ];

    public function __construct( $plugin_name, $plugin_version ) {
        parent::__construct( $plugin_name, $plugin_version );

        $this->inline_content = [ $this, 'admin_data' ];
    }

    public function admin_data(): string {
        return $this->pro_data() . $this->context_data();
    }

    public function context_data(): string {
        $home_url = home_url();

        return 'window.TranslateWP = Object.assign( window.TranslateWP || {}, ' . wp_json_encode(
            [
                'context'       => [
                    'home_url'             => $home_url,
                    'site_id'              => Sites::registered_id(),
                    'nonce'                => wp_create_nonce( 'wp_rest' ),
                    'remote_api_base'      => RemoteApi::base_url(),
                    'translation_base_url' => rest_url( 'translatewp/v1/translation' ),
                    // False for a user who manages translations by grant: the app then shows only what the grant covers.
                    'can_administer'       => \TranslateWP\Components\Access::can_administer(),
                    // Where a support ticket's answer goes unless the user changes it.
                    'user_email'           => (string) wp_get_current_user()->user_email,
                    'export_url'           => \TranslateWP\Components\Access::can_administer() ? \TranslateWP\Components\SiteExport::url() : '',
                    'other_translation_plugins' => \TranslateWP\Components\PluginCoexistence::detected(),
                    'network_settings_url' => is_multisite() && current_user_can( 'manage_network_options' ) ? network_admin_url( 'admin.php?page=translatewp-network-settings' ) . '#settings' : '',
                    'settings_scope'       => (object) \TranslateWP\Components\SettingsScope::subsite_scope(),
                ],
                'can'           => \TranslateWP\Entities\Scripts\TranslateWP::manager_flags()['can'],
                'lexicon_terms' => Access::can( Access::LEXICON ) || Access::can( Access::EDIT ) ? Lexicon::get_all() : [],
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ' );';
    }

    public static function pro_data(): string {
        $state = ProEligibility::cached();

        return 'window.translatewpPro = ' . wp_json_encode(
            [
                'connected'  => SiteConnection::is_connected(),
                'entitled'   => (bool) $state['entitled'],
                'installed'  => ProEligibility::is_installed(),
                'active'     => ProEligibility::is_active(),
                'version'    => ProEligibility::is_installed() ? ProEligibility::installed_version() : '',
                'upgradeUrl' => ProEligibility::upgrade_url_with_return(),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';';
    }

    public function conditions(): bool {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }

        $is_translatewp_screen = 'toplevel_page_translatewp' === $screen->id
            || 0 === strpos( $screen->id, 'translatewp_page_' );

        if ( ! $is_translatewp_screen ) {
            return false;
        }

        return ! SiteConnection::needs_onboarding();
    }
}
