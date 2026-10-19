import { Translator } from '@/shared/lib/translations/Translator';
import { CHANGE_SETTLE_MS, TranslationSession } from '@/shared/lib/translations/TranslationSession';
import type { ServedTimingEvent } from '@/shared/lib/translations/ServedTranslationTiming';

it('resets timing and ignores aborted writes across language switches and later SPA content', async () =>
{
	jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
	jest.spyOn(Math, 'random').mockReturnValue(0);
	document.body.innerHTML = '<p>Hello world</p>';
	const events: ServedTimingEvent[] = [];
	let release!: () => void;
	const gate = new Promise<void>(resolve => { release = resolve; });
	let calls = 0;
	jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async function (entries, options)
	{
		if (++calls === 1) await gate;
		const translated = entries.map(entry => entry.withTranslation(`${this.targetLang}:${entry.value}`));
		for (const entry of translated) options?.onEntryTranslated?.(entry);
		return { translated, failures: [], totalGroups: 1 };
	});
	const session = new TranslationSession({ root: document.body, sourceLang: 'en',
		onTiming: event => events.push(event) });
	try
	{
		const first = session.switchTo('fr');
		await jest.advanceTimersByTimeAsync(10);
		const second = session.switchTo('de');
		expect(events[0]).toMatchObject({ outcome: 'cancelled', firstAppliedMs: null });
		release();
		await first;
		await second;
		await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS + 1);
		expect(document.querySelector('p')!.textContent).toBe('de:Hello world');
		expect(events[1]).toMatchObject({ outcome: 'complete', kind: 'switch', sourceCount: 1 });
		document.body.innerHTML = '<main>New route</main>';
		await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS * 3);
		expect(document.querySelector('main')!.textContent).toBe('de:New route');
		expect(events[2]).toMatchObject({ outcome: 'complete', kind: 'mutation', sourceCount: 1 });
		await session.switchTo('en');
		await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS + 1);
		expect(events).toHaveLength(3);
	}
	finally { release(); session.dispose(); jest.restoreAllMocks(); jest.useRealTimers(); }
});

it('runs an unsampled view without timing callbacks or timing timers', async () =>
{
	jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
	document.body.innerHTML = '<p>Hello world</p>';
	let Session: typeof TranslationSession;
	let Engine: typeof import('../TranslationEngine').TranslationEngine;
	jest.spyOn(Math, 'random').mockReturnValue(0.9);
	jest.isolateModules(() =>
	{
		Session = require('../TranslationSession').TranslationSession;
		Engine = require('../TranslationEngine').TranslationEngine;
	});
	const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });
	let appliedConsumer: unknown = 'not run';
	jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options) =>
	{
		const translated = entries.map(entry => entry.withTranslation('Bonjour'));
		for (const entry of translated) options?.onEntryTranslated?.(entry);
		return { translated, failures: [], totalGroups: 1 };
	});
	const run = Engine.prototype.runEntries;
	jest.spyOn(Engine.prototype, 'runEntries').mockImplementation(function (entries, options)
	{
		appliedConsumer = options?.onEntryWritten;
		return run.call(this, entries, options);
	});
	const session = new Session!({ root: document.body, sourceLang: 'en',
		createTranslator: () => translator });
	try
	{
		await session.switchTo('fr');
		expect(document.body.textContent).toBe('Bonjour');
		expect(appliedConsumer).toBeUndefined();
		expect(jest.getTimerCount()).toBe(0);
	}
	finally { session.dispose(); jest.restoreAllMocks(); jest.useRealTimers(); }
});
