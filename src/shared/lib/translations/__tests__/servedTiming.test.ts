import { TranslationEngine } from '@/shared/lib/translations/TranslationEngine';
import { Translator } from '@/shared/lib/translations/Translator';

afterEach(() => { jest.restoreAllMocks(); document.body.innerHTML = ''; });

it('reports a changed connected DOM only after writing the translated value', async () =>
{
	document.body.innerHTML = '<p>Hello world</p>';
	const observed: string[] = [];
	jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async (entries, options) =>
	{
		const translated = entries.map(entry => entry.withTranslation('Bonjour le monde'));
		for (const entry of translated) options?.onEntryTranslated?.(entry);
		return { translated, failures: [], totalGroups: 1 };
	});
	const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'fr' });
	await engine.run(document.body, undefined, {
		onEntryWritten: ({ changed }) => { if (changed) observed.push(document.body.textContent!); },
	});
	expect(observed).toEqual(['Bonjour le monde']);
});

it('never reports source restoration or detached writes as translated DOM application', async () =>
{
	document.body.innerHTML = '<p>Hello world</p>';
	const observed: unknown[] = [];
	jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async (entries, options) =>
	{
		entries[0].node.parentElement!.remove();
		options?.onEntryTranslated?.(entries[0].withTranslation('Bonjour'));
		throw new Error('provider failed');
	});
	const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'fr' });
	await expect(engine.run(document.body, undefined, {
		onEntryWritten: entry => observed.push(entry),
	})).rejects.toThrow('provider failed');
	expect(observed).toEqual([]);
});

import { CHANGE_SETTLE_MS, TranslationSession } from '@/shared/lib/translations/TranslationSession';

it('aggregates first DOM and full view timing across overlapping mutation work', async () =>
{
	jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
	jest.spyOn(Math, 'random').mockReturnValue(0);
	document.body.innerHTML = '<p>Hello world</p>';
	const events: any[] = [];
	let release!: () => void;
	const gate = new Promise<void>(resolve => { release = resolve; });
	let calls = 0;
	jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async (entries, options) =>
	{
		options?.onServePath?.('ready');
		if (++calls === 1) await gate;
		const translated = entries.map(entry => entry.withTranslation(`fr:${entry.value}`));
		for (const entry of translated) options?.onEntryTranslated?.(entry);
		return { translated, failures: [], totalGroups: 1 };
	});
	const session = new TranslationSession({ root: document.body, sourceLang: 'en',
		onTiming: event => events.push(event) });
	try
	{
		const switching = session.switchTo('fr');
		await jest.advanceTimersByTimeAsync(10);
		document.body.insertAdjacentHTML('beforeend', '<footer>Contact us</footer>');
		await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS + 20);
		expect(document.querySelector('footer')!.textContent).toBe('fr:Contact us');
		expect(events).toEqual([]);
		release();
		await switching;
		await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS + 1);
		expect(events).toEqual([expect.objectContaining({
			version: 1, kind: 'switch', path: 'ready', outcome: 'complete',
			sourceCount: 2, appliedCount: 2, failedGroups: 0, pendingGroups: 0,
			firstAppliedMs: 410, completedMs: 830,
		})]);
	}
	finally { session.dispose(); jest.useRealTimers(); }
});

it('starts a fresh mutation round after a timed out switch without cancelling its translation', async () =>
{
	jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
	jest.spyOn(Math, 'random').mockReturnValue(0);
	document.body.innerHTML = '<p>Hello world</p>';
	const events: any[] = [];
	let release!: () => void;
	const gate = new Promise<void>(resolve => { release = resolve; });
	let calls = 0;
	let signal: AbortSignal | undefined;
	jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async (entries, options) =>
	{
		if (++calls === 1) { signal = options?.signal; await gate; }
		const translated = entries.map(entry => entry.withTranslation(`fr:${entry.value}`));
		for (const entry of translated) options?.onEntryTranslated?.(entry);
		return { translated, failures: [], totalGroups: 1 };
	});
	const session = new TranslationSession({ root: document.body, sourceLang: 'en',
		onTiming: event => events.push(event) });
	try
	{
		const switching = session.switchTo('fr');
		await jest.advanceTimersByTimeAsync(120001);
		expect(events).toEqual([expect.objectContaining({ outcome: 'timeout', completedMs: null })]);
		expect(signal?.aborted).toBe(false);
		release();
		await switching;
		document.body.insertAdjacentHTML('beforeend', '<footer>Contact us</footer>');
		await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS * 3);
		expect(events).toHaveLength(2);
		expect(events[1]).toMatchObject({ kind: 'mutation', outcome: 'complete', sourceCount: 1 });
	}
	finally { release(); session.dispose(); jest.useRealTimers(); }
});

it('performs no additional DOM read when there is no timing consumer', async () =>
{
	document.body.innerHTML = '<p>Hello world</p>';
	const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'fr' });
	const entries = engine.extract(document.body);
	const read = jest.spyOn(entries[0].node, 'nodeValue', 'get');
	jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async (sources, options) =>
	{
		const translated = sources.map(entry => entry.withTranslation('Bonjour'));
		for (const entry of translated) options?.onEntryTranslated?.(entry);
		return { translated, failures: [], totalGroups: 1 };
	});
	await engine.runEntries(entries);
	expect(document.body.textContent).toBe('Bonjour');
	expect(read).toHaveBeenCalledTimes(1);
});
