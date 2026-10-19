import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { SetupTranslationProgress } from '@/shared/api/setupTranslationProgress';
import { HomepageLinks } from '@/apps/setup/components/translationStep/HomepageLinks';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockProgress = jest.fn();
jest.mock('@/shared/api/setupTranslationProgress', () => ({
    getSetupTranslationProgress: (...args: unknown[]) => mockProgress(...args),
}));

const HOME = 'https://example.com/';

const row = (status: 'pending' | 'indexed') => ({
    id: 1, status, queueStatus: status === 'indexed' ? 'done' as const : 'pending' as const,
    segments: status === 'indexed' ? 4 : 0, reason: '', updated: 1, title: 'pending' as const,
});

const progress = (home: Record<string, 'pending' | 'indexed'>): SetupTranslationProgress => ({
    status: { languages: {} },
    pages: {
        pages: [
            { objectType: 'page', objectId: 1, url: HOME, title: 'Home', languages: Object.fromEntries(Object.entries(home).map(([locale, status]) => [locale, row(status)])) },
        ],
        totals: { rows: 2, segments: 4 },
    },
});

let root: Root | null = null;

const render = async (data: SetupTranslationProgress, languages: string[]): Promise<HTMLElement> =>
{
    mockProgress.mockResolvedValue(data);
    useSetupStore.setState({ defaultLanguage: 'en-us', languages: ['en-us', ...languages] });
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root?.render(
            <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                <HomepageLinks />
            </QueryClientProvider>
        );
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

describe('the homepage links on the last setup step', () =>
{
    beforeEach(() =>
    {
        (window as { setupData?: unknown }).setupData = { defaultLanguage: 'en_US', homeUrl: HOME };
    });

    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
        delete (window as { setupData?: unknown }).setupData;
    });

    it('links a ready language to its translated homepage in a new tab and keeps a pending one inert', async () =>
    {
        const container = await render(progress({ es: 'indexed', de: 'pending' }), ['es', 'de']);

        const ready = container.querySelector('a[data-homepage-link="es"]') as HTMLAnchorElement;
        expect(ready.getAttribute('target')).toBe('_blank');
        expect(ready.href).toContain('/es');
        expect(ready.href).not.toContain('?');
        expect(ready.textContent).not.toContain('Spanish');
        expect(ready.getAttribute('aria-label')).toBe('See your homepage in Spanish');
        expect(ready.className).toContain('is-ready');

        const pending = container.querySelector('[data-homepage-link="de"]');
        expect(pending?.tagName).toBe('SPAN');
        expect(pending?.getAttribute('data-link-state')).toBe('translating');
    });

    it('shows every language as a flag, in the order they were chosen', async () =>
    {
        const container = await render(progress({ es: 'indexed', de: 'indexed', fr: 'indexed', it: 'indexed' }), ['es', 'de', 'fr', 'it']);

        const shown = Array.from(container.querySelectorAll('[data-homepage-link]')).map(item => item.getAttribute('data-homepage-link'));
        expect(shown).toEqual(['es', 'de', 'fr', 'it']);
        for (const item of container.querySelectorAll('[data-homepage-link]')) {
            expect(item.querySelector('.setup-homepage-flag-inner')).not.toBeNull();
            expect(item.textContent?.trim()).toBe('');
        }
    });

    it('a pending language carries its own percent on the ring and turns', async () =>
    {
        const container = await render(progress({ es: 'indexed', de: 'pending' }), ['es', 'de']);
        const pending = container.querySelector('[data-homepage-link="de"]') as HTMLElement;
        expect(pending.className).toContain('is-running');
        expect(pending.style.getPropertyValue('--twp-ring-percent')).toBe('0%');
        expect(pending.getAttribute('aria-label')).toBe('German: translating, 0% ready');
    });

    it('renders nothing when no translation language is chosen', async () =>
    {
        const container = await render(progress({}), []);

        expect(container.querySelector('[data-homepage-links]')).toBeNull();
    });
});
