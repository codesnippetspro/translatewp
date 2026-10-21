import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { jest } from '@jest/globals';

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

type MockPro = { plan: string; entitled: boolean; installed: boolean; active: boolean; installedVersion: string | null; latestVersion: string | null; packagePending: boolean; updateAvailable: boolean; updateUrl: string | null };
let mockConnectStatus: { connected: boolean; siteId: string | null; accountLabel: string; portalSitesUrl: string; portalSiteUrl?: string; pro?: MockPro } | undefined = undefined;

jest.mock('@tanstack/react-query', () => ({
    useQuery: () => ({ data: mockConnectStatus, isLoading: false }),
    useMutation: () => ({ isPending: false, mutate: () => {} }),
    useQueryClient: () => ({ invalidateQueries: () => Promise.resolve() }),
}));

jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'] }),
}));

jest.mock('@/shared/mutation-options/connect', () => ({
    disconnectSiteMutationOptions: () => ({ mutationFn: () => Promise.resolve() }),
    installProMutationOptions: () => ({ mutationFn: () => Promise.resolve() }),
    activateProMutationOptions: () => ({ mutationFn: () => Promise.resolve() }),
}));

jest.mock('@wordpress/components', () => ({
    Button: (props: Record<string, unknown>) => props.children ?? null,
    TextControl: () => null,
}));

import { ConnectionStatusText } from '@/shared/components/connection/ConnectionStatusText';
import { ConnectionPanel } from '@/shared/components/connection/ConnectionPanel';

function countOccurrences(haystack: string, needle: string): number
{
    let count = 0;
    let idx = haystack.indexOf(needle);
    while (idx !== -1) { count++; idx = haystack.indexOf(needle, idx + needle.length); }
    return count;
}

describe('ConnectionStatusText', () =>
{
    test('labeled pair renders escaped label, never a script element', () =>
    {
        mockConnectStatus = { connected: true, siteId: 'site-1', accountLabel: '<script>alert(1)</script>', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionStatusText />);
        expect(html).toContain('Connected as');
        expect(html).toContain('&lt;script&gt;');
        expect(html).not.toContain('<script>');
    });

    test('labeled pair links its portal email chip to the current site in a new tab', () =>
    {
        mockConnectStatus = { connected: true, siteId: 'site-1', accountLabel: 'owner@example.com', portalSitesUrl: '', portalSiteUrl: 'https://portal.example/sites/site-1' };
        const html = renderToStaticMarkup(<ConnectionStatusText />);
        expect(html).toContain('href="https://portal.example/sites/site-1"');
        expect(html).toContain('target="_blank"');
        expect(html).toContain('rounded-full');
        expect(html).toContain('owner@example.com');
    });

    test('unlabeled/manual pair renders connected copy without a label', () =>
    {
        mockConnectStatus = { connected: true, siteId: 'site-2', accountLabel: '', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionStatusText />);
        expect(html).toContain('This site is connected');
        expect(html).not.toContain('Connected as');
    });

    test('a site that is not connected says so', () =>
    {
        mockConnectStatus = { connected: false, siteId: null, accountLabel: '', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionStatusText />);
        expect(html).toContain('Not connected');
    });
});

describe('ConnectionPanel status composition', () =>
{
    test('labeled connected panel shows status exactly once', () =>
    {
        mockConnectStatus = { connected: true, siteId: 'site-1', accountLabel: 'owner@example.com', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionPanel />);
        expect(countOccurrences(html, 'Connected as')).toBe(1);
    });

    test('unlabeled connected panel shows status exactly once', () =>
    {
        mockConnectStatus = { connected: true, siteId: 'site-2', accountLabel: '', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionPanel />);
        expect(countOccurrences(html, 'This site is connected')).toBe(1);
    });

    test('an unconnected panel says Not connected exactly once', () =>
    {
        mockConnectStatus = { connected: false, siteId: null, accountLabel: '', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionPanel />);
        expect(countOccurrences(html, 'Not connected')).toBe(1);
    });

    test('unpaired panel invites the connect without naming the mechanisms', () =>
    {
        mockConnectStatus = { connected: false, siteId: null, accountLabel: '', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionPanel />);
        expect(html).toContain('Connect this site to your TranslateWP account to enable translation.');
        expect(html).not.toContain('usage reporting');
        expect(html).not.toContain('cache warming');
    });

    test('unpaired panel offers only the Connect button, no manual paste form', () =>
    {
        mockConnectStatus = { connected: false, siteId: null, accountLabel: '', portalSitesUrl: '' };
        const html = renderToStaticMarkup(<ConnectionPanel />);
        expect(html).toContain('Connect site');
        expect(html).not.toContain('Advanced connection settings');
        expect(html).not.toContain('Paste connection details');
        expect(html).not.toContain('Site token');
    });
});
