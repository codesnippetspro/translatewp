import { ContextGrouping } from '@/shared/lib/translations/ContextGrouping';
import { EntryType } from '@/shared/lib/translations/constants';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { SegmentRegistry, buildPayload, payloadValues, applyPayload, stripMarkers } from '@/shared/lib/translations/segmentRegistry';

const textEntry = (id: string, text: string, parent: Element): TranslatableEntry =>
{
    const node = document.createTextNode(text);
    parent.appendChild(node);
    return new TranslatableEntry({ id, type: EntryType.TEXT, node, attributeName: null, value: text });
};

const segmentFor = (registry: SegmentRegistry, hash: string, language: string, parent: Element, texts: string[], translated: string[]) =>
{
    const entries = texts.map((text, index) => textEntry(`txt_0000000${index + 1}`, text, parent));
    const group = { id: `g-${hash}`, kind: 'text-flow' as const, contextKey: 'p', entries, markerPayload: ContextGrouping.serializeWithMarkers(entries) };
    const translatedPayload = entries.map((entry, index) => `⟪${entry.id}⟫${translated[index]}`).join('');
    return registry.record({ hash, language, group, sourcePayload: group.markerPayload, translatedPayload });
};

describe('SegmentRegistry', () =>
{
    beforeEach(() => { document.body.innerHTML = ''; });

    it('finds the segment a node belongs to and its parent element', () =>
    {
        const registry = new SegmentRegistry();
        const p = document.createElement('p');
        document.body.appendChild(p);
        const segment = segmentFor(registry, 'h1', 'de', p, ['Hall'], ['Wiese']);

        expect(registry.forNode(segment.group.entries[0].node)).toBe(segment);
        expect(registry.forNode(p)).toBe(segment);
        expect(registry.forNode(document.body)).toBeUndefined();
        expect(segment.sourceText).toBe('Hall');
    });

    it('matches occurrences by source wording across contexts, in document order, within one language', () =>
    {
        const registry = new SegmentRegistry();
        const first = document.createElement('p');
        const second = document.createElement('li');
        const other = document.createElement('p');
        document.body.append(first, second, other);
        const b = segmentFor(registry, 'h-b', 'de', second, ['hall'], ['Wiese']);
        const a = segmentFor(registry, 'h-a', 'de', first, ['Hall'], ['Wiese']);
        segmentFor(registry, 'h-c', 'de', other, ['Garden'], ['Garten']);
        segmentFor(registry, 'h-fr', 'fr', other, ['Hall'], ['Salle']);

        expect(registry.matching(a).map(segment => segment.hash)).toEqual(['h-a', 'h-b']);
        expect(registry.matching(b).map(segment => segment.hash)).toEqual(['h-a', 'h-b']);
    });

    it('drops segments whose nodes left the document', () =>
    {
        const registry = new SegmentRegistry();
        const p = document.createElement('p');
        document.body.appendChild(p);
        segmentFor(registry, 'h1', 'de', p, ['Hall'], ['Wiese']);
        expect(registry.all()).toHaveLength(1);
        p.remove();
        expect(registry.all()).toHaveLength(0);
    });

    it('marks and reports manual segments and notifies subscribers', () =>
    {
        const registry = new SegmentRegistry();
        const p = document.createElement('p');
        document.body.appendChild(p);
        const segment = segmentFor(registry, 'h1', 'de', p, ['Hall'], ['Wiese']);
        const listener = jest.fn();
        registry.subscribe(listener);
        registry.markManual(['h1'], true);
        expect(segment.manual).toBe(true);
        expect(listener).toHaveBeenCalled();
    });
});

describe('payload helpers', () =>
{
    it('rebuilds a payload in the canonical marker ids every stored payload carries and reads values back positionally', () =>
    {
        const registry = new SegmentRegistry();
        const p = document.createElement('p');
        document.body.appendChild(p);
        const segment = segmentFor(registry, 'h1', 'de', p, ['Big ', 'hall'], ['Große ', 'Wiese']);

        expect(payloadValues(segment, segment.translatedPayload)).toEqual(['Große ', 'Wiese']);
        const rebuilt = buildPayload(segment, ['Große ', 'Halle']);
        expect(rebuilt).toBe('⟪item_0001⟫Große ⟪item_0002⟫Halle');
        expect(payloadValues(segment, rebuilt)).toEqual(['Große ', 'Halle']);
        // A payload stored under other ids (an earlier page load) still reads by position.
        expect(payloadValues(segment, '⟪txt_00000090⟫Große ⟪txt_00000091⟫Halle')).toEqual(['Große ', 'Halle']);
        expect(stripMarkers(rebuilt)).toBe('Große Halle');
    });

    it('applies a payload to the nodes and records it as the served value', () =>
    {
        const registry = new SegmentRegistry();
        const p = document.createElement('p');
        document.body.appendChild(p);
        const segment = segmentFor(registry, 'h1', 'de', p, ['hall'], ['Wiese']);
        applyPayload(segment, '⟪txt_00000001⟫Halle', entry => { entry.node.nodeValue = entry.value; });
        expect(p.textContent).toBe('Halle');
        expect(segment.translatedPayload).toBe('⟪txt_00000001⟫Halle');
    });
});
