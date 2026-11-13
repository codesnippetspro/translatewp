import { Translator } from '@/shared/lib/translations/Translator';
import type { TranslationSession } from '@/shared/lib/translations/TranslationSession';
import type { ServedTimingEvent } from '@/shared/lib/translations/ServedTranslationTiming';

it('clears a cancelled settle timer so the next language can report completion', async () =>
{
    jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
    const random = jest.spyOn(Math, 'random').mockReturnValue(0);
    let Session!: typeof TranslationSession;
    let settleMs = 0;
    jest.isolateModules(() =>
    {
        ({ TranslationSession: Session, CHANGE_SETTLE_MS: settleMs } = require('../TranslationSession'));
    });
    let started!: () => void;
    const pending = new Promise<void>(resolve => { started = resolve; });
    const events: ServedTimingEvent[] = [];
    document.body.innerHTML = '<p>Original content</p>';
    const session = new Session({ root: document.body, sourceLang: 'en', onTiming: event => events.push(event),
        createTranslator: (sourceLang, targetLang) =>
        {
            const translator = new Translator({ sourceLang, targetLang });
            jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options) =>
            {
                started();
                const translated = entries.map(entry => entry.withTranslation(targetLang + ':' + entry.value));
                translated.forEach(entry => options?.onEntryTranslated?.(entry));
                return { translated, failures: [], totalGroups: 1 };
            });
            return translator;
        },
    });
    try
    {
        const warm = session.switchTo('de');
        await expect(Promise.race([pending, warm]).finally(() => random.mockRestore())).resolves.toBeUndefined();
        await warm;
        await jest.advanceTimersByTimeAsync(settleMs + 1);
        expect(events).toHaveLength(1);
        expect(events[0].outcome).toBe('complete');
        document.querySelector('p')!.firstChild!.nodeValue = 'Theme edit';
        await Promise.resolve();
        await Promise.resolve();
        await session.switchTo('it');
        await jest.advanceTimersByTimeAsync(settleMs + 1);
        expect(events).toHaveLength(2);
        expect(events[1]).toMatchObject({ kind: 'switch', outcome: 'complete' });
        expect(session.pendingTranslationCount()).toBe(0);
        expect(jest.getTimerCount()).toBe(0);
    }
    finally
    {
        session.dispose();
        jest.restoreAllMocks();
        jest.useRealTimers();
        document.body.innerHTML = '';
    }
});

it.each([
    ['Error', new Error('Provider failed')], ['undefined', undefined], ['null', null],
])('keeps error timing and restores later content after a %s rejection', async (_label, error) =>
{
    jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
    const random = jest.spyOn(Math, 'random').mockReturnValue(0);
    let Session!: typeof TranslationSession;
    let settleMs = 0;
    jest.isolateModules(() =>
    {
        ({ TranslationSession: Session, CHANGE_SETTLE_MS: settleMs } = require('../TranslationSession'));
    });
    let started!: () => void;
    let fail!: (reason: unknown) => void;
    const pending = new Promise<void>(resolve => { started = resolve; });
    const events: ServedTimingEvent[] = [];
    let calls = 0;
    document.body.innerHTML = '<p>Original content</p><p>Last paragraph</p>';
    const session = new Session({ root: document.body, sourceLang: 'en', onTiming: event => events.push(event),
        createTranslator: (sourceLang, targetLang) =>
        {
            const translator = new Translator({ sourceLang, targetLang });
            jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options) =>
            {
                if (++calls === 1)
                {
                    started();
                    options?.onEntryTranslated?.(entries[0].withTranslation(targetLang + ':' + entries[0].value));
                    await new Promise<void>((_resolve, reject) => { fail = reject; });
                }
                const translated = entries.map(entry => entry.withTranslation(targetLang + ':' + entry.value));
                translated.forEach(entry => options?.onEntryTranslated?.(entry));
                return { translated, failures: [], totalGroups: 1 };
            });
            return translator;
        },
    });
    let result: Promise<unknown> | undefined;
    try
    {
        result = session.switchTo('it');
        void result.catch(() => {});
        await expect(Promise.race([pending, result]).finally(() => random.mockRestore())).resolves.toBeUndefined();
        document.body.insertAdjacentHTML('beforeend', '<footer>During run</footer>');
        await jest.advanceTimersByTimeAsync(settleMs * 3);
        expect(document.querySelector('footer')?.textContent).toBe('it:During run');
        expect(events).toEqual([]);
        fail(error);
        await expect(result).rejects.toBe(error);
        expect(document.body.textContent).toBe('Original contentLast paragraphDuring run');
        expect(events).toEqual([expect.objectContaining({ outcome: 'error', completedMs: null, sourceCount: 3, appliedCount: 2 })]);
        document.body.insertAdjacentHTML('beforeend', '<aside>After failure</aside>');
        await jest.advanceTimersByTimeAsync(settleMs * 3);
        expect(document.querySelector('aside')?.textContent).toBe('After failure');
        expect(calls).toBe(2);
        expect(events).toHaveLength(1);
    }
    finally
    {
        session.dispose();
        fail?.(error);
        await result?.catch(() => {});
        jest.restoreAllMocks();
        jest.useRealTimers();
        document.body.innerHTML = '';
    }
});

it('finishes sampled timing as cancelled when a held session is disposed', async () =>
{
    jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
    const random = jest.spyOn(Math, 'random').mockReturnValue(0);
    let Session!: typeof TranslationSession;
    jest.isolateModules(() => { ({ TranslationSession: Session } = require('../TranslationSession')); });
    let started!: () => void;
    let release: (() => void) | undefined;
    const pending = new Promise<void>(resolve => { started = resolve; });
    const events: ServedTimingEvent[] = [];
    document.body.innerHTML = '<p>Original content</p>';
    const session = new Session({ root: document.body, sourceLang: 'en', onTiming: event => events.push(event),
        createTranslator: (sourceLang, targetLang) =>
        {
            const translator = new Translator({ sourceLang, targetLang });
            jest.spyOn(translator, 'translateBatch').mockImplementation((entries, options) =>
            {
                started();
                return new Promise(resolve => { release = () =>
                {
                    const translated = entries.map(entry => entry.withTranslation('Late translation'));
                    translated.forEach(entry => options?.onEntryTranslated?.(entry));
                    resolve({ translated, failures: [], totalGroups: 1 });
                }; });
            });
            return translator;
        },
    });
    const result = session.switchTo('it');
    void result.catch(() => {});
    try
    {
        await expect(Promise.race([pending, result]).finally(() => random.mockRestore())).resolves.toBeUndefined();
        session.dispose();
        await expect(result).rejects.toHaveProperty('name', 'AbortError');
        expect(events).toEqual([expect.objectContaining({ outcome: 'cancelled', firstAppliedMs: null, completedMs: null })]);
        release?.();
        await Promise.resolve();
        await Promise.resolve();
        expect(document.body.textContent).toBe('Original content');
        expect(events).toHaveLength(1);
    }
    finally
    {
        session.dispose();
        release?.();
        await result.catch(() => {});
        jest.restoreAllMocks();
        jest.useRealTimers();
        document.body.innerHTML = '';
    }
});
