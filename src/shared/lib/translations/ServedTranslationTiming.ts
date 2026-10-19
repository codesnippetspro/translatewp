import { EntryType } from '@/shared/lib/translations/constants';
import type { TranslationEntryWrite } from '@/shared/lib/translations/TranslationEngine';
import type { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import type { TranslationBatchResult } from '@/shared/lib/translations/Translator';

export type ServedTimingEvent = {
	version: 1;
	kind: 'switch' | 'mutation';
	path: 'ready' | 'cold' | 'unknown';
	outcome: 'complete' | 'partial' | 'error' | 'timeout' | 'cancelled' | 'no_source';
	firstAppliedMs: number | null;
	firstVisibleAppliedMs?: number | null;
	completedMs: number | null;
	elapsedMs: number;
	sourceCount: number;
	appliedCount: number;
	failedGroups: number;
	pendingGroups: number;
	sampleRate: number;
};

export const SERVED_TIMING_TIMEOUT_MS = 120_000;
const SAMPLE_RATE = 1 / 32;
const MAX_DOCUMENT_EVENTS = 4;
const MAX_COUNT = 100_000;
const documentSamples = new WeakMap<Document, { selected: boolean; count: number }>();

export class ServedTranslationTiming
{
	#started = 0;
	#event!: ServedTimingEvent;
	#emit!: (event: ServedTimingEvent) => void;
	#sampled: boolean;
	#done = false;
	#timer?: ReturnType<typeof setTimeout>;
	#frame: number | null = null;
	#visibleCandidates?: Map<Text, string>;
	#sources?: Map<Node, Map<string, TranslatableEntry>>;
	#applied?: Map<Node, Map<string, TranslatableEntry>>;

	constructor({ kind, emit }: {
		kind: ServedTimingEvent['kind'];
		emit: (event: ServedTimingEvent) => void;
	})
	{
		const sample = documentSamples.get(document) ?? {
			selected: Math.random() < SAMPLE_RATE, count: 0,
		};
		documentSamples.set(document, sample);
		this.#sampled = sample.selected && sample.count < MAX_DOCUMENT_EVENTS;
		if (!this.#sampled) return;
		sample.count += 1;
		this.#started = performance.now();
		this.#sources = new Map();
		this.#applied = new Map();
		this.#visibleCandidates = new Map();
		this.#emit = emit;
		this.#event = {
			version: 1, kind, path: 'unknown', outcome: 'complete',
			firstAppliedMs: null, firstVisibleAppliedMs: null, completedMs: null, elapsedMs: 0,
			sourceCount: 0, appliedCount: 0, failedGroups: 0, pendingGroups: 0,
			sampleRate: SAMPLE_RATE,
		};
		this.#timer = setTimeout(() => this.finish('timeout'), SERVED_TIMING_TIMEOUT_MS);
	}

	get sampled(): boolean
	{
		return this.#sampled;
	}

	get finished(): boolean
	{
		return this.#done;
	}

	observe(entries: TranslatableEntry[]): void
	{
		if (!this.#sampled || this.#done) return;
		for (const entry of entries)
		{
			const key = entry.attributeName ?? '';
			const previous = this.#sources!.get(entry.node)?.get(key);
			if (previous?.id !== entry.id || previous.value !== entry.value)
			{
				this.#applied!.get(entry.node)?.delete(key);
				this.#visibleCandidates!.delete(entry.node as Text);
			}
			this.#record(this.#sources!, entry);
		}
	}

	written({ entry, changed, sourceValue }: TranslationEntryWrite): void
	{
		if (!this.#sampled || this.#done) return;
		if (!entry.node.isConnected) return;
		const expected = this.#sources!.get(entry.node)?.get(entry.attributeName ?? '');
		if (expected?.id !== entry.id
			|| (sourceValue !== undefined && expected.value !== sourceValue)) return;
		this.#record(this.#applied!, entry);
		if (changed && this.#event.firstAppliedMs === null) this.#event.firstAppliedMs = this.#elapsed();
		if (!changed || entry.type !== EntryType.TEXT
			|| this.#event.firstVisibleAppliedMs !== null) return;
		this.#visibleCandidates!.set(entry.node as Text, entry.value);
		if (this.#frame === null && typeof requestAnimationFrame === 'function')
		{
			this.#frame = requestAnimationFrame(() => this.#observeViewport());
		}
	}

	path(path: 'ready' | 'cold'): void
	{
		if (!this.#sampled || this.#done) return;
		if (this.#event.path !== 'cold') this.#event.path = path;
	}

	settled(result: TranslationBatchResult): void
	{
		if (!this.#sampled || this.#done) return;
		for (const failure of result.failures)
		{
			if (failure.error === 'translation_pending') this.#event.pendingGroups += 1;
			else this.#event.failedGroups += 1;
		}
	}

	finish(outcome?: ServedTimingEvent['outcome']): void
	{
		if (this.#done) return;
		this.#done = true;
		if (!this.#sampled) return;
		clearTimeout(this.#timer);
		if (this.#frame !== null) cancelAnimationFrame(this.#frame);
		this.#visibleCandidates!.clear();
		const count = (records: Map<Node, Map<string, TranslatableEntry>>): number => [...records]
			.reduce((sum, [node, keys]) => sum + (node.isConnected ? keys.size : 0), 0);
		const sourceCount = count(this.#sources!);
		const appliedCount = count(this.#applied!);
		if (!outcome)
		{
			if (!sourceCount) outcome = 'no_source';
			else if (this.#event.pendingGroups) outcome = 'timeout';
			else if (this.#event.failedGroups || appliedCount < sourceCount)
			{
				outcome = appliedCount ? 'partial' : 'error';
			}
			else outcome = 'complete';
		}
		const elapsedMs = this.#elapsed();
		const event: ServedTimingEvent = {
			...this.#event, outcome, elapsedMs,
			completedMs: outcome === 'complete' ? elapsedMs : null,
			sourceCount: Math.min(sourceCount, MAX_COUNT),
			appliedCount: Math.min(appliedCount, MAX_COUNT),
			failedGroups: Math.min(this.#event.failedGroups, MAX_COUNT),
			pendingGroups: Math.min(this.#event.pendingGroups, MAX_COUNT),
		};
		this.#sources!.clear();
		this.#applied!.clear();
		if (!this.#sampled) return;
		try { this.#emit(event); } catch {}
	}

	#observeViewport(): void
	{
		this.#frame = null;
		if (this.#done || document.visibilityState !== 'visible')
		{
			this.#visibleCandidates!.clear();
			return;
		}
		try
		{
			const width = window.innerWidth;
			const height = window.innerHeight;
			for (const [node, value] of this.#visibleCandidates!)
			{
				if (!node.isConnected || node.nodeValue !== value) continue;
				let rendered = true;
				for (let element = node.parentElement; element; element = element.parentElement)
				{
					const style = getComputedStyle(element);
					if (style.display === 'none' || style.visibility === 'hidden'
						|| style.visibility === 'collapse' || style.opacity === '0') rendered = false;
				}
				if (!rendered) continue;
				const range = document.createRange();
				if (typeof range.getClientRects !== 'function') continue;
				range.selectNodeContents(node);
				if (Array.from(range.getClientRects()).some(rect => rect.width > 0 && rect.height > 0
					&& rect.bottom > 0 && rect.right > 0 && rect.top < height && rect.left < width))
				{
					this.#event.firstVisibleAppliedMs = this.#elapsed();
					break;
				}
			}
		}
		catch {}
		finally { this.#visibleCandidates!.clear(); }
	}

	#elapsed(): number
	{
		const elapsed = Math.round(performance.now() - this.#started);
		return Math.min(SERVED_TIMING_TIMEOUT_MS, Math.max(0, elapsed));
	}

	#record(records: Map<Node, Map<string, TranslatableEntry>>, entry: TranslatableEntry): void
	{
		if (this.#done) return;
		const keys = records.get(entry.node) ?? new Map<string, TranslatableEntry>();
		keys.set(entry.attributeName ?? '', entry);
		records.set(entry.node, keys);
	}
}
