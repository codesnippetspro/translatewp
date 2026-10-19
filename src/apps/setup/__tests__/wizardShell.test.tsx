import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ConnectStep } from '@/apps/setup/components/connectStep';
import { Setup, WIZARD_BODY_CLASS, WIZARD_CARD_CLASS, WIZARD_SHELL_CLASS } from '@/apps/setup';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { useStepStore } from '@/apps/setup/stores/stepStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/components/connection/ConnectionPanel', () => ({ ConnectionPanel: () => <div>panel</div> }));

const mockGetConnectStatus = jest.fn();
const mockGetSetupTranslationProgress = jest.fn();

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
}));

jest.mock('@/shared/api/setupTranslationProgress', () => ({
    getSetupTranslationProgress: (...args: unknown[]) => mockGetSetupTranslationProgress(...args),
}));

describe('wizard shell', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it('the connect step says what happens next in three numbered steps, without the service pitch', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: false, siteId: '', accountLabel: '', portalSitesUrl: '' });
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () =>
        {
            createRoot(container).render(
                <QueryClientProvider client={new QueryClient()}><ConnectStep /></QueryClientProvider>
            );
        });

        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        const text = container.textContent ?? '';
        expect(container.querySelectorAll('[data-connect-steps] li')).toHaveLength(3);
        expect(text).toContain('Approve and connect this site to your account');
        expect(container.querySelector('[data-connect-steps] li[aria-current="step"]')).not.toBeNull();
        expect(text).not.toContain('fast, consistent translations');
        expect(text).not.toContain('Not paired');
        expect(text).not.toContain('Connect this site to your TranslateWP account');
    });

    it('the card keeps one height on every step and a tall step flexes to fill it', () =>
    {
        expect(WIZARD_CARD_CLASS).toMatch(/(^|\s)h-\[\d+px\](\s|$)/);
        expect(WIZARD_CARD_CLASS).toContain('shrink-0');
        expect(WIZARD_CARD_CLASS).not.toContain('max-h-[calc(100vh-4rem)]');
        expect(WIZARD_CARD_CLASS).toContain('flex-col');
        expect(WIZARD_CARD_CLASS).toContain('overflow-hidden');
        expect(WIZARD_BODY_CLASS).toContain('flex-1');
        expect(WIZARD_BODY_CLASS).toContain('min-h-0');
        expect(WIZARD_BODY_CLASS).toContain('flex-col');
        expect(WIZARD_BODY_CLASS).not.toContain('overflow-y-auto');
    });

    it('the wizard shell grows with its card and scrolls instead of cutting a tall step', () =>
    {
        expect(WIZARD_SHELL_CLASS).toContain('min-h-screen');
        expect(WIZARD_SHELL_CLASS).toContain('overflow-y-auto');
        expect(WIZARD_SHELL_CLASS).not.toMatch(/(^|\s)h-screen(\s|$)/);
    });

    it('keeps Open Dashboard enabled while spine translations are still pending', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: '', portalSitesUrl: '' });
        mockGetSetupTranslationProgress.mockResolvedValue({
            status: { languages: { fr: { complete: false } } },
            pages: {
                pages: [{
                    objectType: 'page', objectId: 1, url: 'https://example.com/', title: 'Home',
                    languages: {
                        fr: { id: 1, status: 'pending', queueStatus: 'claimed', segments: 0, reason: '', updated: 1, title: 'pending' },
                    },
                }],
                totals: { rows: 1, segments: 0 },
            },
        });
        (window as { setupData?: unknown }).setupData = { defaultLanguage: 'en_US', homeUrl: 'https://example.com/' };
        useStepStore.setState({ step: 'translation' });
        useSetupStore.setState({
            defaultLanguage: 'en-us', languages: ['fr'], autoTranslate: true,
            context: '', isScanning: false, siteRead: true,
        });

        const container = document.createElement('div');
        document.body.appendChild(container);
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        await act(async () =>
        {
            createRoot(container).render(<QueryClientProvider client={client}><Setup /></QueryClientProvider>);
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        const finish = container.querySelector('[data-finish-setup]') as HTMLButtonElement;
        expect(finish).not.toBeNull();
        expect(finish.textContent).toBe('Open Dashboard');
        expect(finish.disabled).toBe(false);
        expect(container.querySelector('[data-finish-callout] [data-finish-setup]')).not.toBeNull();
        expect(container.querySelector('iframe')).toBeNull();
        expect(container.querySelector('[data-homepage-link="fr"]')?.getAttribute('data-link-state')).toBe('translating');
        delete (window as { setupData?: unknown }).setupData;
        expect(container.querySelector('[aria-label="French: translating"]')).not.toBeNull();
    });
});
