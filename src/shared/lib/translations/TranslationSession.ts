import {
    ServedTranslationTiming,
    type ServedTimingEvent,
} from '@/shared/lib/translations/ServedTranslationTiming';
import {
    DEFAULT_EXTRACTION_OPTIONS,
    EntryType,
    type EntryTypeValue,
    type ExtractionOptions,
    TRANSLATABLE_ATTRIBUTES,
} from '@/shared/lib/translations/constants';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import {
    TranslationEngine,
    type TranslationRunOptions,
} from '@/shared/lib/translations/TranslationEngine';
import type { Translator } from '@/shared/lib/translations/Translator';
import { NumericTemplateMemory } from '@/shared/lib/translations/NumericTemplateMemory';
import { normalizeNumbers } from '@/shared/lib/translations/numericText';
import { VolatilityTracker } from '@/shared/lib/translations/VolatilityTracker';

type SourceRecord = {
    type: EntryTypeValue;
    node: Node;
    attributeName: string | null;
    value: string;
};

type TranslationSessionOptions = {
    root: Element;
    sourceLang: string;
    extractionOptions?: ExtractionOptions;
    noTranslateIds?: string[];
    onError?: (error: unknown) => void;
    onTiming?: (event: ServedTimingEvent) => void;
    onRehydrated?: VoidFunction;
    createTranslator?: (sourceLang: string, targetLang: string) => Translator;
};

export type TranslationSessionResult = Awaited<ReturnType<TranslationEngine['run']>>;

export const CHANGE_SETTLE_MS = 400;

export class TranslationSession
{
    #timing: ServedTranslationTiming | null = null;
    #timingSettle?: ReturnType<typeof setTimeout>;
    #onTiming?: (event: ServedTimingEvent) => void;
    #activeController: AbortController | null = null;
    #activeTarget: string;
    #disposed = false;
    #dynamicEntryCounter = 0;
    #expectedWrites = new Map<Node, Map<string, string>>();
    #extractionOptions: ExtractionOptions;
    #generation = 0;
    #inFlightTranslations = 0;
    #lastServed = new Map<Node, Map<string, { source: string; written: string }>>();
    #sourceByTranslation = new Map<string, Map<string, string>>();
    #mutationFlushScheduled = false;
    #mutationFlushDeferred = false;
    #mutationInFlight = false;
    #noTranslateIds: string[];
    #numericTemplates = new NumericTemplateMemory();
    #observer: MutationObserver | null = null;
    #onError?: (error: unknown) => void;
    #onRehydrated?: VoidFunction;
    #createTranslator?: (sourceLang: string, targetLang: string) => Translator;
    #operation: Promise<void> = Promise.resolve();
    #mutationControllers = new Set<AbortController>();
    #mutationOperation: Promise<void> = Promise.resolve();
    #pendingEntries = new Map<Node, Map<string, TranslatableEntry>>();
    #root: Element;
    #settleTimers = new Map<Node, Map<string, ReturnType<typeof setTimeout>>>();
    #sourceEngine: TranslationEngine;
    #sourceLang: string;
    #sourceRecords = new Map<Node, Map<string, SourceRecord>>();
    #volatility = new VolatilityTracker();

    constructor({
        root,
        sourceLang,
        extractionOptions = DEFAULT_EXTRACTION_OPTIONS,
        noTranslateIds = [],
        onError,
        onTiming,
        onRehydrated,
        createTranslator,
    }: TranslationSessionOptions)
    {
        this.#root = root;
        this.#sourceLang = sourceLang;
        this.#activeTarget = sourceLang;
        this.#extractionOptions = extractionOptions;
        this.#noTranslateIds = noTranslateIds;
        this.#createTranslator = createTranslator;
        this.#onError = onError;
        this.#onTiming = onTiming;
        this.#onRehydrated = onRehydrated;
        this.#sourceEngine = this.#createEngine(sourceLang);
        this.#captureOriginals(this.#sourceEngine.extract(root, extractionOptions));
        this.#observeMutations();
    }

    switchTo(targetLang: string): Promise<TranslationSessionResult>
    {
        this.#finishTiming('cancelled');
        const generation = ++this.#generation;
        this.#activeController?.abort();
        this.#abortMutationFlushes();

        const operation = this.#operation
            .catch(() => {})
            .then(() => this.#performSwitch(targetLang, generation));

        this.#operation = operation.then(() => undefined, () => undefined);
        return operation;
    }

    dispose(): void
    {
        this.#finishTiming('cancelled');
        this.#disposed = true;
        this.#generation += 1;
        this.#activeController?.abort();
        this.#activeController = null;
        this.#abortMutationFlushes();
        this.#observer?.disconnect();
        this.#observer = null;
        this.#pendingEntries.clear();
    }

    // How many translation operations still hold unserved
    // groups on this page: the switch and every re-hydration flush together.
    // The pending line is bound to this page-wide count, not to a single
    // operation, so a clean flush clears the line only when it is the last work.
    pendingTranslationCount(): number
    {
        return this.#inFlightTranslations;
    }

    #abortMutationFlushes(): void
    {
        for (const controller of this.#mutationControllers)
        {
            controller.abort();
        }
        this.#mutationControllers.clear();

        for (const timers of this.#settleTimers.values())
        {
            for (const timer of timers.values())
            {
                clearTimeout(timer);
            }
        }
        this.#settleTimers.clear();
    }

    async #performSwitch(targetLang: string, generation: number): Promise<TranslationSessionResult>
    {
        if (this.#disposed || generation !== this.#generation)
        {
            throw new DOMException('[TranslationSession] Translation aborted.', 'AbortError');
        }

        this.#activeTarget = this.#sourceLang;
        this.#restoreOriginals();

        if (targetLang === this.#sourceLang)
        {
            const sourceEntries = this.#connectedSourceEntries();
            return {
                translated: sourceEntries,
                failures: [],
                totalGroups: 0,
                sourceEntries,
            };
        }

        const controller = new AbortController();
        this.#activeController = controller;
        this.#activeTarget = targetLang;
        this.#inFlightTranslations += 1;
        const engine = this.#createEngine(targetLang);
        const timing = this.#startTiming('switch', engine.translator);

        try
        {
            const extracted = engine.extract(this.#root, this.#extractionOptions);
            this.#captureOriginals(extracted);
            const translatable = extracted.filter(
                entry => !this.#volatility.record(entry.node, this.#volatilityValue(targetLang, entry))
            );

            timing.observe(translatable);
            const result = await engine.runEntries(translatable, {
                signal: controller.signal,
                onEntryApplied: entry => this.#rememberExpectedWrite(entry),
                ...this.#timingRunOptions(timing),
            });
            timing.settled(result);
            result.sourceEntries = extracted;
            const rejectedFailures = result.failures.filter(({ error }) => error !== 'translation_pending');
            if (result.totalGroups > 0 && rejectedFailures.length === result.totalGroups)
            {
                this.#activeTarget = this.#sourceLang;
                this.#restoreOriginals();
            }
            return result;
        }
        catch (error)
        {
            timing.finish((error as Error)?.name === 'AbortError' ? 'cancelled' : 'error');
            if ((error as Error)?.name !== 'AbortError')
            {
                this.#activeTarget = this.#sourceLang;
                this.#restoreOriginals();
            }
            throw error;
        }
        finally
        {
            this.#inFlightTranslations -= 1;
            this.#settleTiming();
            if (this.#activeController === controller)
            {
                this.#activeController = null;
            }
        }
    }

    #startTiming(kind: ServedTimingEvent['kind'], translator: Translator): ServedTranslationTiming
    {
        clearTimeout(this.#timingSettle);
        this.#timing = new ServedTranslationTiming({
            kind,
            emit: event => this.#onTiming ? this.#onTiming(event) : translator.reportServedTiming(event),
        });
        return this.#timing;
    }

    #timingRunOptions(timing: ServedTranslationTiming): TranslationRunOptions
    {
        if (!timing.sampled) return {};
        return {
            onEntryWritten: write => timing.written(write),
            onServePath: path => timing.path(path),
        };
    }

    #finishTiming(outcome?: ServedTimingEvent['outcome']): void
    {
        clearTimeout(this.#timingSettle);
        this.#timing?.finish(outcome);
        this.#timing = null;
    }

    #settleTiming(): void
    {
        clearTimeout(this.#timingSettle);
        if (!this.#timing?.sampled) return;
        this.#timingSettle = setTimeout(() =>
        {
            if (this.#inFlightTranslations || this.#settleTimers.size || this.#pendingEntries.size) return;
            this.#finishTiming();
        }, CHANGE_SETTLE_MS);
    }

    #createEngine(targetLang: string): TranslationEngine
    {
        return new TranslationEngine({
            sourceLang: this.#sourceLang,
            targetLang,
            noTranslateIds: this.#noTranslateIds,
            translator: this.#createTranslator?.(this.#sourceLang, targetLang),
            numericTemplates: targetLang === this.#sourceLang ? null : this.#numericTemplates,
        });
    }

    #captureOriginals(entries: TranslatableEntry[], overwrite: boolean = false): void
    {
        for (const entry of entries)
        {
            const key = this.#entryKey(entry.attributeName);
            const nodeRecords = this.#sourceRecords.get(entry.node) ?? new Map<string, SourceRecord>();
            if (!overwrite && nodeRecords.has(key)) continue;

            nodeRecords.set(key, {
                type: entry.type,
                node: entry.node,
                attributeName: entry.attributeName,
                value: entry.value,
            });
            this.#sourceRecords.set(entry.node, nodeRecords);
        }
    }

    #connectedSourceEntries(): TranslatableEntry[]
    {
        const entries: TranslatableEntry[] = [];

        for (const [node, records] of this.#sourceRecords)
        {
            if (!node.isConnected)
            {
                this.#sourceRecords.delete(node);
                this.#expectedWrites.delete(node);
                continue;
            }

            for (const record of records.values())
            {
                entries.push(new TranslatableEntry({
                    id: this.#nextDynamicId(),
                    type: record.type,
                    node: record.node,
                    attributeName: record.attributeName,
                    value: record.value,
                }));
            }
        }

        return entries;
    }

    #restoreOriginals(): void
    {
        const entries = this.#connectedSourceEntries();
        for (const entry of entries)
        {
            this.#rememberExpectedWrite(entry);
        }
        TranslationEngine.restoreOriginals(entries);
    }

    #observeMutations(): void
    {
        if (typeof MutationObserver === 'undefined') return;

        this.#observer = new MutationObserver(records => this.#handleMutations(records));
        const attributeFilter = [
            ...(this.#extractionOptions.includeAlt ? ['alt'] : []),
            ...(this.#extractionOptions.includeAttributes ? TRANSLATABLE_ATTRIBUTES : []),
        ];

        this.#observer.observe(this.#root, {
            subtree: true,
            childList: true,
            characterData: true,
            attributes: attributeFilter.length > 0,
            ...(attributeFilter.length > 0 ? { attributeFilter } : {}),
        });
    }

    #handleMutations(records: MutationRecord[]): void
    {
        if (this.#activeTarget === this.#sourceLang) return;

        for (const record of records)
        {
            if (record.type === 'childList')
            {
                const removedNodes = Array.from(record.removedNodes);
                const addedNodes = Array.from(record.addedNodes);
                const pairedNodeCount = Math.min(removedNodes.length, addedNodes.length);

                for (let index = 0; index < pairedNodeCount; index += 1)
                {
                    this.#transferOriginals(removedNodes[index], addedNodes[index]);
                }

                for (const node of addedNodes)
                {
                    this.#settleExternalEntries(this.#extractEntriesFromAddedNode(node));
                }
                continue;
            }

            const attributeName = record.type === 'attributes' ? record.attributeName : null;
            if (this.#isOwnWriteBack(record.target, attributeName)) continue;
            if (this.#volatility.isVolatile(record.target)) continue;

            const entry = this.#extractEntryForMutation(record.target, attributeName);
            if (entry)
            {
                this.#settleExternalEntries([entry]);
            }
        }
    }

    #settleExternalEntries(entries: TranslatableEntry[]): void
    {
        const immediate: TranslatableEntry[] = [];

        for (const entry of entries)
        {
            if (!entry.hasContent) continue;

            if (this.#numericTemplates.resolve(this.#activeTarget, entry.value) !== null)
            {
                immediate.push(entry);
                continue;
            }
            if (this.#numericTemplates.isPending(this.#activeTarget, entry.value)) continue;

            const keyNode = entry.node instanceof Element ? entry.node : entry.node.parentElement;
            if (!keyNode) continue;

            const key = this.#entryKey(entry.attributeName);
            const served = this.#lastServed.get(keyNode)?.get(key);
            const writtenBack = served !== undefined && served.written !== entry.value && served.source === entry.value;
            if (writtenBack && this.#volatility.strike(keyNode)) continue;
            const timers = this.#settleTimers.get(keyNode) ?? new Map<string, ReturnType<typeof setTimeout>>();
            const pending = timers.get(key);
            if (pending !== undefined) clearTimeout(pending);

            timers.set(key, setTimeout(() =>
            {
                timers.delete(key);
                if (!timers.size) this.#settleTimers.delete(keyNode);
                if (this.#disposed) return;

                this.#queueExternalEntries(this.#settledEntries(keyNode, entry.attributeName));
            }, CHANGE_SETTLE_MS));
            this.#settleTimers.set(keyNode, timers);
        }

        if (immediate.length)
        {
            this.#queueExternalEntries(immediate);
        }
    }

    #settledEntries(keyNode: Element, attributeName: string | null): TranslatableEntry[]
    {
        if (attributeName)
        {
            const entry = this.#extractEntryForMutation(keyNode, attributeName);
            return entry ? [entry] : [];
        }

        return this.#sourceEngine
            .extract(keyNode, this.#extractionOptions)
            .filter(entry => entry.type === EntryType.TEXT && entry.node.parentNode === keyNode);
    }

    #extractEntriesFromAddedNode(node: Node): TranslatableEntry[]
    {
        if (node instanceof Element)
        {
            return this.#sourceEngine.extract(node, this.#extractionOptions);
        }

        if (node instanceof Text && node.parentElement)
        {
            return this.#sourceEngine
                .extract(node.parentElement, this.#extractionOptions)
                .filter(entry => entry.node === node);
        }

        return [];
    }

    #transferOriginals(removedNode: Node, addedNode: Node): void
    {
        const removedEntries = this.#extractEntriesFromAddedNode(removedNode);
        const addedEntries = this.#extractEntriesFromAddedNode(addedNode);
        if (removedEntries.length !== addedEntries.length) return;

        for (let index = 0; index < removedEntries.length; index += 1)
        {
            const removedEntry = removedEntries[index];
            const addedEntry = addedEntries[index];
            if (
                removedEntry.type !== addedEntry.type
                || removedEntry.attributeName !== addedEntry.attributeName
                || removedEntry.value !== addedEntry.value
            ) return;
        }

        for (let index = 0; index < removedEntries.length; index += 1)
        {
            const removedEntry = removedEntries[index];
            const addedEntry = addedEntries[index];
            const sourceRecord = this.#sourceRecords
                .get(removedEntry.node)
                ?.get(this.#entryKey(removedEntry.attributeName));
            if (!sourceRecord) continue;

            const key = this.#entryKey(addedEntry.attributeName);
            const records = this.#sourceRecords.get(addedEntry.node) ?? new Map<string, SourceRecord>();
            records.set(key, {
                ...sourceRecord,
                node: addedEntry.node,
            });
            this.#sourceRecords.set(addedEntry.node, records);

            const expectedWrite = this.#expectedWrites.get(removedEntry.node)?.get(key);
            if (expectedWrite === undefined) continue;
            const writes = this.#expectedWrites.get(addedEntry.node) ?? new Map<string, string>();
            writes.set(key, expectedWrite);
            this.#expectedWrites.set(addedEntry.node, writes);
        }
    }

    #extractEntryForMutation(node: Node, attributeName: string | null): TranslatableEntry | null
    {
        const root = node instanceof Element ? node : node.parentElement;
        if (!root) return null;

        return this.#sourceEngine
            .extract(root, this.#extractionOptions)
            .find(entry => entry.node === node && entry.attributeName === attributeName)
            ?? null;
    }

    #queueExternalEntries(entries: TranslatableEntry[]): void
    {
        for (const entry of entries)
        {
            if (!entry.hasContent) continue;

            const key = this.#entryKey(entry.attributeName);
            const sourceRecord = this.#sourceRecords.get(entry.node)?.get(key);
            const holdsOwnWrite = this.#expectedWrites.get(entry.node)?.get(key) === entry.value;
            const dynamicEntry = new TranslatableEntry({
                id: this.#nextDynamicId(),
                type: entry.type,
                node: entry.node,
                attributeName: entry.attributeName,
                value: holdsOwnWrite && sourceRecord ? sourceRecord.value : this.#sourceOfCopy(entry.value),
            });
            const nodeEntries = this.#pendingEntries.get(entry.node) ?? new Map<string, TranslatableEntry>();

            nodeEntries.set(key, dynamicEntry);
            this.#pendingEntries.set(entry.node, nodeEntries);
            this.#captureOriginals([dynamicEntry], !holdsOwnWrite);
        }

        if (this.#activeTarget !== this.#sourceLang)
        {
            this.#scheduleMutationFlush();
        }
    }

    #volatilityValue(targetLang: string, entry: TranslatableEntry): string
    {
        return this.#numericTemplates.resolve(targetLang, entry.value) === null
            ? entry.value
            : normalizeNumbers(entry.value);
    }

    #scheduleMutationFlush(): void
    {
        if (this.#mutationFlushScheduled) return;
        this.#mutationFlushScheduled = true;

        queueMicrotask(() =>
        {
            this.#mutationFlushScheduled = false;
            // Nodes that settle while a flush is still being served wait for
            // it and go out together in the next one, so a widget that adds
            // its items one by one costs one round trip, not one per item.
            if (this.#mutationInFlight)
            {
                this.#mutationFlushDeferred = true;
                return;
            }
            const targetLang = this.#activeTarget;
            const entries = Array.from(this.#pendingEntries.values())
                .flatMap(nodeEntries => Array.from(nodeEntries.values()))
                .filter(entry => entry.node.isConnected)
                .filter(entry => !this.#volatility.record(entry.node, this.#volatilityValue(targetLang, entry)));
            this.#pendingEntries.clear();

            if (!entries.length || this.#disposed || targetLang === this.#sourceLang)
            {
                this.#settleTiming();
                return;
            }

            const generation = this.#generation;
            this.#mutationInFlight = true;
            // Re-hydrate on a chain of its own so the nodes the theme added or
            // replaced after extraction translate without waiting for the
            // active switch's miss-poll window to close; that window can run to
            // the poll timeout, which would otherwise leave re-rendered content
            // in the source language for its whole duration.
            const operation = this.#mutationOperation
                .catch(() => {})
                .then(async () =>
                {
                    if (
                        this.#disposed
                        || generation !== this.#generation
                        || targetLang !== this.#activeTarget
                    ) return;

                    const controller = new AbortController();
                    this.#mutationControllers.add(controller);
                    this.#inFlightTranslations += 1;

                    let rehydrated = false;
                    try
                    {
                        const engine = this.#createEngine(targetLang);
                        const timing = this.#timing && !this.#timing.finished
                            ? this.#timing
                            : this.#startTiming('mutation', engine.translator);
                        timing.observe(entries);
                        const result = await engine.runEntries(entries, {
                            signal: controller.signal,
                            onEntryApplied: entry => this.#rememberExpectedWrite(entry),
                            ...this.#timingRunOptions(timing),
                        });
                        timing.settled(result);
                        // A group that could not be served keeps its source text
                        // and stays queued for a later view.
                        rehydrated = !result.failures.length;
                    }
                    finally
                    {
                        this.#mutationControllers.delete(controller);
                        this.#inFlightTranslations -= 1;
                        this.#settleTiming();
                    }

                    // A re-hydration flush that serves the
                    // content the theme re-rendered after the switch notifies
                    // the app, which clears the pending line only when nothing
                    // else on the page is still pending. The decrement above
                    // runs first so this flush no longer counts itself; the
                    // switch poll, if still open, keeps the line visible.
                    if (rehydrated)
                    {
                        this.#onRehydrated?.();
                    }
                });

            this.#mutationOperation = operation.then(() => undefined, () => undefined);
            void operation.catch(error =>
            {
                if ((error as Error)?.name !== 'AbortError')
                {
                    if (generation === this.#generation) this.#finishTiming('error');
                    this.#onError?.(error);
                }
            }).finally(() =>
            {
                this.#mutationInFlight = false;
                if (this.#mutationFlushDeferred && !this.#disposed)
                {
                    this.#mutationFlushDeferred = false;
                    this.#scheduleMutationFlush();
                }
            });
        });
    }

    #rememberExpectedWrite(entry: TranslatableEntry): void
    {
        const key = this.#entryKey(entry.attributeName);
        const nodeWrites = this.#expectedWrites.get(entry.node) ?? new Map<string, string>();
        nodeWrites.set(key, entry.value);
        this.#expectedWrites.set(entry.node, nodeWrites);

        const source = this.#sourceRecords.get(entry.node)?.get(key)?.value;
        const keyNode = entry.node instanceof Element ? entry.node : entry.node.parentElement;
        if (source === undefined || !keyNode) return;
        const served = this.#lastServed.get(keyNode) ?? new Map<string, { source: string; written: string }>();
        served.set(key, { source, written: entry.value });
        this.#lastServed.set(keyNode, served);
        this.#rememberTranslation(source, entry.value);
    }

    #rememberTranslation(source: string, written: string): void
    {
        const translation = written.trim();
        if (!translation || translation === source.trim() || this.#activeTarget === this.#sourceLang) return;
        const byTranslation = this.#sourceByTranslation.get(this.#activeTarget) ?? new Map<string, string>();
        if (!byTranslation.has(translation)) byTranslation.set(translation, source);
        this.#sourceByTranslation.set(this.#activeTarget, byTranslation);
    }

    // A theme that builds new nodes from text the engine already wrote (a
    // select menu copying its translated options) must not register that
    // translation as new source text; the copy takes the source exactly as it
    // was recorded, so it shares the original's hash and is served from cache.
    #sourceOfCopy(value: string): string
    {
        return this.#sourceByTranslation.get(this.#activeTarget)?.get(value.trim()) ?? value;
    }

    // A write-back must never re-queue its own mutation. A single engine write
    // can surface as more than one observer record (a restore then a translate
    // collapse into one delivery, and the observer can repeat a record), so the
    // expectation is kept rather than consumed: every record whose value still
    // equals the last value the engine wrote for this node is absorbed. The
    // expectation is superseded by the next write to the node; a genuinely
    // different value is external content and is re-queued.
    #isOwnWriteBack(node: Node, attributeName: string | null): boolean
    {
        const expected = this.#expectedWrites.get(node)?.get(this.#entryKey(attributeName));
        return expected !== undefined && expected === this.#readValue(node, attributeName);
    }

    #readValue(node: Node, attributeName: string | null): string
    {
        if (attributeName && node instanceof Element)
        {
            return node.getAttribute(attributeName) ?? '';
        }
        return node.nodeValue ?? '';
    }

    #entryKey(attributeName: string | null): string
    {
        return attributeName ? `@${attributeName}` : '#text';
    }

    #nextDynamicId(): string
    {
        this.#dynamicEntryCounter += 1;
        return `session_${String(this.#dynamicEntryCounter).padStart(8, '0')}`;
    }
}
