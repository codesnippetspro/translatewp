import { EntryType } from '@/shared/lib/translations/constants';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import type { ServedTimingEvent } from '@/shared/lib/translations/ServedTranslationTiming';

let Timing: typeof import('../ServedTranslationTiming').ServedTranslationTiming;
let events: ServedTimingEvent[];
let round: InstanceType<typeof Timing>;
const source = (): TranslatableEntry =>
{
	document.body.innerHTML = '<p>Hello world</p><p>Contact us</p>';
	return new TranslatableEntry({ id: 'one', node: document.querySelector('p')!.firstChild!,
		type: EntryType.TEXT, attributeName: null, value: 'Hello world' });
};
beforeEach(() =>
{
	jest.useFakeTimers();
	Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
	jest.spyOn(Math, 'random').mockReturnValue(0);
	jest.isolateModules(() =>
	{
		Timing = require('../ServedTranslationTiming').ServedTranslationTiming;
	});
	events = [];
	round = new Timing({ kind: 'switch', emit: event => events.push(event) });
});
afterEach(() => { round.finish('cancelled'); jest.restoreAllMocks(); jest.useRealTimers(); });

it('keeps partial failures out of successful completion latency', () =>
{
	const entry = source();
	round.observe([entry]);
	jest.advanceTimersByTime(20);
	round.written({ entry, changed: true });
	round.path('ready');
	round.path('cold');
	round.path('ready');
	round.settled({ translated: [entry], totalGroups: 2,
		failures: [{ groupId: 'two', error: 'translation_failed' }] });
	jest.advanceTimersByTime(40);
	round.finish();
	expect(events).toEqual([expect.objectContaining({ outcome: 'partial', path: 'cold',
		firstAppliedMs: 20, completedMs: null, elapsedMs: 60, failedGroups: 1 })]);
});

it('records pending groups as timeout and no-source views without a completion time', () =>
{
	round.finish();
	expect(events[0]).toMatchObject({ outcome: 'no_source', sourceCount: 0,
		firstAppliedMs: null, completedMs: null });
	round = new Timing({ kind: 'mutation', emit: event => events.push(event) });
	round.observe([source()]);
	round.settled({ translated: [], totalGroups: 1,
		failures: [{ groupId: 'one', error: 'translation_pending' }] });
	round.finish();
	expect(events[1]).toMatchObject({ outcome: 'timeout', pendingGroups: 1, completedMs: null });
});

it('does not invent a first changed DOM for confirmed same-source answers', () =>
{
	const entry = source();
	round.observe([entry]);
	round.written({ entry, changed: false });
	round.finish();
	expect(events[0]).toMatchObject({ outcome: 'complete', firstAppliedMs: null,
		sourceCount: 1, appliedCount: 1 });
});

it('excludes detached sources from full view completeness', () =>
{
	const entry = source();
	round.observe([entry]);
	entry.node.parentElement!.remove();
	round.finish();
	expect(events[0]).toMatchObject({ outcome: 'no_source', sourceCount: 0, appliedCount: 0 });
});

it('emits at most one event per round and four per document even under repeated starts', () =>
{
	for (let index = 0; index < 100; index++)
	{
		round.finish('cancelled');
		round.finish('error');
		round = new Timing({ kind: 'mutation', emit: event => events.push(event) });
	}
	expect(events).toHaveLength(4);
	expect(events.every(event => event.outcome === 'cancelled')).toBe(true);
});

it('leaves unsampled rounds and a failing emitter outside translation outcomes', () =>
{
	round.finish('cancelled');
	round = new Timing({ kind: 'switch', emit: () => { throw new Error('collector down'); } });
	expect(() => round.finish()).not.toThrow();
	jest.spyOn(Math, 'random').mockReturnValue(0.9);
	jest.isolateModules(() => { Timing = require('../ServedTranslationTiming').ServedTranslationTiming; });
	round = new Timing({ kind: 'switch', emit: () => { throw new Error('collector down'); } });
	expect(() => round.finish()).not.toThrow();
	expect(events).toHaveLength(1);
});

it('observes visible changed text on a later frame without treating attributes as visible text', () =>
{
	const entry = source();
	Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
	const rectangle = { top: 10, left: 10, bottom: 30, right: 120, width: 110, height: 20 };
	jest.spyOn(document, 'createRange').mockReturnValue({
		selectNodeContents: () => {}, getClientRects: () => [rectangle],
	} as unknown as Range);
	const attribute = new TranslatableEntry({ id: 'alt', node: document.querySelector('p')!,
		type: EntryType.ATTRIBUTE, attributeName: 'title', value: 'Bonjour' });
	round.observe([entry, attribute]);
	jest.advanceTimersByTime(5);
	round.written({ entry: attribute, changed: true });
	jest.advanceTimersByTime(20);
	entry.node.nodeValue = 'Bonjour';
	round.written({ entry: entry.withTranslation('Bonjour'), changed: true });
	jest.advanceTimersByTime(20);
	round.finish();
	expect(events[0]).toMatchObject({ firstAppliedMs: 5, firstVisibleAppliedMs: 32 });
});

it.each(['hidden', 'offscreen', 'replaced', 'css_hidden'])(
	'never calls %s translated text a viewport application', mode =>
	{
		const entry = source();
		round.observe([entry]);
		Object.defineProperty(document, 'visibilityState', {
			configurable: true, value: mode === 'hidden' ? 'hidden' : 'visible',
		});
		jest.spyOn(document, 'createRange').mockReturnValue({
			selectNodeContents: () => {}, getClientRects: () => [{ top: mode === 'offscreen' ? 10000 : 10,
				bottom: mode === 'offscreen' ? 10020 : 30,
				left: 10, right: 120, width: 110, height: 20 }],
		} as unknown as Range);
		if (mode === 'css_hidden') entry.node.parentElement!.style.display = 'none';
		entry.node.nodeValue = 'Bonjour';
		round.written({ entry: entry.withTranslation('Bonjour'), changed: true });
		if (mode === 'replaced') entry.node.nodeValue = 'New source';
		jest.advanceTimersByTime(20);
		round.finish();
		expect(events[0]).toMatchObject({ firstAppliedMs: 0, firstVisibleAppliedMs: null });
	}
);

it('does no per-target work or deadline scheduling for an unsampled document', () =>
{
	round.finish('cancelled');
	jest.spyOn(Math, 'random').mockReturnValue(0.9);
	jest.isolateModules(() => { Timing = require('../ServedTranslationTiming').ServedTranslationTiming; });
	const timers = jest.getTimerCount();
	round = new Timing({ kind: 'switch', emit: () => { throw new Error('must not emit'); } });
	const targets = new Proxy([] as TranslatableEntry[], { get: () => { throw new Error('target work'); } });
	expect(() => round.observe(targets)).not.toThrow();
	expect(jest.getTimerCount()).toBe(timers);
});

it.each([false, true])('requires the latest source coverage when its new reply is served=%s', served =>
{
	const entry = source();
	round.observe([entry]);
	round.written({ entry, changed: true });
	const next = new TranslatableEntry({ id: 'new-source', node: entry.node,
		type: EntryType.TEXT, attributeName: null, value: 'New source' });
	round.observe([next]);
	round.written({ entry, changed: true });
	if (served) round.written({ entry: next.withTranslation('Nouveau'), changed: true });
	round.written({ entry, changed: true });
	round.finish();
	expect(events[0]).toMatchObject({ outcome: served ? 'complete' : 'error',
		sourceCount: 1, appliedCount: served ? 1 : 0, completedMs: served ? 0 : null });
});

it('ignores an old source reply even when an entry ID was reused', () =>
{
	const entry = source();
	round.observe([entry]);
	round.written({ entry, changed: true, sourceValue: 'Hello world' });
	round.observe([entry.withTranslation('New source')]);
	round.written({ entry: entry.withTranslation('Bonjour'), changed: true,
		sourceValue: 'Hello world' });
	round.finish();
	expect(events[0]).toMatchObject({ outcome: 'error', appliedCount: 0, completedMs: null });
});
