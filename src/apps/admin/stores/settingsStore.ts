import type { SwitcherBackgroundStyle } from '@/apps/translatewp/types';
import { withToneFormality } from '@/apps/admin/stores/toneFormality';
import { create } from 'zustand';

import { updateAdminData } from '@/shared/api/admin';
import type { FloatingSwitcherPosition, SideTabPosition } from '@/shared/lib/switcherPosition';
import type { InlinePlacement } from '@/apps/translatewp/inlinePlacement';
import type { Language } from '@/shared/components/languageTable';

const DEFAULT_SWITCHER_OFFSET = 24;
const DEFAULT_SWITCHER_ICON_SIZE = 24;
const DEFAULT_SWITCHER_PADDING = 12;

// The switcher types release 1.0 renders; the Side tab is Pro.
export const SWITCHER_TYPES = ['dropdown', 'native_select', 'side_tab'] as const;

export type SwitcherType = typeof SWITCHER_TYPES[number];

export type SwitcherSettings = {
	// null asks the save to remove the dragged position ("Reset position").
	floating_position?: FloatingSwitcherPosition | null;
	floating_position_mobile?: FloatingSwitcherPosition | null;
	side_tab_position?: SideTabPosition | null;
	side_tab_position_mobile?: SideTabPosition | null;
    // A Pro site-wide inline placement; read-only in Settings.
    inline_placement?: InlinePlacement | null;
    type: SwitcherType;
    trigger: 'name_flag' | 'flag' | 'icon';
    show_flag: boolean;
    native_names: boolean;
    regional_names: 'full' | 'short';
    pending_message?: string;
    anchor_fallback?: 'floating' | 'none';
    // Stored as "hide"; the settings show it as Support TranslateWP, on when the line shows.
    hide_branding?: boolean;
    icon_style: 'rectangle' | 'square' | 'circle';
    text_color: string;
    background_color: string;
    border_color: string;
    background_style: SwitcherBackgroundStyle;
    highlight_text: string;
    highlight_bg: string;
    offset: number;
    icon_size: number;
    padding: number;
};

export type SEOSettings = {
    url_structure: 'clean' | 'query_string';
    force_lowercase: boolean;
    remember_last_choice: boolean;
    cookie_ttl: number;
    redirect_after_switch: boolean;
    output_hreflang: boolean;
    include_x_default: boolean;
    sitemaps: boolean;
    exclude_disabled_languages: boolean;
    redirect_changed_slugs: string;
    ai_crawlers: {
        preset_answer_engines: boolean;
        bots: Record<string, { default: 'allow' | 'deny'; languages: Record<string, 'allow' | 'deny' | 'inherit'> }>;
    };
};

export type TranslationsSettings = {
    translate_all_content: boolean;
    post_types: string[];
    categories: string[];
    auto_translate: boolean;
    translate_image_alt_tags: boolean;
    translate_protected_pages?: boolean;
    translate_comments?: boolean;
    translate_menus?: boolean;
    translate_widgets?: boolean;
    visible_roles?: string[];
    preserve_html_attributes: boolean;
    preserve_branded_terms: boolean;
    localized_date_time_formats: boolean;
    own_providers_after_quota?: boolean;
    enable_units_conversion: boolean;
};

export type TranslationsContext = {
    last_scan_time: number;
    files: string[];
    pages: string[];
    text_context: string;
};

export type TranslationsStyle = {
    type: 'marketing' | 'technical' | 'neutral' | 'legal' | 'medical';
    tone: string;
    formality: number;
    creativity: number;
    audience?: string;
    length?: 'match' | 'shorter' | 'longer';
    cultural_adaptation?: 'keep' | 'adapt';
};

export type SetupSettings = {
    ended: boolean;
};

type AdminSettingsMap = {
    'languages.list': Language[];
    'languages.switcher': SwitcherSettings;
    'languages.seo': SEOSettings;
    'translations.settings': TranslationsSettings;
    'translations.context': TranslationsContext;
    'translations.style': TranslationsStyle;
    'setup': SetupSettings;
    'advanced': AdvancedSettings;
};

export type AdminSettingKey = keyof AdminSettingsMap;
type SettingUpdater<T> = T | ((previous: T) => T);

type AdvancedSettings = {
    delete_on_uninstall: boolean;
};

const DEFAULT_SETTINGS: AdminSettingsMap = {
    'languages.list': [],
    'languages.switcher': {
        type: 'dropdown',
        trigger: 'name_flag',
        show_flag: true,
        native_names: true,
        regional_names: 'full',
        pending_message: '',
        anchor_fallback: 'floating',
        icon_style: 'rectangle',
        text_color: '#393535',
        background_color: '#FFFFFF',
        border_color: '#E2E2E4',
        background_style: 'solid',
        highlight_text: '#FFFFFF',
        highlight_bg: '#0e3ecd',
        offset: DEFAULT_SWITCHER_OFFSET,
        icon_size: DEFAULT_SWITCHER_ICON_SIZE,
        padding: DEFAULT_SWITCHER_PADDING,
    },
    'languages.seo': {
        url_structure: 'clean',
        force_lowercase: true,
        remember_last_choice: true,
        cookie_ttl: 30,
        redirect_after_switch: false,
        output_hreflang: true,
        include_x_default: true,
        sitemaps: true,
        exclude_disabled_languages: true,
        redirect_changed_slugs: '301',
        ai_crawlers: {
            preset_answer_engines: true,
            bots: {},
        },
    },
    'translations.settings': {
        translate_all_content: true,
        post_types: [],
        categories: [],
        auto_translate: true,
        translate_image_alt_tags: true,
        translate_comments: true,
        translate_menus: true,
        translate_widgets: true,
        translate_protected_pages: false,
        preserve_html_attributes: false,
        preserve_branded_terms: true,
        localized_date_time_formats: true,
        enable_units_conversion: true,
        own_providers_after_quota: false,
    },
    'translations.context': {
        last_scan_time: 0,
        files: [],
        pages: [],
        text_context: '',
    },
    'translations.style': {
        type: 'neutral',
        tone: 'friendly',
        formality: 5,
        creativity: 5,
        audience: '',
        length: 'match',
        cultural_adaptation: 'keep',
    },
    'setup': {
        ended: false,
    },
    'advanced': {
        delete_on_uninstall: false,
    },
};

const cloneValue = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
// An empty text and a missing key mean the same stored setting: a custom
// field cleared again must not count as a change.
const settled = (value: unknown): unknown =>
{
    if (Array.isArray(value)) return value.map(settled);
    if (typeof value === 'object' && value !== null) {
        return Object.fromEntries(
            Object.entries(value as Record<string, unknown>)
                .filter(([, item]) => item !== '' && item !== undefined && item !== null)
                .map(([key, item]) => [key, settled(item)])
        );
    }
    return value;
};

const areEqual = (left: unknown, right: unknown) => JSON.stringify(settled(left)) === JSON.stringify(settled(right));
const isPlainObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

const hasChangesIn = (settings: AdminSettingsMap, persistedSettings: AdminSettingsMap) =>
    (Object.keys(settings) as AdminSettingKey[]).some((key) => !areEqual(settings[key], persistedSettings[key]));

const normalizeLanguages = (value: unknown): Language[] =>
{
    if (Array.isArray(value)) {
        return value as Language[];
    }

    if (value && typeof value === 'object') {
        return Object.values(value as Record<string, Language>);
    }

    return cloneValue(DEFAULT_SETTINGS['languages.list']);
};

const normalizeNonNegativeInt = (value: unknown, defaultValue: number): number =>
{
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return defaultValue;
    }

    if (value < 0) {
        return 0;
    }

    return Math.trunc(value);
};

const normalizeSwitcherSettings = (value: unknown): SwitcherSettings =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['languages.switcher']);
    }

    const incomingSettings = value as Record<string, unknown>;
    const legacyHighlightColor = typeof incomingSettings.highlight_color === 'string'
        ? incomingSettings.highlight_color
        : undefined;

    return {
        ...cloneValue(DEFAULT_SETTINGS['languages.switcher']),
        ...(incomingSettings as Partial<SwitcherSettings>),
        highlight_bg: typeof incomingSettings.highlight_bg === 'string'
            ? incomingSettings.highlight_bg
            : legacyHighlightColor ?? DEFAULT_SETTINGS['languages.switcher'].highlight_bg,
        offset: normalizeNonNegativeInt(incomingSettings.offset, DEFAULT_SWITCHER_OFFSET),
        icon_size: normalizeNonNegativeInt(incomingSettings.icon_size, DEFAULT_SWITCHER_ICON_SIZE),
        padding: normalizeNonNegativeInt(incomingSettings.padding, DEFAULT_SWITCHER_PADDING),
        regional_names: incomingSettings.regional_names === 'short' ? 'short' : 'full',
        background_style: incomingSettings.background_style === 'transparent' || incomingSettings.background_style === 'glass' ? incomingSettings.background_style : 'solid',
        pending_message: typeof incomingSettings.pending_message === 'string' ? incomingSettings.pending_message : '',
        anchor_fallback: incomingSettings.anchor_fallback === 'none' ? 'none' : 'floating',
        trigger: incomingSettings.trigger === 'flag' || incomingSettings.trigger === 'icon' ? incomingSettings.trigger : 'name_flag',
    };
};

/**
 * PHP serialises an empty associative array as a JSON array (`[]`), so a stored
 * `ai_crawlers.bots` map, and each bot's per-language map, can reach the admin
 * as an array instead of an object. Coerce every such map back to an object so
 * the SEO panel always reads and writes a map.
 */
const normalizeCrawlerBots = (value: unknown): SEOSettings['ai_crawlers']['bots'] =>
{
    type Bots = SEOSettings['ai_crawlers']['bots'];

    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return {};
    }

    const bots: Bots = {};
    for (const [bot, settings] of Object.entries(value as Record<string, unknown>)) {
        if (!settings || typeof settings !== 'object' || Array.isArray(settings)) {
            continue;
        }

        const botSettings = settings as Record<string, unknown>;
        const languages = botSettings.languages;
        bots[bot] = {
            ...(botSettings as Bots[string]),
            languages: (languages && typeof languages === 'object' && !Array.isArray(languages) ? languages : {}) as Bots[string]['languages'],
        };
    }

    return bots;
};

const normalizeSeoSettings = (value: unknown): SEOSettings =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['languages.seo']);
    }

    const incomingSettings = value as Partial<SEOSettings>;
    const incomingCrawlers = incomingSettings.ai_crawlers && typeof incomingSettings.ai_crawlers === 'object'
        ? incomingSettings.ai_crawlers
        : undefined;

    return {
        ...cloneValue(DEFAULT_SETTINGS['languages.seo']),
        ...incomingSettings,
        ai_crawlers: {
            ...cloneValue(DEFAULT_SETTINGS['languages.seo'].ai_crawlers),
            ...incomingCrawlers,
            bots: normalizeCrawlerBots(incomingCrawlers?.bots),
        },
    };
};

const normalizeTranslationsSettings = (value: unknown): TranslationsSettings =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['translations.settings']);
    }

    const { taxonomies: _legacyTaxonomies, ...rest } = value as Record<string, unknown>;

    return {
        ...cloneValue(DEFAULT_SETTINGS['translations.settings']),
        ...rest,
    } as TranslationsSettings;
};

const normalizeTranslationsStyle = (value: unknown): TranslationsStyle =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['translations.style']);
    }

    const incoming = value as Partial<TranslationsStyle>;

    return {
        ...cloneValue(DEFAULT_SETTINGS['translations.style']),
        ...incoming,
        audience: typeof incoming.audience === 'string' ? incoming.audience : '',
        length: incoming.length === 'shorter' || incoming.length === 'longer' ? incoming.length : 'match',
        cultural_adaptation: incoming.cultural_adaptation === 'adapt' ? 'adapt' : 'keep',
    };
};

const normalizeTranslationsContext = (value: unknown): TranslationsContext =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['translations.context']);
    }

    return {
        ...cloneValue(DEFAULT_SETTINGS['translations.context']),
        ...(value as Partial<TranslationsContext>),
    };
};

const normalizeSetupSettings = (value: unknown): SetupSettings =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['setup']);
    }

    return {
        ...cloneValue(DEFAULT_SETTINGS['setup']),
        ...(value as Partial<SetupSettings>),
    };
};

const normalizeAdvancedSettings = (value: unknown): AdvancedSettings =>
{
    if (!value || typeof value !== 'object') {
        return cloneValue(DEFAULT_SETTINGS['advanced']);
    }

    return {
        ...cloneValue(DEFAULT_SETTINGS['advanced']),
        ...(value as Partial<AdvancedSettings>),
    };
};

// The model settings enter every translation fingerprint,
// so saving them marks every translation stale and the site is translated
// again on its next visits. Style and context are model settings as a whole;
// of the content settings only the branded-terms instruction is. Lexicon terms
// and the date, time and unit toggles are versions instead and never mark
// anything stale on save.
export const TRANSLATION_MODEL_KEYS: AdminSettingKey[] = ['translations.style', 'translations.context'];
export const TRANSLATION_MODEL_SETTING_FIELDS: (keyof TranslationsSettings)[] = ['preserve_branded_terms'];

/** The settings keys whose pending change marks every translation stale. */
export const translationAffectingChanges = (settings: AdminSettingsMap, persisted: AdminSettingsMap): AdminSettingKey[] =>
{
    const changed = TRANSLATION_MODEL_KEYS.filter(key => !areEqual(settings[key], persisted[key]));
    const content = settings['translations.settings'];
    const persistedContent = persisted['translations.settings'];
    if (TRANSLATION_MODEL_SETTING_FIELDS.some(field => content[field] !== persistedContent[field])) {
        changed.push('translations.settings');
    }
    return changed;
};

export interface SlugClash {
    locale: string;
    suggestedSlug: string;
}

interface AdminSettingsStore {
    isLoading: boolean;
    isSaving: boolean;
    isInitialized: boolean;
    hasChanges: boolean;
    saveFailed: boolean;
    saveError: string | null;
    slugClash: SlugClash | null;
    slugFocusLocale: string | null;
    acceptSuggestedSlug: () => Promise<void>;
    requestSlugFocus: () => void;
    clearSlugFocus: () => void;
    /** The settings revision this form was loaded from; sent with every save. */
    revision: number | null;
    /** Another tab saved since this form loaded; the last save was refused. */
    staleSettings: boolean;
    setRevision: (revision: number | null) => void;
    clearStale: () => void;
    /** Drop the draft and load the settings again from the site. */
    beginReload: () => void;
    // The keys this tab had edited when a reload began; they are laid over the
    // freshly loaded settings so the reload loses nothing typed here.
    pendingEdits: Partial<AdminSettingsMap>;
    settings: AdminSettingsMap;
    persistedSettings: AdminSettingsMap;
    setLoading: (isLoading: boolean) => void;
    initializeSettings: (settings: AdminSettingsMap) => void;
    initializeFromApiData: (data: {
        languagesList: unknown;
        switcher: unknown;
        seo?: unknown;
        translationsSettings: unknown;
        translationsContext: unknown;
        translationsStyle: unknown;
        setup: unknown;
        advanced: unknown;
    }) => void;
    setSetting: <K extends AdminSettingKey>(key: K, updater: SettingUpdater<AdminSettingsMap[K]>) => void;
    /** Puts one setting back to its saved value, leaving other unsaved changes alone. */
    revertKey: (key: AdminSettingKey) => void;
    /** When the last save succeeded, so the admin can say Saved. */
    savedAt: number | null;
    discardChanges: () => void;
    saveSettings: () => Promise<AdminSettingKey[]>;
}

const isSettingsChanged = (error: unknown): boolean =>
    typeof error === 'object' && error !== null && (error as { code?: unknown }).code === 'settings_changed';

const revisionFrom = (error: unknown): number | null =>
{
    const data = (error as { data?: { revision?: unknown } }).data;
    return typeof data?.revision === 'number' ? data.revision : null;
};

const REFUSAL_CODES = ['duplicate_language_slug', 'missing_default_language', 'settings_locked'];

const refusalMessage = (error: unknown): string | null =>
{
    const refusal = error as { code?: unknown; message?: unknown } | null;
    return refusal && typeof refusal.code === 'string' && REFUSAL_CODES.includes(refusal.code) && typeof refusal.message === 'string'
        ? refusal.message
        : null;
};

const slugClashFrom = (error: unknown): SlugClash | null =>
{
    const refusal = error as { code?: unknown; data?: { locale?: unknown; suggested_slug?: unknown } } | null;
    const data = refusal?.data;
    return refusal?.code === 'duplicate_language_slug' && typeof data?.locale === 'string' && typeof data?.suggested_slug === 'string'
        ? { locale: data.locale, suggestedSlug: data.suggested_slug }
        : null;
};

export const useAdminSettingsStore = create<AdminSettingsStore>((set, get) => ({
    isLoading: true,
    isSaving: false,
    revision: null,
    staleSettings: false,
    setRevision: (revision) => set({ revision }),
    clearStale: () => set({ staleSettings: false }),
    beginReload: () => set((state) =>
    {
        const pendingEdits: Partial<AdminSettingsMap> = {};
        for (const key of Object.keys(state.settings) as AdminSettingKey[]) {
            const edited = state.settings[key];
            const saved = state.persistedSettings[key];
            if (areEqual(edited, saved)) continue;
            (pendingEdits as Record<string, unknown>)[key] = isPlainObject(edited) && isPlainObject(saved)
                ? Object.fromEntries(Object.entries(edited).filter(([field, value]) => !areEqual(value, (saved as Record<string, unknown>)[field])))
                : cloneValue(edited);
        }

        return { isInitialized: false, staleSettings: false, hasChanges: false, saveFailed: false, pendingEdits };
    }),
    pendingEdits: {},
    isInitialized: false,
    hasChanges: false,
    saveFailed: false,
    saveError: null,
    savedAt: null,
    slugClash: null,
    slugFocusLocale: null,
    acceptSuggestedSlug: async () =>
    {
        const clash = get().slugClash;
        if (!clash) {
            return;
        }
        get().setSetting('languages.list', previous => previous.map(language =>
            language.locale === clash.locale ? { ...language, slug: clash.suggestedSlug } : language));
        await get().saveSettings();
    },
    requestSlugFocus: () => set(state => ({ slugFocusLocale: state.slugClash?.locale ?? null })),
    clearSlugFocus: () => set({ slugFocusLocale: null }),
    settings: cloneValue(DEFAULT_SETTINGS),
    persistedSettings: cloneValue(DEFAULT_SETTINGS),
    setLoading: (isLoading) => set({ isLoading }),
    initializeFromApiData: ({
        languagesList,
        switcher,
        seo,
        translationsSettings,
        translationsContext,
        translationsStyle,
        setup,
        advanced,
    }) =>
    {
        get().initializeSettings({
            'languages.list': normalizeLanguages(languagesList),
            'languages.switcher': normalizeSwitcherSettings(switcher),
            'languages.seo': normalizeSeoSettings(seo),
            'translations.settings': normalizeTranslationsSettings(translationsSettings),
            'translations.context': normalizeTranslationsContext(translationsContext),
            'translations.style': normalizeTranslationsStyle(translationsStyle),
            'setup': normalizeSetupSettings(setup),
            'advanced': normalizeAdvancedSettings(advanced),
        });
    },
    initializeSettings: (nextSettings) =>
        set((state) =>
        {
            if (state.isInitialized) {
                return state;
            }

            const clonedSettings = cloneValue(nextSettings);
            const settings = cloneValue(clonedSettings);
            for (const [key, edit] of Object.entries(state.pendingEdits) as [AdminSettingKey, unknown][]) {
                const loaded = settings[key];
                (settings as Record<string, unknown>)[key] = isPlainObject(edit) && isPlainObject(loaded)
                    ? { ...loaded, ...cloneValue(edit) }
                    : cloneValue(edit);
            }

            return {
                settings,
                persistedSettings: cloneValue(clonedSettings),
                hasChanges: hasChangesIn(settings, clonedSettings),
                pendingEdits: {},
                isInitialized: true,
                isLoading: false,
            };
        }),
    setSetting: (key, updater) =>
        set((state) =>
        {
            const previousValue = state.settings[key];
            const updated =
                typeof updater === 'function'
                    ? (updater as (value: typeof previousValue) => typeof previousValue)(previousValue)
                    : updater;
            const nextValue = key === 'translations.style'
                ? withToneFormality(previousValue as TranslationsStyle, updated as TranslationsStyle) as typeof previousValue
                : updated;

            if (areEqual(previousValue, nextValue)) {
                return state;
            }

            const nextSettings = {
                ...state.settings,
                [key]: nextValue,
            };

            return {
                settings: nextSettings,
                hasChanges: hasChangesIn(nextSettings, state.persistedSettings),
                slugClash: null,
            };
        }),
    revertKey: (key) =>
        set((state) =>
        {
            const settings = { ...state.settings, [key]: cloneValue(state.persistedSettings[key]) };
            return { settings, hasChanges: hasChangesIn(settings, state.persistedSettings) };
        }),
    discardChanges: () =>
        set((state) =>
        {
            if (state.isSaving) {
                return state;
            }

            return {
                settings: cloneValue(state.persistedSettings),
                hasChanges: false,
                saveFailed: false,
                slugClash: null,
            };
        }),
    saveSettings: async () =>
    {
        if (get().isSaving) {
            return [];
        }

        const currentState = get();
        const dirtyKeys = (Object.keys(currentState.settings) as AdminSettingKey[]).filter(
            key => !areEqual(currentState.settings[key], currentState.persistedSettings[key])
        );

        if (!dirtyKeys.length) {
            return [];
        }

        // What is sent is fixed here: an edit made while the save runs is
        // compared against what was sent, so it stays unsaved until it is sent.
        const sending = cloneValue(currentState.settings);

        set({ isSaving: true, saveFailed: false, saveError: null, slugClash: null, staleSettings: false });

        try {
            // Saves run one at a time: each carries the revision the previous
            // one returned, so a batch from this tab never trips its own guard.
            for (const key of dirtyKeys) {
                const revision = get().revision;
                const result = revision === null
                    ? await updateAdminData(key, sending[key])
                    : await updateAdminData(key, sending[key], revision);
                set((state) =>
                {
                    const persistedSettings = { ...state.persistedSettings, [key]: sending[key] };
                    return {
                        persistedSettings,
                        hasChanges: hasChangesIn(state.settings, persistedSettings),
                        revision: typeof result?.revision === 'number' ? result.revision : state.revision,
                    };
                });
            }

            set({ savedAt: Date.now() });

            return dirtyKeys;
        } catch (error) {
            if (isSettingsChanged(error)) {
                set({ staleSettings: true, revision: revisionFrom(error) });
                return [];
            }
            set({ saveFailed: true, saveError: refusalMessage(error), slugClash: slugClashFrom(error) });
            return [];
        } finally {
            set({ isSaving: false });
        }
    },
}));
