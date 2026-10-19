<?php

namespace TranslateWP\Entities\Styles;

use TranslateWP\Entities\Style;

class Shared extends Style {

    protected string $type          = 'admin';
    protected string $name          = 'shared';
    protected array $dependencies   = [];

    private const PLUGIN_SCREEN_IDS = [
        'toplevel_page_translatewp',
        'translatewp_page_translatewp-languages',
        'translatewp_page_translatewp-translations',
        'translatewp_page_translatewp-lexicon',
        'translatewp_page_translatewp-ai-providers',
        'translatewp_page_translatewp-settings',
    ];

    public function conditions(): bool {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }

        $screen_id = (string) ( $screen->id ?? '' );
        return in_array( $screen_id, self::PLUGIN_SCREEN_IDS, true );
    }
}
