import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { jest } from '@jest/globals';
import { addFilter, removeFilter } from '@wordpress/hooks';

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

let mockConnectStatus: { pro?: { entitled: boolean; active?: boolean } } | undefined;

jest.mock('@tanstack/react-query', () => ({
    useQuery: () => ({ data: mockConnectStatus, isLoading: false }),
}));

jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'] }),
}));

import { AiProviders } from '@/apps/admin/components/AiProviders';

const FILTER_NAMESPACE = 'translatewp-tests/ai-providers';
const RegisteredPanel = () => <div>Registered AI Models panel</div>;

describe('AiProviders slot', () =>
{
    afterEach(() =>
    {
        removeFilter('translatewp.slot.aiProviders', FILTER_NAMESPACE);
    });

    test('renders the page heading as "AI models" to match the Settings tab', () =>
    {
        mockConnectStatus = { pro: { entitled: false } };

        const html = renderToStaticMarkup(<AiProviders />);

        expect(html).toContain('>AI models</h2>');
    });

    test('renders the locked row with the Pro badge when nothing is registered', () =>
    {
        mockConnectStatus = { pro: { entitled: false } };

        const html = renderToStaticMarkup(<AiProviders />);

        expect(html).toContain('Unlock AI Models with PRO');
        expect(html).toContain('Pro');
        expect(html).toContain('OpenAI');
        expect(html).toContain('Anthropic');
        expect(html).toContain('Google Gemini');
        expect(html).toContain('Any OpenAI-compatible endpoint');
        expect(html).toContain('Your key is encrypted by WordPress and is never sent to visitor browsers.');
        expect(html).not.toContain('DeepL');
        expect(html).not.toContain('Registered AI Models panel');
    });

    test('says the TranslateWP model translates, and that Pro adds an own key billed by its provider', () =>
    {
        mockConnectStatus = { pro: { entitled: false } };

        const html = renderToStaticMarkup(<AiProviders />);

        expect(html).toContain('Translations use the TranslateWP model, included in your plan.');
        expect(html).toContain('billed by that provider');
        expect(html).not.toContain('Not connected');
    });

    test('renders the locked row when registered but the account is not entitled', () =>
    {
        addFilter('translatewp.slot.aiProviders', FILTER_NAMESPACE, () => RegisteredPanel);
        mockConnectStatus = { pro: { entitled: false } };

        const html = renderToStaticMarkup(<AiProviders />);

        expect(html).toContain('Unlock AI Models with PRO');
        expect(html).not.toContain('Registered AI Models panel');
    });

    test('an entitled account with nothing registered sees the addon-update hint without a lock', () =>
    {
        mockConnectStatus = { pro: { entitled: true, active: true } };

        const html = renderToStaticMarkup(<AiProviders />);

        expect(html).toContain('does not include AI Models yet');
        expect(html).not.toContain('once this account is on Pro');
    });

    test('renders the registered Pro panel once the account is entitled', () =>
    {
        addFilter('translatewp.slot.aiProviders', FILTER_NAMESPACE, () => RegisteredPanel);
        mockConnectStatus = { pro: { entitled: true } };

        const html = renderToStaticMarkup(<AiProviders />);

        expect(html).toContain('Registered AI Models panel');
        expect(html).not.toContain('Unlock AI Models with PRO');
    });
});
