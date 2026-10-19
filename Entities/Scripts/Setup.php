<?php

namespace TranslateWP\Entities\Scripts;

use TranslateWP\Components\SiteConnection;
use TranslateWP\Entities\Script;

class Setup extends Script {

    protected string $type = 'admin';
    protected string $name = 'setup';
    protected array $settings = [ 'in_footer' => true ];

    public function __construct( $plugin_name, $plugin_version ) {
        parent::__construct( $plugin_name, $plugin_version );

        $this->inline_content = [ Admin::class, 'pro_data' ];
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

        return SiteConnection::needs_onboarding();
    }
}
