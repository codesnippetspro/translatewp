import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { DashboardMetricsContent } from '@/apps/admin/components/Dashboard';
import type { DashboardMetrics } from '@/shared/api/admin';
import { getQuotaQueryOptions } from '@/shared/api/quota';

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

const EMPTY_TRANSLATIONS = 'No translation data yet.';
const EMPTY_CONTENT = 'No translated content yet.';
const EMPTY_LEXICON = 'No lexicon entries yet.';

const populatedMetrics: DashboardMetrics = {
    tokenTrend: [
        { period: 'Sep 10', totalTokens: 2100000, cachedHits: 120 },
        { period: 'Sep 11', totalTokens: 5300000, cachedHits: 240 },
    ],
    tokensToday: 5300000,
    tokensThisMonth: 7400000,
    translationsToday: 432,
    cacheHitsToday: 240,
    cacheHitRatio: 36,
    tokensVsYesterday: 152.4,
    languageUsage: [
        { locale: 'de', name: 'German', translations: 210, share: 49, barColor: '#0e3ecd' },
        { locale: 'fr', name: 'French', translations: 222, share: 51, barColor: '#E08E3C' },
    ],
    recentContent: [
        { page: 'Welcome home', language: 'German', href: 'https://example.com/?lang=de' },
        { page: 'Pricing', language: 'French', href: 'https://example.com/pricing?lang=fr' },
    ],
    recentLexicon: [
        { term: 'Checkout', added: 'Today', href: '#' },
    ],
};

const zeroMetrics: DashboardMetrics = {
    tokenTrend: [],
    tokensToday: 0,
    tokensThisMonth: 0,
    translationsToday: 0,
    cacheHitsToday: 0,
    cacheHitRatio: 0,
    tokensVsYesterday: 0,
    languageUsage: [],
    recentContent: [],
    recentLexicon: [],
};

describe('Dashboard widgets', () =>
{
    test('render real numbers and suppress the empty states when there is usage', () =>
    {
        const html = renderToStaticMarkup(<DashboardMetricsContent data={populatedMetrics} />);

        expect(html).toContain('432');
        expect(html).toContain('Page translations today');
        expect(html).toContain('German');
        expect(html).toContain('French');
        expect(html).toContain('Welcome home');
        // A language saved without a stored name still shows its name, not its code.
        const codesOnly = renderToStaticMarkup(<DashboardMetricsContent data={{
            ...populatedMetrics,
            languageUsage: [{ locale: 'pt-br', name: 'pt-br', translations: 3, share: 100, barColor: '#000' }],
            recentContent: [{ page: 'Home', locale: 'es-419', language: 'ES-419', href: '#' }],
        }} />);
        expect(codesOnly).toContain('Brazilian Portuguese');
        expect(codesOnly).toContain('Latin American Spanish');
        expect(codesOnly).not.toContain('>pt-br<');
        expect(codesOnly).not.toContain('ES-419');
        expect(html).toContain('Pricing');
        expect(html).toContain('Checkout');

        expect(html).not.toContain(EMPTY_TRANSLATIONS);
        expect(html).not.toContain(EMPTY_CONTENT);
        expect(html).not.toContain(EMPTY_LEXICON);
        expect(html).toContain('finished since midnight, one per page and language');
        expect(html).toContain('240 pieces of already translated text');
        expect((html.match(/data-info-tip/g) ?? []).length).toBe(3);
    });

    test('links the Recent Lexicon Entries "View all" to the Lexicon page', () =>
    {
        const html = renderToStaticMarkup(<DashboardMetricsContent data={populatedMetrics} />);

        expect(html).toContain('href="admin.php?page=translatewp-lexicon" class="text-sm text-translatewp-primary underline">View all</a>');
    });

    test('show the empty states only when every number is genuinely zero', () =>
    {
        const html = renderToStaticMarkup(<DashboardMetricsContent data={zeroMetrics} />);

        expect(html).toContain(EMPTY_TRANSLATIONS);
        expect(html).toContain(EMPTY_CONTENT);
        expect(html).toContain(EMPTY_LEXICON);
        expect(html).toContain('admin.php?page=translatewp-lexicon#add');
        expect(html).toContain('Add your first term');
        expect(html).toContain('Lexicon entries');
        expect(html).not.toContain('Recent lexicon entries');

        // The numeric cards still render a genuine zero rather than being hidden.
        expect(html).toContain('Page translations today');
        expect(html).toContain('>0<');
    });

    test('show no token figure', () =>
    {
        const html = renderToStaticMarkup(<DashboardMetricsContent data={populatedMetrics} />);

        expect(html).not.toMatch(/token/i);
        expect(html).not.toContain('5,300,000');
        expect(html).not.toContain('7,400,000');
        expect(html).not.toContain('Burn rate');
    });

    test('show the account percentage on a connected site', () =>
    {
        const client = new QueryClient();
        client.setQueryData(getQuotaQueryOptions().queryKey, { percentAvailable: 100, windowEndsAt: null, enforced: false });

        const html = renderToStaticMarkup(
            <QueryClientProvider client={client}>
                <DashboardMetricsContent data={populatedMetrics} connected />
            </QueryClientProvider>
        );

        expect(html).toContain('100% available');
        expect(html).toMatch(/available to your account/i);
        expect(html).not.toMatch(/token/i);
    });

    test('leave the percentage out on a site that is not connected', () =>
    {
        const html = renderToStaticMarkup(<DashboardMetricsContent data={populatedMetrics} />);

        expect(html).not.toContain('% available');
        expect(html).not.toMatch(/available to your account/i);
    });
});
