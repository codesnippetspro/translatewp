import { TextEncoder } from 'util';

import { ContextGroup, ContextGrouping, NEIGHBOUR_CONTEXT_MAX_LENGTH } from '@/shared/lib/translations/ContextGrouping';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { Translator, type TranslateBatchOptions } from '@/shared/lib/translations/Translator';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

const aiSettings = {
    preserve_branded_terms: true,
    localized_date_time_formats: false,
    enable_units_conversion: false,
    context: 'website',
    writing_style: 'neutral',
    tone: 'neutral',
    formality: 0,
    creativity: 0,
    lexicon_terms: [],
};

// Synthetic fixture for a known theme pattern: one sentence rendered as two
// sibling fancy headings, each duplicating its own text for a CSS outline effect.
const fancyHeading = (text: string): string =>
    `<div class="ld-fancy-heading text-uppercase ld-fh-outline ld-fh-outline-static">`
    + `<h1 class="ld-fh-element lqd-highlight-underline"><span class="ld-fh-txt-outline">${text}</span>  ${text}</h1>`
    + `</div>`;

const footerMarkup = `<footer>${fancyHeading('BUCHEN')}${fancyHeading('SIE EINE DEMO')}`
    + `<p>WILLKOMMEN!</p><p>NEUIGKEITEN</p></footer>`;

const groupWithText = (groups: ContextGroup[], text: string): ContextGroup =>
{
    const match = groups.find(group =>
        group.kind === 'text-flow' && group.markerPayload.includes(text));
    if (!match) throw new Error(`No group found carrying "${text}"`);
    return match;
};

const countOccurrences = (haystack: string, needle: string): number =>
    haystack.split(needle).length - 1;

describe('neighbour context for short footer headings', () =>
{
    beforeEach(() =>
    {
        document.body.innerHTML = footerMarkup;
    });

    it('gives each heading the sibling heading as its neighbour', () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const groups = ContextGrouping.build(entries);

        const sutiGroup = groupWithText(groups, 'BUCHEN');
        const newsGroup = groupWithText(groups, 'SIE EINE DEMO');

        expect(sutiGroup.neighbours?.after).toContain('SIE EINE DEMO');
        expect(newsGroup.neighbours?.before).toContain('BUCHEN');

        // Short enough to need context, so the side pointing into the rest of the
        // footer is populated too, and each side stays within the 120-char cap.
        expect(newsGroup.neighbours?.after).toContain('WILLKOMMEN!');
        expect((sutiGroup.neighbours?.after.length ?? 0)).toBeLessThanOrEqual(120);
        expect((newsGroup.neighbours?.before.length ?? 0)).toBeLessThanOrEqual(120);
    });

    it('collapses the duplicated outline nodes so the segment is sent once', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);

        const client = new TranslationCacheClient({
            sourceLang: 'de',
            targetLang: 'ko',
            siteId: 'site-1',
            apiBaseUrl: 'https://api.example.test',
            aiSettings,
        });
        const plan = await client.prepare(entries);

        const newsCache = plan.groupsForCache.find(groupCache =>
            groupCache.markerPayload.includes('SIE EINE DEMO'));
        if (!newsCache) throw new Error('No cache group carrying "SIE EINE DEMO"');

        // The block held "SIE EINE DEMO" twice (outline copy + fill copy); it is sent once.
        expect(newsCache.canonicalEntries).toHaveLength(1);
        expect(countOccurrences(newsCache.markerPayload, 'SIE EINE DEMO')).toBe(1);
    });

    it('writes the single translation back to both outline nodes', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);

        const client = new TranslationCacheClient({
            sourceLang: 'de',
            targetLang: 'ko',
            siteId: 'site-1',
            apiBaseUrl: 'https://api.example.test',
            aiSettings,
        });
        const plan = await client.prepare(entries);

        const newsCache = plan.groupsForCache.find(groupCache =>
            groupCache.markerPayload.includes('SIE EINE DEMO'));
        if (!newsCache) throw new Error('No cache group carrying "SIE EINE DEMO"');

        expect(newsCache.group.entries).toHaveLength(2);

        const translatedPayload = newsCache.markerPayload.replace('SIE EINE DEMO', '데모 예약');
        const translator = new Translator({ sourceLang: 'de', targetLang: 'ko' });
        const applied: TranslatableEntry[] = [];
        const options: TranslateBatchOptions = {
            onEntryTranslated: entry => applied.push(entry),
        };

        translator._applyGroupTranslation(newsCache, translatedPayload, new Map<string, string>(), options);

        const appliedNodes = new Set(applied.map(entry => entry.node));
        expect(appliedNodes.size).toBe(2);
        expect(applied.every(entry => entry.value === '데모 예약')).toBe(true);

        for (const entry of applied)
        {
            entry.node.nodeValue = entry.value;
        }
        const newsHeading = Array.from(document.querySelectorAll('h1'))
            .find(heading => heading.textContent?.includes('데모'));
        const headingText = newsHeading?.textContent ?? '';
        expect(headingText).not.toContain('SIE EINE DEMO');
        expect(countOccurrences(headingText, '데모 예약')).toBe(2);
    });
});

describe('neighbour text at the length cap', () =>
{
    it('never ends on half of a flag emoji', () =>
    {
        const countries = Array.from({ length: 40 }, (_, index) => `🇱🇺 Country ${index}`).join(' ');
        document.body.innerHTML = `<main><h2>Pick a country</h2><p>${countries}</p></main>`;
        const heading = document.querySelector('h2') as HTMLElement;
        const entries = new DOMExtractor().extract(heading);
        const groups = ContextGrouping.build(entries);
        const after = groups[0]?.neighbours?.after ?? '';

        expect(after.length).toBeLessThanOrEqual(NEIGHBOUR_CONTEXT_MAX_LENGTH);
        expect(after.charCodeAt(after.length - 1)).not.toBeGreaterThanOrEqual(0xd800);
        expect(() => JSON.parse(JSON.stringify({ after }))).not.toThrow();
    });
});
