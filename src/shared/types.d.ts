export interface TranslateWPLexiconTerm {
    id: number;
    term: string;
    description: string;
    languages?: string[];
}

export interface TranslateWPAiSettings {
    preserve_branded_terms: boolean;
    localized_date_time_formats: boolean;
    enable_units_conversion: boolean;
    context: string;
    writing_style: string;
    tone: string;
    formality: number;
    creativity: number;
    lexicon_terms: TranslateWPLexiconTerm[];
    audience?: string;
    length?: 'match' | 'shorter' | 'longer';
    cultural_adaptation?: 'keep' | 'adapt';
}

export interface TranslateWPContext {
    home_url: string;
    domain: string;
    site_id: string;
    nonce: string;
    remote_api_base: string;
    admin_base_url: string;
    translation_base_url: string;
    connected: boolean;
    // Bumped by a forced retranslation; the local hydration cache is namespaced
    // by it so a retranslated page's stale browser copy is skipped next load.
    cache_version: number;
    // Per-language generations; a language absent here uses cache_version. A
    // retranslation bumps only the languages it touched, so other languages'
    // visitors keep their warm local hydration cache.
    cache_versions?: Record<string, number>;
	settings_revision?: number;
    network_settings_url?: string;
    /** False for a user who manages translations by grant and is not an administrator. */
    can_administer?: boolean;
    /** The signed-in user's email: where a support ticket is answered unless changed. */
    user_email?: string;
    /** The wp-admin action that downloads the site's export file. */
    export_url?: string;
    /** Names of other translation plugins active on the current site. */
    other_translation_plugins?: string[];
    settings_scope?: Record<string, boolean>;
    // A Pro inline placement saved for this page only; wins over the
    // site-wide inline_placement in the switcher settings while it exists.
    page_placement?: import('@/apps/translatewp/inlinePlacement').InlinePlacement | null;
    // The translation language the address names, '' on a plain address.
    requested_locale?: string;
    orbital?: boolean;
    /** The single post or page on screen, for a user who may retranslate it; null elsewhere. */
    page_object?: { type: string; id: number; url: string } | null;
    // Present for a signed-in administrator only: whether the account unlocks
    // the Pro editor actions, where to unlock them, and, for an entitled
    // account, whether the addon is installed and active and where to install it.
    pro?: { entitled: boolean; upgrade_url: string; installed?: boolean; active?: boolean; install_url?: string };
    // Present only for a user who can manage the plugin, on a site that serves
    // no visitors: why, and where to fix it.
    setup_notice?: { state: 'setup' | 'reconnect'; url: string };
}

export interface TranslateWPOptions {
    languages: Record<string, any>;
    auto_translate: boolean;
    extraction_options: {
        includeAlt: boolean;
        includeAttributes: boolean;
        excludedAreas?: string[];
    };
    ai_settings: TranslateWPAiSettings;
}

// The frontend localizer (Entities/Scripts/TranslateWP.php) never runs in
// wp-admin. There window.TranslateWP carries the host bridge plus the subset of
// connection context and the lexicon terms the admin script localizes; the
// settings store holds the remaining extraction inputs.
export interface TranslateWPAdminData {
    context: Pick<TranslateWPContext, 'home_url' | 'site_id' | 'nonce' | 'remote_api_base' | 'translation_base_url'>;
    lexicon_terms: TranslateWPLexiconTerm[];
    host?: import('@/shared/host').HostBridge;
}

declare global {
    interface Window {
        setupData?: {
            defaultLanguage: string;
            languages?: string[];
            autoTranslate?: boolean;
            context?: string;
            proposedContext?: string;
            style?: string;
            tone?: string;
            formality?: number;
            creativity?: number;
            /** The site was read (spine extracted) at least once. */
            siteRead?: boolean;
            step?: string;
            /** The admin's profile locale (get_user_locale()), suggested first. */
            userLocale?: string;
            /** The address of the site's homepage. */
            homeUrl?: string;
        };

        TranslateWP: {
            context: TranslateWPContext;
            options?: TranslateWPOptions;
            canManage?: boolean;
            can?: { edit: boolean; retranslate: boolean; settings: boolean; lexicon: boolean };
            settingsUrl?: string;
            lexicon_terms?: TranslateWPLexiconTerm[];
            host?: import('@/shared/host').HostBridge;
            // The public page's bridge for the Pro front-end editor.
            frontend?: import('@/shared/host/frontend').FrontendHostBridge;
        };
    }
}

export {};
