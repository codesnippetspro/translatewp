import { EntryType } from '@/shared/lib/translations/constants';
import { truncateText } from '@/shared/lib/translations/truncateText';
import { normalizeContextKey } from '@/shared/lib/translations/fingerprintPayload';
import { hasTranslatableText } from '@/shared/lib/translations/markerPayload';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';

export const MARKER_PREFIX = '⟪';
export const MARKER_SUFFIX = '⟫';

// Mirrors the server's MISS_MAX_TOTAL_BYTES-safe per-item cap: a text-flow
// group past this length is split at entry boundaries before it is ever
// sent, so the miss report never depends on the server's skip path.
export const MAX_TEXT_FLOW_MARKER_PAYLOAD_LENGTH = 60000;

// Mirrors the server's MAX_CONTEXT_KEY_LENGTH.
export const MAX_CONTEXT_KEY_LENGTH = 1024;

// Per-side cap for the neighbour-context string, and the word count below which
// a group counts as short enough to need it.
export const NEIGHBOUR_CONTEXT_MAX_LENGTH = 120;
export const NEIGHBOUR_SHORT_WORD_LIMIT = 4;

const NEIGHBOUR_SECTION_TAGS = new Set([
    'SECTION', 'ARTICLE', 'ASIDE', 'NAV', 'HEADER', 'FOOTER', 'MAIN', 'FORM'
]);
const NEIGHBOUR_SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE']);

export type ContextGroupKind = 'text-flow' | 'alt' | 'attribute';

// Metadata only: neighbours never enters the fingerprint (fingerprintPayload.ts),
// so attaching it leaves every cached segment matching.
export type ContextGroupNeighbours = {
    before: string;
    after: string;
};

export type ContextGroup = {
    id: string;
    kind: ContextGroupKind;
    contextKey: string;
    entries: TranslatableEntry[];
    markerPayload: string;
    neighbours?: ContextGroupNeighbours;
};

const BLOCK_TAGS = new Set([
    'ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'DETAILS', 'DIALOG', 'DD', 'DIV', 'DL', 'DT',
    'FIELDSET', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'FORM', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
    'HEADER', 'HR', 'LI', 'MAIN', 'NAV', 'OL', 'P', 'PRE', 'SECTION', 'TABLE', 'TD', 'TH', 'UL'
]);

export class ContextGrouping
{
    static build(entries: TranslatableEntry[]): ContextGroup[]
    {
        const groups: ContextGroup[] = [];
        let groupCounter = 0;

        const textEntries = entries
            .filter(entry => entry.type === EntryType.TEXT && entry.hasContent)
            .sort(compareEntriesInDocumentOrder);

        const nonTextEntries = entries
            .filter(entry => entry.type !== EntryType.TEXT && entry.hasContent);

        let activeTextEntries: TranslatableEntry[] = [];
        let activeTextContext = '';

        for (const entry of textEntries)
        {
            const contextKey = getContextKey(entry);

            if (!activeTextEntries.length)
            {
                activeTextEntries = [entry];
                activeTextContext = contextKey;
                continue;
            }

            const previous = activeTextEntries[activeTextEntries.length - 1];
            const keepTogether = shouldKeepInSameTextFlow(previous, entry, activeTextContext, contextKey);

            if (keepTogether)
            {
                activeTextEntries.push(entry);
                continue;
            }

            for (const chunk of splitEntriesUnderPayloadCap(collapseOutlineDuplicates(activeTextEntries)))
            {
                groups.push(ContextGrouping.#createGroup(++groupCounter, 'text-flow', activeTextContext, chunk));
            }
            activeTextEntries = [entry];
            activeTextContext = contextKey;
        }

        if (activeTextEntries.length)
        {
            for (const chunk of splitEntriesUnderPayloadCap(collapseOutlineDuplicates(activeTextEntries)))
            {
                groups.push(ContextGrouping.#createGroup(++groupCounter, 'text-flow', activeTextContext, chunk));
            }
        }

        for (const entry of nonTextEntries)
        {
            if (!entry.hasContent)
            {
                continue;
            }

            const kind: ContextGroupKind = entry.type === EntryType.ALT ? 'alt' : 'attribute';
            groups.push(ContextGrouping.#createGroup(++groupCounter, kind, getContextKey(entry), [entry]));
        }

        return groups.filter(group => group.entries.length > 0);
    }

    static serializeWithMarkers(entries: TranslatableEntry[]): string
    {
        return filterTranslatableEntries(entries)
            .map(entry => `${MARKER_PREFIX}${entry.id}${MARKER_SUFFIX}${entry.value}`)
            .join('');
    }

    static entriesForMarkers(entries: TranslatableEntry[]): TranslatableEntry[]
    {
        return filterTranslatableEntries(entries);
    }

    static parseTranslatedMarkerEntries(translatedPayload: string): { id: string; value: string }[]
    {
        const markerRegex = new RegExp(`${escapeRegExp(MARKER_PREFIX)}([^${escapeRegExp(MARKER_SUFFIX)}]+)${escapeRegExp(MARKER_SUFFIX)}`, 'g');
        const markers: { id: string; index: number; markerLength: number }[] = [];
        let match: RegExpExecArray | null;

        while ((match = markerRegex.exec(translatedPayload)) !== null)
        {
            markers.push({
                id: match[1],
                index: match.index,
                markerLength: match[0].length,
            });
        }

        return markers.map((current, index) =>
        {
            const valueStart = current.index + current.markerLength;
            const valueEnd = index + 1 < markers.length ? markers[index + 1].index : translatedPayload.length;

            return {
                id: current.id,
                value: translatedPayload.slice(valueStart, valueEnd),
            };
        });
    }

    static parseTranslatedMarkers(translatedPayload: string): Map<string, string>
    {
        const valuesById = new Map<string, string>();

        for (const entry of ContextGrouping.parseTranslatedMarkerEntries(translatedPayload))
        {
            valuesById.set(entry.id, entry.value);
        }

        return valuesById;
    }

    static #createGroup(counter: number, kind: ContextGroupKind, contextKey: string, entries: TranslatableEntry[]): ContextGroup
    {
        const contentEntries = filterTranslatableEntries(entries);
        const group: ContextGroup = {
            id: `grp_${String(counter).padStart(8, '0')}`,
            kind,
            contextKey: truncateText(normalizeContextKey(contextKey), MAX_CONTEXT_KEY_LENGTH),
            entries: contentEntries,
            markerPayload: ContextGrouping.serializeWithMarkers(contentEntries)
        };

        const neighbours = computeGroupNeighbours(kind, contentEntries);
        if (neighbours)
        {
            group.neighbours = neighbours;
        }

        return group;
    }
}

function filterTranslatableEntries(entries: TranslatableEntry[]): TranslatableEntry[]
{
    return entries.filter(entry => entry.hasContent && hasTranslatableText(entry.value));
}

// Splits a text-flow entry run at entry boundaries, never mid-entry, so each
// resulting chunk serializes under MAX_TEXT_FLOW_MARKER_PAYLOAD_LENGTH. A
// single entry already over the cap is sent as its own oversized chunk.
function splitEntriesUnderPayloadCap(entries: TranslatableEntry[]): TranslatableEntry[][]
{
    const chunks: TranslatableEntry[][] = [];
    let current: TranslatableEntry[] = [];

    for (const entry of entries)
    {
        const candidate = [...current, entry];
        const candidateLength = ContextGrouping.serializeWithMarkers(candidate).length;

        if (current.length && candidateLength > MAX_TEXT_FLOW_MARKER_PAYLOAD_LENGTH)
        {
            chunks.push(current);
            current = [entry];
            continue;
        }

        current = candidate;
    }

    if (current.length)
    {
        chunks.push(current);
    }

    return chunks;
}

const compareEntriesInDocumentOrder = (a: TranslatableEntry, b: TranslatableEntry): number =>
{
    if (a.node === b.node) return 0;

    const relation = a.node.compareDocumentPosition(b.node);
    if (relation & Node.DOCUMENT_POSITION_FOLLOWING) return -1;
    if (relation & Node.DOCUMENT_POSITION_PRECEDING) return 1;

    return 0;
};

const shouldKeepInSameTextFlow = (previous: TranslatableEntry, current: TranslatableEntry, previousContextKey: string, currentContextKey: string): boolean =>
{
    if (previousContextKey !== currentContextKey) return false;

    const previousValue = previous.value;
    const currentValue = current.value;

    if (/\n{2,}/.test(previousValue) || /\n{2,}/.test(currentValue)) return false;

    const previousTrimmed = previousValue.trimEnd();
    const currentTrimmed = currentValue.trimStart();

    if (!previousTrimmed || !currentTrimmed) return true;

    const previousEndsSentence = /[.!?]["')\]]?$/.test(previousTrimmed);
    const currentStartsNewSentence = /^[A-Z0-9]/.test(currentTrimmed);

    if (previousEndsSentence && currentStartsNewSentence) return false;

    return true;
};

const getContextKey = (entry: TranslatableEntry): string =>
{
    const element = entry.type === EntryType.TEXT
        ? (entry.node.parentElement ?? null)
        : (entry.node as Element);

    const blockElement = getClosestBlockAncestor(element);
    const attributeKey = entry.attributeName ? `@${entry.attributeName}` : '';

    return `${getElementPath(blockElement)}${attributeKey}`;
};

const getClosestBlockAncestor = (element: Element | null): Element =>
{
    let cursor = element;

    while (cursor)
    {
        if (BLOCK_TAGS.has(cursor.tagName)) return cursor;
        cursor = cursor.parentElement;
    }

    return element?.ownerDocument?.body ?? document.body;
};

const getElementPath = (element: Element): string =>
{
    const parts: string[] = [];
    let cursor: Element | null = element;
    // Paths are relative to the element's own document — extraction may run
    // over a DOMParser-parsed page (warm-up indexing), and its keys must
    // match what a visitor's browser computes on the live page.
    const stopAt = element.ownerDocument?.body ?? document.body;

    while (cursor && cursor !== stopAt)
    {
        const parent: Element | null = cursor.parentElement;
        if (!parent) break;

        const tag = cursor.tagName.toLowerCase();
        const cursorTag = cursor.tagName;
        const siblingsWithSameTag = Array.from(parent.children)
            .filter((child: Element) => child.tagName === cursorTag);
        const index = siblingsWithSameTag.indexOf(cursor) + 1;

        parts.push(`${tag}:nth-of-type(${index})`);
        cursor = parent;
    }

    return parts.reverse().join(' > ') || 'body';
};

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// A text-flow run shares one block (one contextKey). When that block's whole
// visible text is one phrase repeated across its nodes -- the theme's outline
// heading effect, one copy in a child element and a fill copy as the block's own
// text -- every node is normalised to the same collapsed value. The per-value
// dedupe in TranslationCacheClient then sends the phrase to the model once, while
// write-back still fans the single translation out to every node.
function collapseOutlineDuplicates(entries: TranslatableEntry[]): TranslatableEntry[]
{
    if (entries.length < 2) return entries;

    const collapsedValues = entries.map(entry => collapseNeighbourWhitespace(entry.value));
    const [first] = collapsedValues;
    if (!first || !collapsedValues.every(value => value === first)) return entries;

    return entries.map(entry => entry.value === first
        ? entry
        : new TranslatableEntry({
            id: entry.id,
            type: entry.type,
            node: entry.node,
            attributeName: entry.attributeName,
            value: first,
        }));
}

function computeGroupNeighbours(kind: ContextGroupKind, entries: TranslatableEntry[]): { before: string; after: string } | undefined
{
    if (kind !== 'text-flow' || !entries.length) return undefined;

    const uniqueValues = [...new Set(entries.map(entry => entry.value))];
    const combined = collapseNeighbourWhitespace(uniqueValues.join(' '));
    if (!combined || countWords(combined) >= NEIGHBOUR_SHORT_WORD_LIMIT) return undefined;

    const before = neighbourText(entries[0].node, 'before');
    const after = neighbourText(entries[entries.length - 1].node, 'after');
    if (!before && !after) return undefined;

    return { before, after };
}

function neighbourText(boundaryNode: Node, direction: 'before' | 'after'): string
{
    const doc = boundaryNode.ownerDocument;
    const root = neighbourSection(boundaryNode);
    if (!doc || !root || !root.contains(boundaryNode)) return '';

    const walker = doc.createTreeWalker(root, window.NodeFilter.SHOW_TEXT);
    walker.currentNode = boundaryNode;

    const fragments: string[] = [];
    const seen = new Set<string>();
    let total = 0;

    for (;;)
    {
        const node = direction === 'before' ? walker.previousNode() : walker.nextNode();
        if (!node) break;

        const collapsed = collapseNeighbourWhitespace(node.nodeValue ?? '');
        if (!collapsed || seen.has(collapsed) || !isVisibleNeighbourNode(node)) continue;

        seen.add(collapsed);
        fragments.push(collapsed);
        total += collapsed.length + 1;
        if (total >= NEIGHBOUR_CONTEXT_MAX_LENGTH) break;
    }

    if (direction === 'before') fragments.reverse();

    return truncateText(collapseNeighbourWhitespace(fragments.join(' ')), NEIGHBOUR_CONTEXT_MAX_LENGTH);
}

function neighbourSection(node: Node): Element | null
{
    let element: Element | null = node.nodeType === Node.TEXT_NODE
        ? node.parentElement
        : (node as Element);

    while (element)
    {
        if (NEIGHBOUR_SECTION_TAGS.has(element.tagName)) return element;
        element = element.parentElement;
    }

    return node.ownerDocument?.body ?? null;
}

function isVisibleNeighbourNode(node: Node): boolean
{
    let element: Element | null = node.parentElement;

    while (element)
    {
        if (NEIGHBOUR_SKIP_TAGS.has(element.tagName)) return false;
        element = element.parentElement;
    }

    return true;
}

function collapseNeighbourWhitespace(value: string): string
{
    return value.replace(/\s+/g, ' ').trim();
}

function countWords(value: string): number
{
    const collapsed = collapseNeighbourWhitespace(value);
    return collapsed ? collapsed.split(' ').length : 0;
}
