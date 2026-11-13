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
