import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { QuotaCard, quotaLevel } from '@/apps/admin/components/Dashboard/QuotaCard';
import { getQuota, getQuotaQueryOptions, type Quota } from '@/shared/api/quota';

jest.mock('@wordpress/api-fetch', () => jest.fn());

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

const renderWith = (quota?: Quota) =>
{
    const client = new QueryClient();
    if (quota)
    {
        client.setQueryData(getQuotaQueryOptions().queryKey, quota);
    }

    return renderToStaticMarkup(
        <QueryClientProvider client={client}>
            <QuotaCard />
        </QueryClientProvider>
    );
};

describe('the quota bar colour', () =>
{
    it.each([
        [0, 'critical'], [4, 'critical'], [5, 'low'], [24, 'low'], [25, 'fair'], [64, 'fair'], [65, 'ample'], [100, 'ample'],
    ])('%d%% available is %s', (percent, level) =>
    {
        expect(quotaLevel(percent)).toBe(level);
    });

    it('renders the bar at the available width with its level', () =>
    {
        const html = renderWith({ percentAvailable: 42, windowEndsAt: null, enforced: true });
        expect(html).toContain('data-quota-level="fair"');
        expect(html).toContain('width:42%');
        expect(html).toContain('Translations quota');
        expect(html).not.toContain('Fair-use');
    });
});

describe('QuotaCard', () =>
{
    test('reads 100% available while enforcement is off', () =>
    {
        const html = renderWith({ percentAvailable: 100, windowEndsAt: null, enforced: false });

        expect(html).toContain('100% available');
        expect(html).not.toMatch(/token/i);
    });

    test('shows the account percentage when enforcement is on', () =>
    {
        const html = renderWith({ percentAvailable: 42, windowEndsAt: '2026-10-21T00:00:00.000Z', enforced: true });

        expect(html).toContain('42% available');
        expect(html).not.toContain('100% available');
    });

    test('shows no percentage before the answer is in', () =>
    {
        expect(renderWith()).not.toMatch(/% available/);
    });

    test('reads the percentage from the plugin route', async () =>
    {
        const quota: Quota = { percentAvailable: 100, windowEndsAt: null, enforced: false };
        jest.mocked(apiFetch).mockResolvedValueOnce(quota);

        await expect(getQuota()).resolves.toEqual(quota);
        expect(apiFetch).toHaveBeenCalledWith({ path: '/translatewp/v1/admin/quota', method: 'GET' });
    });

    test('keeps the percentage for a minute and does not refetch on focus', () =>
    {
        const options = getQuotaQueryOptions();

        expect(options.staleTime).toBeGreaterThanOrEqual(60_000);
        expect(options.refetchOnWindowFocus).toBe(false);
    });
});
