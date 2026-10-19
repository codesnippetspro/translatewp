import { ContextGrouping, type ContextGroup } from '@/shared/lib/translations/ContextGrouping';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { EntryType } from '@/shared/lib/translations/constants';

// What the front-end editor works on. Every translated
// group a signed-in administrator's page applied is recorded here with its
// fingerprint, its source and served payloads and the DOM nodes it wrote to.
// Visitors never fill it: recording is gated on canManage by the caller, so
// the visitor path pays nothing and the visitor DOM carries no marks.
export type AppliedSegment = {
    hash: string;
    language: string;
    group: ContextGroup;
    sourcePayload: string;
    translatedPayload: string;
    // The text of the source with the markers removed, for matching
    // "all occurrences" of the same wording across contexts.
    sourceText: string;
    manual: boolean;
};

export const stripMarkers = (payload: string): string =>
    ContextGrouping.parseTranslatedMarkerEntries(payload).map(entry => entry.value).join('').replace(/\s+/g, ' ').trim();

export const normalizeSourceText = (text: string): string => text.normalize('NFKC').replace(/\s+/g, ' ').trim().toLowerCase();

// The element a segment renders in: the parent of its first text node, or the
// element itself for an attribute entry.
export const segmentElement = (segment: AppliedSegment): Element | null =>
{
    const entry = segment.group.entries[0];
    if (!entry) return null;
    const node = entry.node;
    return node.nodeType === Node.TEXT_NODE ? node.parentElement : (node as Element);
};

export class SegmentRegistry
{
    #byHash = new Map<string, AppliedSegment>();
    #byNode = new Map<Node, string>();
    #listeners = new Set<() => void>();

    record(input: Omit<AppliedSegment, 'sourceText' | 'manual'> & { manual?: boolean }): AppliedSegment
    {
        const previous = this.#byHash.get(input.hash);
        const segment: AppliedSegment = {
            ...input,
            sourceText: stripMarkers(input.sourcePayload),
            manual: input.manual ?? previous?.manual ?? false,
        };
        this.#byHash.set(segment.hash, segment);
        for (const entry of segment.group.entries) this.#byNode.set(entry.node, segment.hash);
        this.#emit();
        return segment;
    }

    markManual(hashes: Iterable<string>, manual: boolean): void
    {
        for (const hash of hashes)
        {
            const segment = this.#byHash.get(hash);
            if (segment) segment.manual = manual;
        }
        this.#emit();
    }

    clear(): void
    {
        this.#byHash.clear();
        this.#byNode.clear();
        this.#emit();
    }

    all(): AppliedSegment[]
    {
        return [...this.#byHash.values()].filter(segment => segment.group.entries.some(entry => entry.node.isConnected));
    }

    get(hash: string): AppliedSegment | undefined
    {
        return this.#byHash.get(hash);
    }

    // The segment a DOM node belongs to: the node itself, or the nearest text
    // node child of an element the pointer landed on.
    forNode(node: Node | null): AppliedSegment | undefined
    {
        let current: Node | null = node;
        while (current)
        {
            const hash = this.#byNode.get(current);
            if (hash) return this.#byHash.get(hash);
            if (current.nodeType === Node.ELEMENT_NODE)
            {
                for (const child of Array.from(current.childNodes))
                {
                    const childHash = this.#byNode.get(child);
                    if (childHash) return this.#byHash.get(childHash);
                }
            }
            current = current.parentNode;
            if (current === document.body) break;
        }
        return undefined;
    }

    // Every recorded segment whose source wording equals this one's,
    // regardless of context; this one included, in document order.
    matching(segment: AppliedSegment): AppliedSegment[]
    {
        const key = normalizeSourceText(segment.sourceText);
        return this.all()
            .filter(candidate => candidate.language === segment.language && normalizeSourceText(candidate.sourceText) === key)
            .sort(compareSegmentsInDocumentOrder);
    }

    subscribe(listener: () => void): () => void
    {
        this.#listeners.add(listener);
        return () => { this.#listeners.delete(listener); };
    }

    #emit(): void
    {
        for (const listener of this.#listeners) listener();
    }
}

export const compareSegmentsInDocumentOrder = (left: AppliedSegment, right: AppliedSegment): number =>
{
    const a = left.group.entries[0]?.node;
    const b = right.group.entries[0]?.node;
    if (!a || !b || a === b) return 0;
    const position = a.compareDocumentPosition(b);
    if (position & Node.DOCUMENT_POSITION_FOLLOWING) return -1;
    if (position & Node.DOCUMENT_POSITION_PRECEDING) return 1;
    return 0;
};

// Rebuilds a served payload for a segment from per-entry target values, in the
// canonical marker ids every stored payload carries (item_0001, item_0002, ...),
// never the page's own entry ids: the cache only serves a payload whose ids
// match the group's canonical ids, so a correction stored under entry ids
// would be served once and lost on the next load.
export const buildPayload = (segment: AppliedSegment, values: string[]): string =>
{
    const entries = ContextGrouping.entriesForMarkers(segment.group.entries);
    return entries.map((entry, index) => entry.withTranslation(values[index] ?? entry.value))
        .map((entry, index) => `⟪item_${String(index + 1).padStart(4, '0')}⟫${entry.value}`)
        .join('');
};

// The per-entry target values of a served payload, positionally aligned with
// the segment's translatable entries (ids differ between page loads, so the
// order is the contract, as it is for the translator).
export const payloadValues = (segment: AppliedSegment, payload: string): string[] =>
{
    const entries = ContextGrouping.entriesForMarkers(segment.group.entries);
    const parsed = ContextGrouping.parseTranslatedMarkerEntries(payload);
    const byId = new Map(parsed.map(entry => [entry.id, entry.value]));
    return entries.map((entry, index) => byId.get(entry.id) ?? parsed[index]?.value ?? entry.value);
};

// Writes target values straight into the segment's nodes and records the new
// served payload. Used by the editor after a saved correction.
export const applyPayload = (segment: AppliedSegment, payload: string, write: (entry: TranslatableEntry) => void): void =>
{
    const entries = ContextGrouping.entriesForMarkers(segment.group.entries);
    const values = payloadValues(segment, payload);
    entries.forEach((entry, index) =>
    {
        const value = values[index];
        if (value === undefined) return;
        write(entry.withTranslation(value));
    });
    segment.translatedPayload = payload;
};

export const entryIsText = (entry: TranslatableEntry): boolean => entry.type === EntryType.TEXT;

export const segmentRegistry = new SegmentRegistry();
export default segmentRegistry;
