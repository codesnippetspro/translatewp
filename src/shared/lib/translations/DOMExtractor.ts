import {
    DEFAULT_EXTRACTION_OPTIONS,
    ADMIN_ONLY_CLASSES, DEFAULT_NO_TRANSLATE_CLASS,
    EntryType,
    type ExtractionOptions,
    TRANSLATABLE_ATTRIBUTES,
} from '@/shared/lib/translations/constants';
import { NodeFilter } from '@/shared/lib/translations/NodeFilter';
import { pageAreaSelector } from '@/shared/lib/translations/pageAreas';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';

/**
 * Extracts all translatable content from a DOM subtree
 * 
 * Use TreeWalker for text nodes (O(n) single pass, no querySelectorAll overhead) and targeted querySelectorAll for attribute-bearing elements
 */
export class DOMExtractor
{
    #idCounter = 0;

    skipDuplicates: boolean;
    noTranslateClasses: string[];
    noTranslateIds: string[];

    /**
     * @param {objects} [options]
     * @param {boolean} [options.skipDuplicates=false]
     *  When true, entries with an identical value are deduplicated
     *  The first occurrence (document order) is kept; its node is used for write-back
     * @param {string[]} [options.noTranslateClasses=[DEFAULT_NO_TRANSLATE_CLASS]]
     *  CSS classes that opt an element and its descendants out of translation
     * @param {string[]} [options.noTranslateIds=[]]
     *  Element IDs that opt an element and its descendants out of translation
     * @param {string} [options.noTranslateClass]
     *  Deprecated single-class alias kept for backward compatibility
     * @param {string} [options.noTranslateId]
     *  Deprecated single-id alias kept for backward compatibility
     */
    constructor({
        skipDuplicates = false,
        noTranslateClasses = [DEFAULT_NO_TRANSLATE_CLASS],
        noTranslateIds = [],
        noTranslateClass,
        noTranslateId
    }: {
        skipDuplicates?: boolean,
        noTranslateClasses?: string[],
        noTranslateIds?: string[],
        noTranslateClass?: string,
        noTranslateId?: string
    } = {})
    {
        this.skipDuplicates = skipDuplicates;
        this.noTranslateClasses = DOMExtractor.#normalizeNoTranslateClasses(noTranslateClasses, noTranslateClass);
        this.noTranslateIds = DOMExtractor.#normalizeNoTranslateIds(noTranslateIds, noTranslateId);
    }

    /**
     * Extract all translatable entries from a root element
     * 
     * @param {Element}            root
     * @param {ExtractionOptions}  options
     * @returns {TranslatableEntry[]}
     */
    extract(root: Element, options: ExtractionOptions = DEFAULT_EXTRACTION_OPTIONS): TranslatableEntry[]
    {
        this.#idCounter = 0;
        this.#excludedAreaSelector = pageAreaSelector(options.excludedAreas ?? []);

        const entries: TranslatableEntry[] = [];

        // Each extraction step is independent and only runs when the mode includes that content type
        this.#extractTextNodes(root, entries);

        if (options.includeAlt)
        {
            this.#extractAltTexts(root, entries);
        }

        if (options.includeAttributes)
        {
            this.#extractAttributes(root, entries);
        }

        return this.skipDuplicates ? this.#deduplicate(entries) : entries;
    }

    // --- Private Extraction Methods ---

    /**
     * Walks all text nodes in the subtree using a TreeWalker
     * TreeWalker is significantly faster than recursive manual traversal or innerHTML parsing for large DOMs
     * 
     * @param {Element}             root
     * @param {TranslatableEntry[]} entries
     */
    #extractTextNodes(root: Element, entries: TranslatableEntry[]): void
    {
        const walker = document.createTreeWalker(root, NodeFilter_SHOW_TEXT);

        let node: Node | null;

        while ((node = walker.nextNode()))
        {
            if (!NodeFilter.isTranslatableTextNode(node as Text, this.noTranslateClasses, this.noTranslateIds)) continue;
            if (this.#inExcludedArea((node as Text).parentElement)) continue;

            const value = node.nodeValue ?? '';
            if (!value.trim().length) continue;

            entries.push(new TranslatableEntry({
                id:             this.#nextId('txt'),
                type:           EntryType.TEXT,
                node,
                attributeName:  null,
                value,
            }));
        }
    }

    /**
     * Extracts alt text from all <img> elements in the subtree
     * 
     * @param {Element}             root
     * @param {TranslatableEntry[]} entries
     */
    #extractAltTexts(root: Element, entries: TranslatableEntry[]): void
    {
        const images = [
            ...(root.matches('img') ? [root as HTMLImageElement] : []),
            ...Array.from(root.querySelectorAll('img')),
        ];

        for (const img of images)
        {
            if (!NodeFilter.isTranslatableAlt(img, this.noTranslateClasses, this.noTranslateIds)) continue;
            if (this.#inExcludedArea(img)) continue;

            entries.push(new TranslatableEntry({
                id:             this.#nextId('alt'),
                type:           EntryType.ALT,
                node:           img,
                attributeName:  'alt',
                value:          img.getAttribute('alt') ?? ''
            }));
        }
    }

    /**
     * Extracts all translatable HTML attributes from elements in the subtree
     * Builds a single compound CSS selector to query all attributes in one pass
     * 
     * @param {Element}             root
     * @param {TranslatableEntry[]} entries
     */
    #extractAttributes(root: Element, entries: TranslatableEntry[]): void
    {
        // Build "[attr1],[attr2],..." selector to fetch all candidates in one query
        const selector = TRANSLATABLE_ATTRIBUTES.map(attr => `[${attr}]`).join(',');
        const elements = [
            ...(root.matches(selector) ? [root] : []),
            ...Array.from(root.querySelectorAll(selector)),
        ];

        for (const element of elements)
        {
            for (const attr of TRANSLATABLE_ATTRIBUTES)
            {
                if (!element.hasAttribute(attr)) continue;
                if (!NodeFilter.isTranslatableAttribute(element, attr, this.noTranslateClasses, this.noTranslateIds)) continue;
                if (this.#inExcludedArea(element)) continue;

                entries.push(new TranslatableEntry({
                    id:             this.#nextId('attr'),
                    type:           EntryType.ATTRIBUTE,
                    node:           element,
                    attributeName:  attr,
                    value:          element.getAttribute(attr) ?? ''
                }));
            }
        }
    }

    /**
     * Removes entries with duplicate values, keeping the first occurrence
     * Uses a Map for O(n) performance
     * 
     * @param {TranslatableEntry[]}     entries
     * @returns {TranslatableEntry[]}
     */
    #deduplicate(entries: TranslatableEntry[]): TranslatableEntry[]
    {
        const seen = new Map<string, TranslatableEntry>();

        return entries.filter(entry =>
        {
            const key = `${entry.type}::${entry.value}`;
            if (seen.has(key)) return false;
            seen.set(key, entry);
            return true;
        });
    }

    /** @returns {string} */
    #nextId(prefix: string): string
    {
        return `${prefix}_${String(++this.#idCounter).padStart(8, '0')}`;
    }

    static #normalizeNoTranslateClasses(noTranslateClasses: string[], noTranslateClass?: string): string[]
    {
        const classes = new Set<string>([DEFAULT_NO_TRANSLATE_CLASS, ...ADMIN_ONLY_CLASSES]);

        for (const className of noTranslateClasses)
        {
            if (className?.trim())
            {
                classes.add(className.trim());
            }
        }

        if (noTranslateClass?.trim())
        {
            classes.add(noTranslateClass.trim());
        }

        return [...classes];
    }

    #excludedAreaSelector = '';

    #inExcludedArea(element: Element | null): boolean
    {
        if (this.#excludedAreaSelector === '' || !element) return false;

        return element.closest(this.#excludedAreaSelector) !== null;
    }

    static #normalizeNoTranslateIds(noTranslateIds: string[], noTranslateId?: string): string[]
    {
        const ids = new Set<string>();

        for (const id of noTranslateIds)
        {
            if (id?.trim())
            {
                ids.add(id.trim());
            }
        }

        if (noTranslateId?.trim())
        {
            ids.add(noTranslateId.trim());
        }

        return [...ids];
    }
}

// TreeWalker requires the window.NodeFilter constant; alias to avoid shadowing our own NodeFilter import
const NodeFilter_SHOW_TEXT = window.NodeFilter.SHOW_TEXT;