import {
    DEFAULT_EXTRACTION_OPTIONS,
    DEFAULT_NO_TRANSLATE_CLASS,
    EntryType,
    type ExtractionOptions,
} from '@/shared/lib/translations/constants';
import { ContextGroup } from '@/shared/lib/translations/ContextGrouping';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import type { NumericTemplateMemory } from '@/shared/lib/translations/NumericTemplateMemory';
import { normalizeNumbers, substituteNumbers } from '@/shared/lib/translations/numericText';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import {
    Translator,
    type TranslationBatchResult,
} from '@/shared/lib/translations/Translator';

export type TranslationEntryWrite = {
    entry: TranslatableEntry;
    changed: boolean;
    sourceValue?: string;
};

export type TranslationRunOptions = {
    signal?: AbortSignal;
    onExtracted?: (entries: TranslatableEntry[]) => void;
    onEntryApplied?: (entry: TranslatableEntry) => void;
    onEntryWritten?: (write: TranslationEntryWrite) => void;
    onServePath?: (path: 'ready' | 'cold') => void;
    onGroupServeStarted?: (entries: TranslatableEntry[]) => void;
    onGroupServeFailed?: (entries: TranslatableEntry[]) => void;
};

export type TranslationEngineResult = TranslationBatchResult & {
    sourceEntries: TranslatableEntry[];
};

/**
 * TranslationEngine is the top-level orchestrator for the translation process
 * 
 * Coordinates extraction -> translation -> DOM write-back
 * All three phases are cleanly separated so each one can evolve independently
 * 
 * Usage:
 *  const engine        = new TranslationEngine({ targetLang: 'es' });
 *  const entries       = engine.extract(document.body);
 *  const translated    = await engine.translate(entries);
 *  engine.apply(translated);
 */
export class TranslationEngine
{
    extractor: DOMExtractor;
    translator: Translator;
    numericTemplates: NumericTemplateMemory | null;

    /**
     * @param {object}  [options]
     * @param {string}  [options.sourceLang='auto']                             Source Language tag
     * @param {string}  [options.targetLang='en']                               Target Language tag
     * @param {boolean} [options.skipDuplicates=false]                          Deduplicate identical strings
     * @param {string[]} [options.noTranslateClasses=[DEFAULT_NO_TRANSLATE_CLASS]] CSS classes that opt an element and its descendants out of translation
     * @param {string[]} [options.noTranslateIds=[]]                            Element IDs that opt an element and its descendants out of translation
     * @param {string} [options.noTranslateClass]                                Deprecated single-class alias kept for backward compatibility
     * @param {string} [options.noTranslateId]                                   Deprecated single-id alias kept for backward compatibility
     * @param {NumericTemplateMemory} [options.numericTemplates]                Serves a later value of an already translated numeric text locally
     */
    constructor({
        sourceLang = 'auto',
        targetLang = 'en',
        skipDuplicates = false,
        noTranslateClasses = [DEFAULT_NO_TRANSLATE_CLASS],
        noTranslateIds = [],
        noTranslateClass,
        noTranslateId,
        translator,
        numericTemplates = null,
    }: {
        sourceLang?: string,
        targetLang?: string,
        skipDuplicates?: boolean,
        noTranslateClasses?: string[],
        noTranslateIds?: string[],
        noTranslateClass?: string,
        noTranslateId?: string,
        translator?: Translator,
        numericTemplates?: NumericTemplateMemory | null,
    } = {})
    {
        this.extractor = new DOMExtractor({ skipDuplicates, noTranslateClasses, noTranslateIds, noTranslateClass, noTranslateId });
        this.translator = translator ?? new Translator({ sourceLang, targetLang });
        this.numericTemplates = numericTemplates;
    }

    /**
     * Phase 1: Extract translatable content from a DOM subtree
     * 
     * @param {Element}            root
     * @param {ExtractionOptions}  options
     * @returns {TranslatableEntry[]}
     */
    extract(root: Element, options: ExtractionOptions = DEFAULT_EXTRACTION_OPTIONS): TranslatableEntry[]
    {
        return this.extractor.extract(root, options);
    }

    /**
     * Phase 2: translate a set of extracted entries
     * Returns translated copies; originals are left unmodified
     * 
     * @param {TranslatableEntry[]}             entries
     * @returns {Promise<TranslatableEntry[]>}
     */
    async translate(entries: TranslatableEntry[]): Promise<TranslationBatchResult>
    {
        return this.translator.translateBatch(entries);
    }


    /**
     * Phase 3: Apply translations back to the DOM
     * 
     * @param {TranslatableEntry[]} translatedEntries
     */
    apply(translatedEntries: TranslatableEntry[]): void
    {
        for (const entry of translatedEntries)
        {
            DOMWriter.write(entry);
        }
    }

    /**
     * Re-apply pre-translation values (default language) to the DOM.
     * Use the `sourceEntries` returned from {@link TranslationEngine.run} before any further translation.
     */
    static restoreOriginals(sourceEntries: TranslatableEntry[]): void
    {
        for (const entry of sourceEntries)
        {
            DOMWriter.write(entry);
        }
    }

    /**
     * Convenience method to perform all three phases in sequence
     *
     * On any failure other than an abort, the pre-translation values are written back so the page
     * is never left partially translated.
     *
     * @param {Element}                     root
     * @param {ExtractionOptions}           extractionOptions
     * @param {object}                      [options]
     * @param {AbortSignal}                 [options.signal]        Cancels the run; pending writes are skipped
     * @param {Function}                    [options.onExtracted]   Receives the pre-translation entries as soon as extraction completes
     * @returns {Promise<{ translated: TranslatableEntry[], sourceEntries: TranslatableEntry[] }>}
     */
    async run(
        root: Element,
        extractionOptions: ExtractionOptions = DEFAULT_EXTRACTION_OPTIONS,
        options: TranslationRunOptions = {}
    ): Promise<TranslationEngineResult>
    {
        const extracted = this.extract(root, extractionOptions);
        options.onExtracted?.(extracted);

        return this.runEntries(extracted, options);
    }

    async runEntries(
        extracted: TranslatableEntry[],
        options: Omit<TranslationRunOptions, 'onExtracted'> = {}
    ): Promise<TranslationEngineResult>
    {
        DOMWriter.ensureLoadingStyles();

        const local: TranslatableEntry[] = [];
        const remote: TranslatableEntry[] = [];
        for (const entry of extracted)
        {
            const resolved = this.numericTemplates?.resolve(this.translator.targetLang, entry.value) ?? null;
            if (resolved === null)
            {
                remote.push(entry);
                continue;
            }
            local.push(entry.withTranslation(resolved));
        }
        for (const entry of local)
        {
            options.onEntryApplied?.(entry);
            const changed = options.onEntryWritten ? DOMWriter.read(entry) !== entry.value : false;
            DOMWriter.write(entry);
            if (options.onEntryWritten && entry.node.isConnected)
            {
                options.onEntryWritten({ entry, changed });
            }
        }

        const targetLang = this.translator.targetLang;
        const sourceById = new Map(remote.map(entry => [entry.id, entry]));
        for (const entry of remote)
        {
            this.numericTemplates?.markPending(targetLang, entry.value);
        }

        // Every entry marked as loading is unmarked exactly once: when its
        // translation lands, when its group fails, or when the batch settles
        // without either (a stream that closed with the entry unserved).
        const loading = new Set<TranslatableEntry>();
        const unmark = (entry: TranslatableEntry): void =>
        {
            if (!loading.delete(entry)) return;
            DOMWriter.setLoading(entry, false);
        };
        try
        {
            const result = await this.translator.translateBatch(remote, {
                signal: options.signal,
                onServePath: options.onServePath,
                onGroupServeStarted: (entries) =>
                {
                    for (const entry of entries)
                    {
                        if (loading.has(entry)) continue;
                        loading.add(entry);
                        DOMWriter.setLoading(entry, true);
                    }
                },
                onGroupServeFailed: (entries) =>
                {
                    for (const entry of entries) unmark(entry);
                },
                onEntryTranslated: (entry, served = true) =>
                {
                    if (options.signal?.aborted) return;
                    const applied = TranslationEngine.#withCurrentNumbers(sourceById.get(entry.id), entry);
                    if (applied)
                    {
                        options.onEntryApplied?.(applied);
                        const changed = options.onEntryWritten
                            ? DOMWriter.read(applied) !== applied.value
                            : false;
                        DOMWriter.write(applied);
                        if (options.onEntryWritten && served && applied.node.isConnected)
                        {
                            options.onEntryWritten({
                                entry: applied, changed, sourceValue: sourceById.get(entry.id)?.value,
                            });
                        }
                    }
                    unmark(sourceById.get(entry.id) ?? entry);
                }
            });
            for (const entry of [...loading]) unmark(entry);

            this.#rememberNumericTranslations(remote, result);
            return { ...result, translated: [...result.translated, ...local], sourceEntries: extracted };
        }
        catch (error)
        {
            if ((error as Error)?.name !== 'AbortError')
            {
                TranslationEngine.restoreOriginals(extracted);
                for (const entry of extracted)
                {
                    options.onEntryApplied?.(entry);
                }
            }

            throw error;
        }
        finally
        {
            for (const entry of remote)
            {
                this.numericTemplates?.clearPending(targetLang, entry.value);
            }
            for (const entry of [...loading]) unmark(entry);
            DOMWriter.clearLoading(extracted);
        }
    }

    static #withCurrentNumbers(source: TranslatableEntry | undefined, translated: TranslatableEntry): TranslatableEntry | null
    {
        if (!source) return translated;

        const current = DOMWriter.read(translated);
        if (current === null || current === source.value) return translated;
        if (normalizeNumbers(current) !== normalizeNumbers(source.value)) return translated;

        const substituted = substituteNumbers(current, translated.value);
        return substituted === null ? null : translated.withTranslation(substituted);
    }

    #rememberNumericTranslations(sources: TranslatableEntry[], result: TranslationBatchResult): void
    {
        if (!this.numericTemplates) return;

        const sourceById = new Map(sources.map(entry => [entry.id, entry.value]));
        for (const entry of result.translated)
        {
            const source = sourceById.get(entry.id);
            if (source === undefined) continue;
            const mayBeSourceKeptByFailure = entry.value === source && result.failures.length > 0;
            if (mayBeSourceKeptByFailure) continue;
            this.numericTemplates.remember(this.translator.targetLang, source, entry.value);
        }
    }
}

// --- DOM Write-Back ---

/**
 * Writes a single translated entry back into the DOM
 * Kept as a private static class to isolate DOM mutation from engine logic
 */
class DOMWriter
{
    static #loadingClassName = 'translatewp-translating';
    static #loadingCountAttribute = 'data-translatewp-loading-count';
    static #styleTagId = 'translatewp-translating-style';

    static ensureLoadingStyles(): void
    {
        if (document.getElementById(DOMWriter.#styleTagId)) return;

        const style = document.createElement('style');
        style.id = DOMWriter.#styleTagId;
        style.textContent = `
            .${DOMWriter.#loadingClassName} {
                color: transparent !important;
                border-radius: .2em;
                background-image: linear-gradient(
                    90deg,
                    rgba(237, 237, 237, .92) 25%,
                    rgba(245, 245, 245, .96) 37%,
                    rgba(237, 237, 237, .92) 63%
                );
                background-size: 400% 100%;
                animation: translatewp-skeleton-shimmer 1.2s ease-in-out infinite;
            }

            @keyframes translatewp-skeleton-shimmer {
                0% {
                    background-position: 100% 50%;
                }
                100% {
                    background-position: 0 50%;
                }
            }

            @media (prefers-reduced-motion: reduce) {
                .${DOMWriter.#loadingClassName} {
                    animation: none;
                    background-position: 50% 50%;
                }
            }
        `;

        document.head.appendChild(style);
    }

    // A run that is over leaves nothing marked: every element its entries
    // touched is cleared whatever the count reached, so a second start for the
    // same element (the cache lookup, then the miss path) cannot strand it.
    static clearLoading(entries: TranslatableEntry[]): void
    {
        for (const entry of entries)
        {
            const element = DOMWriter.#getLoadingElement(entry);
            if (!element) continue;
            element.removeAttribute(DOMWriter.#loadingCountAttribute);
            element.classList.remove(DOMWriter.#loadingClassName);
            element.removeAttribute('aria-busy');
        }
    }

    static setLoading(entry: TranslatableEntry, isLoading: boolean): void
    {
        if (!/\p{L}/u.test(entry.value)) return;

        const element = DOMWriter.#getLoadingElement(entry);
        if (!element) return;

        const currentCount = Number(element.getAttribute(DOMWriter.#loadingCountAttribute) || 0);

        if (isLoading)
        {
            const nextCount = currentCount + 1;
            element.setAttribute(DOMWriter.#loadingCountAttribute, String(nextCount));
            element.classList.add(DOMWriter.#loadingClassName);
            element.setAttribute('aria-busy', 'true');
            return;
        }

        const nextCount = Math.max(0, currentCount - 1);
        if (nextCount > 0)
        {
            element.setAttribute(DOMWriter.#loadingCountAttribute, String(nextCount));
            return;
        }

        element.removeAttribute(DOMWriter.#loadingCountAttribute);
        element.classList.remove(DOMWriter.#loadingClassName);
        element.removeAttribute('aria-busy');
    }

    static read(entry: TranslatableEntry): string | null
    {
        switch (entry.type)
        {
            case EntryType.TEXT:
                return entry.node.nodeValue;

            case EntryType.ALT:
            case EntryType.ATTRIBUTE:
                return (entry.node as Element).getAttribute(entry.attributeName ?? '');

            default:
                return null;
        }
    }

    /** @param {TranslatableEntry} entry */
    static write(entry: TranslatableEntry): void
    {
        switch (entry.type)
        {
            case EntryType.TEXT:
                entry.node.nodeValue = entry.value;
                break;

            case EntryType.ALT:
            case EntryType.ATTRIBUTE:
                (entry.node as Element).setAttribute(entry.attributeName ?? '', entry.value);
                break;

            default:
                console.warn(`[TranslationEngine] Unknown entry type: ${entry.type}`);
        }
    }

    static #getLoadingElement(entry: TranslatableEntry): Element | null
    {
        if (entry.type === EntryType.TEXT)
        {
            return entry.node.parentElement;
        }

        return entry.node instanceof Element ? entry.node : null;
    }
}