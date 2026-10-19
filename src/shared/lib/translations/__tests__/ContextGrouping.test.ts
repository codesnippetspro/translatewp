import { TextEncoder } from 'util';

import { EntryType } from '@/shared/lib/translations/constants';
import { ContextGrouping, MAX_TEXT_FLOW_MARKER_PAYLOAD_LENGTH } from '@/shared/lib/translations/ContextGrouping';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { validateMarkerPayload } from '@/shared/lib/translations/markerPayload';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

describe('ContextGrouping whitespace collection', () =>
{
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

    it('drops whitespace-only text nodes from grouped marker payloads', async () =>
    {
        document.body.innerHTML = '<main><p>Hello<span>   </span> world</p></main>';

        const entries = new DOMExtractor().extract(document.body);
        expect(entries).toHaveLength(2);
        expect(entries.map(entry => entry.value)).toEqual(['Hello', ' world']);

        const groups = ContextGrouping.build(entries);
        expect(groups).toHaveLength(1);
        expect(validateMarkerPayload(groups[0].markerPayload)).toEqual({ valid: true });

        const markerIds = [...groups[0].markerPayload.matchAll(/⟪([^⟫]+)⟫/g)].map(match => match[1]);
        expect(new Set(markerIds).size).toBe(markerIds.length);

        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'fr',
            siteId: 'site-1',
            apiBaseUrl: 'https://api.example.test',
            aiSettings,
        });
        const plan = await client.prepare(entries);

        expect(plan.groupsForCache).toHaveLength(1);
        expect(plan.groupsForCache[0].canonicalEntries.map(entry => entry.id)).toEqual(['item_0001', 'item_0002']);
        expect(validateMarkerPayload(plan.groupsForCache[0].markerPayload)).toEqual({ valid: true });
    });

    it('keeps canonical marker ids unique when adjacent whitespace nodes are ignored', async () =>
    {
        document.body.innerHTML = '<main><p>Alpha</p><p>   </p><p>Beta</p></main>';

        const paragraphs = Array.from(document.querySelectorAll('p'));
        const entries = paragraphs
            .map((paragraph, index) => new TranslatableEntry({
                id: `txt_${String(index + 1).padStart(8, '0')}`,
                type: EntryType.TEXT,
                node: paragraph.firstChild as Text,
                attributeName: null,
                value: (paragraph.firstChild as Text).nodeValue ?? '',
            }))
            .filter(entry => entry.hasContent);

        expect(entries).toHaveLength(2);

        const groups = ContextGrouping.build(entries);
        expect(groups).toHaveLength(2);
        expect(groups.every(group => validateMarkerPayload(group.markerPayload).valid === true)).toBe(true);

        const ids = groups.flatMap(group =>
            [...group.markerPayload.matchAll(/⟪([^⟫]+)⟫/g)].map(match => match[1])
        );
        expect(new Set(ids).size).toBe(ids.length);
    });
});

describe('ContextGrouping oversized text-flow splitting', () =>
{
    const buildTextEntry = (paragraph: HTMLParagraphElement, id: string, value: string): TranslatableEntry =>
    {
        const node = document.createTextNode(value);
        paragraph.appendChild(node);
        return new TranslatableEntry({ id, type: EntryType.TEXT, node, attributeName: null, value });
    };

    it('splits an oversized text-flow group at entry boundaries and keeps document order', () =>
    {
        document.body.innerHTML = '<main><p></p></main>';
        const paragraph = document.querySelector('p') as HTMLParagraphElement;

        const entries = Array.from(
            { length: 5 },
            (_, index) => buildTextEntry(paragraph, `txt_${String(index + 1).padStart(8, '0')}`, 'a'.repeat(15000))
        );

        const groups = ContextGrouping.build(entries);

        expect(groups.length).toBeGreaterThan(1);
        expect(groups.every(group => group.kind === 'text-flow')).toBe(true);
        expect(groups.every(group => group.markerPayload.length <= MAX_TEXT_FLOW_MARKER_PAYLOAD_LENGTH)).toBe(true);

        const orderedIds = groups.flatMap(group => group.entries.map(entry => entry.id));
        expect(orderedIds).toEqual(entries.map(entry => entry.id));
    });

    it('sends a single entry larger than the cap as its own oversized group', () =>
    {
        document.body.innerHTML = '<main><p></p></main>';
        const paragraph = document.querySelector('p') as HTMLParagraphElement;

        const entries = [
            buildTextEntry(paragraph, 'txt_00000001', 'a'.repeat(70000)),
            buildTextEntry(paragraph, 'txt_00000002', 'more text'),
        ];

        const groups = ContextGrouping.build(entries);

        expect(groups).toHaveLength(2);
        expect(groups[0].entries.map(entry => entry.id)).toEqual(['txt_00000001']);
        expect(groups[0].markerPayload.length).toBeGreaterThan(MAX_TEXT_FLOW_MARKER_PAYLOAD_LENGTH);
        expect(groups[1].entries.map(entry => entry.id)).toEqual(['txt_00000002']);
    });
});
