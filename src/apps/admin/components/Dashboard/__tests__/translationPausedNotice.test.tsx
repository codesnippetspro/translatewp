import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { TranslationPausedNotice } from '@/apps/admin/components/Dashboard/TranslationPausedNotice';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = { getJobEvents: jest.fn() };

jest.mock('@/shared/api/admin', () => ({
    getJobEvents: (...args: unknown[]) => mockApi.getJobEvents(...args),
}));

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <TranslationPausedNotice />
            </QueryClientProvider>
        );
    });
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

describe('the Overview says when translation is paused', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
        delete (window as { translatewpPro?: unknown }).translatewpPro;
    });

    it('shows the paused notice with the upgrade link when the latest event is a quota block', async () =>
    {
        (window as { translatewpPro?: unknown }).translatewpPro = { upgradeUrl: 'https://portal.example/upgrade' };
        mockApi.getJobEvents.mockResolvedValue({ events: [{ id: '1', siteId: 's', accountId: 'a', kind: 'quota_block', occurredAt: '2026-09-14T12:00:00.000Z' }] });
        const container = await render();
        expect(container.textContent).toContain('Translation is paused');
        expect(container.textContent).toContain('resumes on its own');
        expect(container.querySelector('a')?.getAttribute('href')).toBe('https://portal.example/upgrade');
    });

    it('shows nothing when the latest event is something else or there is none', async () =>
    {
        mockApi.getJobEvents.mockResolvedValue({ events: [{ id: '1', siteId: 's', accountId: 'a', kind: 'provider_error', occurredAt: '2026-09-14T12:00:00.000Z' }] });
        expect((await render()).textContent).toBe('');
        document.body.innerHTML = '';
        mockApi.getJobEvents.mockResolvedValue({ events: [] });
        expect((await render()).textContent).toBe('');
    });
});
