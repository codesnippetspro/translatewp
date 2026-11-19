import { webcrypto } from 'crypto';
import { TextEncoder } from 'util';

import golden from '../../../../../tests/fixtures/translation-fingerprint-golden.json';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { Translator } from '@/shared/lib/translations/Translator';
import { splitSeparatorEdges } from '@/shared/lib/translations/translationWhitespace';

Object.defineProperty(globalThis, 'TextEncoder', { value: TextEncoder, configurable: true });
Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });

const BREADCRUMB = '<nav class="woocommerce-breadcrumb" aria-label="Breadcrumb">'
    + '<a href="/">Home</a>&nbsp;&#47;&nbsp;<a href="/product-category/uncategorized/">Uncategorized</a>'
    + '&nbsp;&#47;&nbsp;Garden Lantern</nav>';

const settings = golden.vectors.find(vector => vector.id === 'basic-proxy-fixture')!.settings;

async function breadcrumbPlan()
{
    document.body.innerHTML = BREADCRUMB;
    const entries = new DOMExtractor().extract(document.body, { includeAlt: false, includeAttributes: false });
    const client = new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'it', siteId: 'site-1', apiBaseUrl: 'https://api.example.test',
        aiSettings: settings,
    });

    return client.prepare(entries);
}

describe('separator edges', () =>
{
    it.each([
        ['\u00a0/\u00a0Garden Lantern', ['\u00a0/\u00a0', 'Garden Lantern', '']],
        ['Home / ', ['', 'Home', ' / ']],
        [' › Accessories', [' › ', 'Accessories', '']],
        ['Next »', ['', 'Next »', '']],
        ['Shop / Accessories', ['', 'Shop / Accessories', '']],
        ['\n\t\tDescription\t\t', ['', '\n\t\tDescription\t\t', '']],
        [' / 25', ['', ' / 25', '']],
    ])('splits %j at its separator edges only', (value, [prefix, core, suffix]) =>
    {
        expect(splitSeparatorEdges(value)).toEqual({ prefix, core, suffix });
    });

    it('sends a WooCommerce breadcrumb as its crumb words, without the separator glued to the last crumb', async () =>
    {
        const plan = await breadcrumbPlan();

        expect(plan.groupsForCache.map(group => group.markerPayload)).toEqual([
            '⟪item_0001⟫Home⟪item_0002⟫Uncategorized⟪item_0003⟫Garden Lantern',
        ]);
    });

    it('writes the translated last crumb back behind its separator', async () =>
    {
        const plan = await breadcrumbPlan();
        const results = new Map<string, string>();

        new Translator({ targetLang: 'it' })._applyGroupTranslation(
            plan.groupsForCache[0],
            '⟪item_0001⟫Home⟪item_0002⟫Senza categoria⟪item_0003⟫Lanterna da giardino',
            results,
            {},
        );

        expect([...results.values()]).toEqual(['Home', 'Senza categoria', '\u00a0/\u00a0Lanterna da giardino']);
    });
});
