import { createStore } from 'zustand';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const loadSetupStore = async () =>
{
    jest.resetModules();
    const module = await import('@/apps/setup/stores/setupStore');
    return module.useSetupStore;
};

describe('setupStore server/localStorage precedence', () =>
{
    beforeEach(() =>
    {
        localStorage.clear();
        delete (window as { setupData?: unknown }).setupData;
    });

    afterEach(() =>
    {
        localStorage.clear();
        delete (window as { setupData?: unknown }).setupData;
    });

    it('server setupData wins over a stale localStorage draft', async () =>
    {
        (window as { setupData?: unknown }).setupData = {
            defaultLanguage: 'es-es',
            languages: ['es-es', 'en-us'],
            autoTranslate: false,
            context: 'marketing',
            step: 'translation',
        };
        localStorage.setItem(`translatewp-setup:${window.location.pathname}`, JSON.stringify({
            state: {
                defaultLanguage: 'fr-fr',
                languages: ['fr-fr'],
                autoTranslate: true,
                context: 'stale-draft',
            },
            version: 0,
        }));

        const useSetupStore = await loadSetupStore();
        const state = useSetupStore.getState();

        expect(state.defaultLanguage).toBe('es-es');
        expect(state.languages).toEqual(['es-es', 'en-us']);
        expect(state.autoTranslate).toBe(false);
        expect(state.context).toBe('marketing');
    });

    it('a site the server reports unread starts unread even when the browser remembers a read', async () =>
    {
        (window as { setupData?: unknown }).setupData = {
            defaultLanguage: 'de-de',
            languages: ['de-de'],
            autoTranslate: true,
            context: '',
            step: 'context',
            siteRead: false,
        };
        localStorage.setItem(`translatewp-setup:${window.location.pathname}`, JSON.stringify({
            state: { defaultLanguage: 'de-de', languages: ['de-de'], autoTranslate: true, context: '', siteRead: true },
            version: 0,
        }));

        const useSetupStore = await loadSetupStore();
        expect(useSetupStore.getState().siteRead).toBe(false);

        useSetupStore.getState().setSiteRead(true);
        const stored = JSON.parse(localStorage.getItem(`translatewp-setup:${window.location.pathname}`) ?? '{}') as { state?: Record<string, unknown> };
        expect(stored.state).toBeDefined();
        expect('siteRead' in (stored.state ?? {})).toBe(false);
    });

    it('a stale browser read flag never satisfies the read gate when no server setup data exists', async () =>
    {
        localStorage.setItem(`translatewp-setup:${window.location.pathname}`, JSON.stringify({
            state: { defaultLanguage: 'fr-fr', languages: ['fr-fr'], autoTranslate: true, context: 'draft', siteRead: true },
            version: 0,
        }));

        const useSetupStore = await loadSetupStore();
        expect(useSetupStore.getState().siteRead).toBe(false);
        expect(useSetupStore.getState().context).toBe('draft');
    });

    it('localStorage draft is used when no server setup data exists', async () =>
    {
        localStorage.setItem(`translatewp-setup:${window.location.pathname}`, JSON.stringify({
            state: {
                defaultLanguage: 'fr-fr',
                languages: ['fr-fr'],
                autoTranslate: true,
                context: 'draft-only',
            },
            version: 0,
        }));

        const useSetupStore = await loadSetupStore();
        const state = useSetupStore.getState();

        expect(state.defaultLanguage).toBe('fr');
        expect(state.languages).toEqual(['fr-fr']);
        expect(state.autoTranslate).toBe(true);
        expect(state.context).toBe('draft-only');
    });
});
