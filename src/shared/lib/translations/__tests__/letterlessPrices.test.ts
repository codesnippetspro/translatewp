import { createHash, webcrypto } from 'crypto';
import { TextEncoder } from 'util';

import vectors from '../../../../../tests/fixtures/translation-letter-eligibility.json';
import { hasTranslationLetters } from '@/shared/lib/translations/hasTranslationLetters';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { Translator } from '@/shared/lib/translations/Translator';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { EntryType } from '@/shared/lib/translations/constants';
import golden from '../../../../../tests/fixtures/translation-fingerprint-golden.json';
import { readFileSync } from 'fs';
import path from 'path';

Object.defineProperty(globalThis, 'TextEncoder', { value: TextEncoder, configurable: true });
Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });

it('pins the shared browser, PHP and service letter eligibility table', () =>
{
    const bytes = readFileSync(path.resolve(__dirname, '../../../../../tests/fixtures/translation-letter-eligibility.json'));
    expect(createHash('sha256').update(bytes).digest('hex')).toBe('ecadadc728aa1f1c360708e648636f4da1960885b0377b4e17378171f14268dc');
});

it.each(vectors)('extracts $text only when it contains a Unicode letter', ({ text, hasLetters }) =>
{
    expect(hasTranslationLetters(text)).toBe(hasLetters);
    document.body.innerHTML = '<p></p>';
    document.querySelector('p')!.textContent = text;
    expect(new DOMExtractor().extract(document.body).map(entry => entry.value)).toEqual(hasLetters ? [text] : []);
});

it('excludes prices before cache planning even for entries supplied without the extractor', async () =>
{
    const entries = vectors.map(({ text }, index) => new TranslatableEntry({
        id: `entry_${index}`, type: EntryType.TEXT, node: document.createTextNode(text),
        attributeName: null, value: text,
    }));
    const settings = golden.vectors.find(vector => vector.id === 'basic-proxy-fixture')!.settings;
    const client = new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'it', siteId: 'site-1', apiBaseUrl: 'https://api.example.test',
        aiSettings: settings,
    });
    const plan = await client.prepare(entries);
    const sources = plan.groupsForCache.flatMap(group => group.canonicalEntries.map(entry => entry.value));
    expect(sources.sort()).toEqual(vectors.filter(vector => vector.hasLetters).map(vector => vector.text).sort());
    const prices = await client.prepare(entries.filter(entry => !hasTranslationLetters(entry.value)));
    expect(prices).toEqual({ groups: [], groupsForCache: [] });
});

it.each(['it', 'es', 'lv'])('ignores an old corrupted cached price in %s while applying mixed text', language =>
{
    const texts = [' 14,50', '14,50 per month'];
    const entries = texts.map((value, index) => new TranslatableEntry({
        id: `entry_${index}`, type: EntryType.TEXT, node: document.createTextNode(value), attributeName: null, value,
    }));
    const canonicalEntries = entries.map((entry, index) => new TranslatableEntry({
        ...entry, id: `item_000${index + 1}`,
    }));
    const group = { id: 'group', kind: 'text-flow' as const, contextKey: 'p', entries, markerPayload: '' };
    const results = new Map<string, string>();
    const onEntryTranslated = jest.fn();
    new Translator({ targetLang: language })._applyGroupTranslation(
        { group, canonicalEntries, hash: 'old-price', siteId: 'site-1', markerPayload: '' },
        '⟪item_0001⟫ 1.450⟪item_0002⟫14,50 al mese', results, { onEntryTranslated },
    );
    expect(results.get('entry_0')).toBe(' 14,50');
    expect(results.get('entry_1')).toBe('14,50 al mese');
    expect(onEntryTranslated.mock.calls[0][1]).toBe(false);
});

it('does not warm a letterless SEO text through a manually prepared group', async () =>
{
    const translator = new Translator({ sourceLang: 'en', targetLang: 'it' });
    const createClient = jest.spyOn(translator, '_createCacheClient');
    expect(await translator.translateSeoString(' 14,50', 'page-title')).toBeNull();
    expect(createClient).not.toHaveBeenCalled();
});
