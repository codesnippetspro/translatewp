import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

describe('languages.seo store defaults', () =>
{
    it('defaults match the ai_crawlers / sitemaps contract', () =>
    {
        const seo = useAdminSettingsStore.getState().settings['languages.seo'];

        expect(seo.ai_crawlers).toEqual({ preset_answer_engines: true, bots: {} });
        expect(seo.sitemaps).toBe(true);    });
});

describe('languages.seo getter (initializeFromApiData default merge)', () =>
{
    it('merges a partial ai_crawlers-only payload over the defaults without dropping other fields', () =>
    {
        useAdminSettingsStore.getState().initializeFromApiData({
            languagesList: [],
            switcher: {},
            seo: {
                ai_crawlers: {
                    bots: {
                        GPTBot: { default: 'deny', languages: {} },
                    },
                },
            },
            translationsSettings: {},
            translationsContext: {},
            translationsStyle: {},
            setup: {},
            advanced: {},
        });

        const seo = useAdminSettingsStore.getState().settings['languages.seo'];

        expect(seo.ai_crawlers.preset_answer_engines).toBe(true);
        expect(seo.ai_crawlers.bots).toEqual({ GPTBot: { default: 'deny', languages: {} } });
        expect(seo.sitemaps).toBe(true);    });

    it('falls back to full defaults when no seo payload is provided at all', () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false } as never);

        useAdminSettingsStore.getState().initializeFromApiData({
            languagesList: [],
            switcher: {},
            translationsSettings: {},
            translationsContext: {},
            translationsStyle: {},
            setup: {},
            advanced: {},
        });

        const seo = useAdminSettingsStore.getState().settings['languages.seo'];

        expect(seo.ai_crawlers).toEqual({ preset_answer_engines: true, bots: {} });
        expect(seo.sitemaps).toBe(true);    });
});

describe('languages.seo getter coerces PHP empty-map serialisation to objects', () =>
{
    it('treats ai_crawlers.bots that arrives as an empty array as an empty map', () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false } as never);

        useAdminSettingsStore.getState().initializeFromApiData({
            languagesList: [],
            switcher: {},
            seo: { ai_crawlers: { preset_answer_engines: true, bots: [] } } as never,
            translationsSettings: {},
            translationsContext: {},
            translationsStyle: {},
            setup: {},
            advanced: {},
        });

        const seo = useAdminSettingsStore.getState().settings['languages.seo'];

        expect(seo.ai_crawlers.preset_answer_engines).toBe(true);
        expect(seo.ai_crawlers.bots).toEqual({});
        expect(Array.isArray(seo.ai_crawlers.bots)).toBe(false);
    });

    it('treats a bot per-language map that arrives as an empty array as an empty map', () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false } as never);

        useAdminSettingsStore.getState().initializeFromApiData({
            languagesList: [],
            switcher: {},
            seo: { ai_crawlers: { bots: { GPTBot: { default: 'deny', languages: [] } } } } as never,
            translationsSettings: {},
            translationsContext: {},
            translationsStyle: {},
            setup: {},
            advanced: {},
        });

        const seo = useAdminSettingsStore.getState().settings['languages.seo'];

        expect(seo.ai_crawlers.bots).toEqual({ GPTBot: { default: 'deny', languages: {} } });
        expect(Array.isArray(seo.ai_crawlers.bots.GPTBot.languages)).toBe(false);
    });
});
