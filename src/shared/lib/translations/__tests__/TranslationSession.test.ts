import { Translator } from '@/shared/lib/translations/Translator';
import { TranslationEngine } from '@/shared/lib/translations/TranslationEngine';
import { CHANGE_SETTLE_MS, TranslationSession } from '@/shared/lib/translations/TranslationSession';
import { VolatilityTracker } from '@/shared/lib/translations/VolatilityTracker';

const EXTRACTION_OPTIONS = {
    includeAlt: false,
    includeAttributes: false,
};

const flushMutations = async (): Promise<void> =>
{
    await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS + 1);
};

const sentValues = (): string[] => (Translator.prototype.translateBatch as jest.Mock).mock.calls
    .flatMap(call => (call[0] as Array<{ value: string }>).map(entry => entry.value));

const word = (index: number): string =>
    String.fromCharCode(97 + (index % 26)) + String.fromCharCode(97 + Math.floor(index / 26));

const sessions: TranslationSession[] = [];

const createSession = (options: ConstructorParameters<typeof TranslationSession>[0]): TranslationSession =>
{
    const session = new TranslationSession(options);
    sessions.push(session);
    return session;
};

describe('TranslationSession', () =>
{
    beforeEach(() =>
    {
        jest.useFakeTimers({ doNotFake: ['queueMicrotask', 'nextTick'] });
        jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(async function (entries, options = {})
        {
            if (options.signal?.aborted)
            {
                throw new DOMException('Translation aborted.', 'AbortError');
            }

            const translated = entries.map(entry => entry.withTranslation(`${this.targetLang}:${entry.value}`));
            for (const entry of translated)
            {
                options.onEntryTranslated?.(entry);
            }
            return {
                translated,
                failures: [],
                totalGroups: translated.length > 0 ? 1 : 0,
            };
        });
    });

    afterEach(() =>
    {
        for (const session of sessions.splice(0)) session.dispose();
        jest.restoreAllMocks();
        jest.useRealTimers();
        document.body.innerHTML = '';
    });

    it('restores originals after a translated subtree is replaced', async () =>
    {
        document.body.innerHTML = '<footer><p>English footer</p></footer>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('fr');
        const footer = document.querySelector('footer');
        footer?.replaceWith(footer.cloneNode(true));
        await flushMutations();

        expect(document.querySelector('footer p')?.textContent).toBe('fr:English footer');

        const restored = await session.switchTo('en');

        expect(document.querySelector('footer p')?.textContent).toBe('English footer');
        expect(restored.failures).toEqual([]);
        expect(restored.totalGroups).toBe(0);
        session.dispose();
    });

    it('translates content inserted while a target language is active', async () =>
    {
        document.body.innerHTML = '<main><p>Welcome</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('de');
        document.body.insertAdjacentHTML('beforeend', '<footer><p>Contact us</p></footer>');
        await flushMutations();

        expect(document.querySelector('footer p')?.textContent).toBe('de:Contact us');

        await session.switchTo('en');

        expect(document.querySelector('footer p')?.textContent).toBe('Contact us');
        session.dispose();
    });

    it('treats external text mutations as new source content', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('de');
        const paragraph = document.querySelector('p');
        if (paragraph)
        {
            paragraph.textContent = 'Updated content';
        }
        await flushMutations();

        expect(paragraph?.textContent).toBe('de:Updated content');

        await session.switchTo('en');

        expect(paragraph?.textContent).toBe('Updated content');
        session.dispose();
    });

    it('maps a node copied from a translation back to its source instead of sending the translation', async () =>
    {
        document.body.innerHTML = '<form><select><option>Street dance</option></select></form>';
        const session = createSession({
            root: document.body,
            sourceLang: 'lv',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('en');
        const option = document.querySelector('option');
        expect(option?.textContent).toBe('en:Street dance');

        // A select-menu widget builds its list from the options it finds, which
        // are already translated: the item is born holding the translation.
        document.body.insertAdjacentHTML('beforeend', `<ul><li><div> ${option?.textContent} </div></li></ul>`);
        await flushMutations();

        expect(sentValues()).toEqual(['Street dance', 'Street dance']);
        expect(document.querySelector('li div')?.textContent).toBe('en:Street dance');

        await session.switchTo('it');

        expect(sentValues()).toEqual(['Street dance', 'Street dance', 'Street dance', 'Street dance']);
        expect(document.querySelector('option')?.textContent).toBe('it:Street dance');
        expect(document.querySelector('li div')?.textContent).toBe('it:Street dance');

        await session.switchTo('lv');

        expect(document.querySelector('li div')?.textContent).toBe('Street dance');
        session.dispose();
    });

    it('serves nodes that settle while a flush is in flight in one later flush, not one each', async () =>
    {
        document.body.innerHTML = '<main><p>Welcome</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        await session.switchTo('de');
        const batchSizes: number[] = [];
        (Translator.prototype.translateBatch as jest.Mock).mockImplementation(async function (entries, options = {})
        {
            batchSizes.push(entries.length);
            await new Promise(resolve => setTimeout(resolve, 2000));
            const translated = entries.map(entry => entry.withTranslation(`${this.targetLang}:${entry.value}`));
            for (const entry of translated) options.onEntryTranslated?.(entry);
            return { translated, failures: [], totalGroups: 1 };
        });

        // A widget adds its items one by one, each settling on its own timer.
        for (let index = 0; index < 4; index += 1)
        {
            document.body.insertAdjacentHTML('beforeend', `<ul><li>Item ${"abcd"[index]}</li></ul>`);
            await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS + 1);
        }
        await jest.advanceTimersByTimeAsync(5000);

        expect(batchSizes).toEqual([1, 3]);
        expect(batchSizes.reduce((sum, size) => sum + size, 0)).toBe(4);
        expect([...document.querySelectorAll('li')].map(li => li.textContent)).toEqual(["de:Item a", "de:Item b", "de:Item c", "de:Item d"]);
        session.dispose();
    });

    it('applies only the latest rapid language switch', async () =>
    {
        document.body.innerHTML = '<main><p>Welcome</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        const french = session.switchTo('fr');
        const german = session.switchTo('de');

        await expect(french).rejects.toMatchObject({ name: 'AbortError' });
        await german;

        expect(document.querySelector('main p')?.textContent).toBe('de:Welcome');
        session.dispose();
    });

    it('restores the source language after a total translation failure', async () =>
    {
        document.body.innerHTML = '<main><p>Welcome</p></main>';
        jest.spyOn(Translator.prototype, 'translateBatch').mockRejectedValueOnce(new Error('Provider unavailable'));
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await expect(session.switchTo('fr')).rejects.toThrow('Provider unavailable');

        expect(document.querySelector('main p')?.textContent).toBe('Welcome');
        session.dispose();
    });

    it('keeps a pending-only translation active instead of reverting to source', async () =>
    {
        document.body.innerHTML = '<main><p>Welcome</p></main>';
        const restoreOriginals = jest.spyOn(TranslationEngine, 'restoreOriginals');
        jest.spyOn(Translator.prototype, 'translateBatch').mockResolvedValueOnce({
            translated: [],
            failures: [{ groupId: 'group-1', error: 'translation_pending' }],
            totalGroups: 1,
        });
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('fr');

        expect(document.querySelector('main p')?.textContent).toBe('Welcome');
        expect(restoreOriginals).toHaveBeenCalledTimes(1);
        session.dispose();
    });

    it('re-hydrates content the theme replaces without waiting for the active poll', async () =>
    {
        const batches: Array<{ values: string[]; resolve: () => void }> = [];
        jest.spyOn(Translator.prototype, 'translateBatch').mockImplementation(function (entries, options = {})
        {
            return new Promise(resolve =>
            {
                batches.push({
                    values: entries.map(entry => entry.value),
                    resolve: () =>
                    {
                        if (options.signal?.aborted)
                        {
                            resolve({ translated: [], failures: [], totalGroups: 0 });
                            return;
                        }
                        const translated = entries.map(entry => entry.withTranslation(`${this.targetLang}:${entry.value}`));
                        for (const entry of translated)
                        {
                            options.onEntryTranslated?.(entry);
                        }
                        resolve({ translated, failures: [], totalGroups: translated.length > 0 ? 1 : 0 });
                    },
                });
            });
        });

        document.body.innerHTML = '<main><p>Body</p></main><section><h3 class="question">Question</h3></section>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        void session.switchTo('de');
        await flushMutations();
        expect(batches).toHaveLength(1);

        const heading = document.querySelector('h3.question') as HTMLElement;
        const button = document.createElement('button');
        button.className = 'question';
        button.textContent = 'Question';
        heading.replaceWith(button);
        await flushMutations();

        const rehydration = batches.find(batch => !batch.values.includes('Body') && batch.values.includes('Question'));
        expect(rehydration).toBeDefined();

        rehydration!.resolve();
        await flushMutations();

        expect(document.querySelector('button.question')?.textContent).toBe('de:Question');
        expect(document.querySelector('main p')?.textContent).toBe('Body');

        for (const batch of batches) batch.resolve();
        await flushMutations();
        session.dispose();
    });

    it('notifies onRehydrated when a re-hydration flush applies replaced content', async () =>
    {
        document.body.innerHTML = '<main><p>Welcome</p></main>';
        const onRehydrated = jest.fn();
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
            onRehydrated,
        });

        try
        {
            await session.switchTo('de');
            document.body.insertAdjacentHTML('beforeend', '<footer><p>Contact us</p></footer>');
            await flushMutations();

            expect(document.querySelector('footer p')?.textContent).toBe('de:Contact us');
            expect(onRehydrated).toHaveBeenCalled();
        }
        finally
        {
            session.dispose();
        }
    });

    it('never re-queues its own write-backs as new content', async () =>
    {
        document.body.innerHTML = '<main><p>Reserve a seat</p><p>Opening hours</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        await flushMutations();
        await flushMutations();

        expect(document.querySelector('main p')?.textContent).toBe('it:Reserve a seat');
        // The switch translates once. A write-back the observer sees must be
        // recognised as our own, never re-queued into a second translate cycle.
        const translateBatch = Translator.prototype.translateBatch as jest.Mock;
        const inputs = translateBatch.mock.calls.map((call) => (call[0] as Array<{ value: string }>).map((entry) => entry.value));
        expect(translateBatch).toHaveBeenCalledTimes(1);
        expect(inputs).toEqual([['Reserve a seat', 'Opening hours']]);
        session.dispose();
    });

    it('stops translating a node that keeps changing to new content', async () =>
    {
        document.body.innerHTML = '<main><p>Start</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const paragraph = document.querySelector('p') as HTMLParagraphElement;
        const lastTranslated = VolatilityTracker.DEFAULT_THRESHOLD - 3;

        for (let index = 0; index <= lastTranslated; index += 1)
        {
            paragraph.textContent = word(index);
            await flushMutations();
        }
        expect(paragraph.textContent).toBe(`it:${word(lastTranslated)}`);

        paragraph.textContent = word(lastTranslated + 1);
        await flushMutations();
        expect(paragraph.textContent).toBe(word(lastTranslated + 1));

        paragraph.textContent = word(lastTranslated + 2);
        await flushMutations();
        expect(paragraph.textContent).toBe(word(lastTranslated + 2));
        session.dispose();
    });

    it('translates a change of existing text once it has held its value', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const paragraph = document.querySelector('p') as HTMLParagraphElement;

        paragraph.textContent = 'Updated content';
        await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS / 2);
        expect(paragraph.textContent).toBe('Updated content');

        await jest.advanceTimersByTimeAsync(CHANGE_SETTLE_MS / 2 + 1);
        expect(paragraph.textContent).toBe('it:Updated content');
        session.dispose();
    });

    it('translates only the settled value of a text that is typed out', async () =>
    {
        document.body.innerHTML = '<main><p>Static</p><h1 id="typed"></h1></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const typed = document.querySelector('#typed') as HTMLHeadingElement;
        const phrase = 'Build faster';

        for (let length = 1; length <= phrase.length; length += 1)
        {
            typed.textContent = phrase.slice(0, length);
            await jest.advanceTimersByTimeAsync(80);
        }
        await flushMutations();

        expect(sentValues().filter(value => phrase.startsWith(value))).toEqual([phrase]);
        expect(typed.textContent).toBe('it:Build faster');
        session.dispose();
    });

    it('translates only the final value of a counter that counts up after it appears', async () =>
    {
        document.body.innerHTML = '<main><p>Static</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        document.body.insertAdjacentHTML('beforeend', '<section><p id="count">0 happy clients</p></section>');
        const count = document.querySelector('#count') as HTMLParagraphElement;

        for (let value = 1; value <= 60; value += 1)
        {
            count.textContent = `${value} happy clients`;
            await jest.advanceTimersByTimeAsync(16);
        }
        await flushMutations();

        expect(sentValues().filter(value => value.endsWith('happy clients'))).toEqual(['60 happy clients']);
        expect(count.textContent).toBe('it:60 happy clients');
        session.dispose();
    });

    it('serves every step of a counter whose first value was translated without a request', async () =>
    {
        document.body.innerHTML = '<main><p id="count">0 happy clients</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const count = document.querySelector('#count') as HTMLParagraphElement;

        for (let value = 1; value <= 60; value += 1)
        {
            count.textContent = `${value} happy clients`;
            await jest.advanceTimersByTimeAsync(16);
            expect(count.textContent).toBe(`it:${value} happy clients`);
        }

        expect(sentValues().filter(value => value.endsWith('happy clients'))).toEqual(['0 happy clients']);
        session.dispose();
    });

    it('keeps translating a rotator that cycles through many phrases', async () =>
    {
        document.body.innerHTML = '<main><p id="rotator">Fast</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const rotator = document.querySelector('#rotator') as HTMLParagraphElement;
        const phrases = ['Simple', 'Global', 'Reliable', 'Friendly', 'Precise', 'Open', 'Honest', 'Fast'];

        for (let cycle = 0; cycle < 3; cycle += 1)
        {
            for (const phrase of phrases)
            {
                rotator.textContent = phrase;
                await jest.advanceTimersByTimeAsync(3000);
                expect(rotator.textContent).toBe(`it:${phrase}`);
            }
        }
        session.dispose();
    });

    it('keeps a text whose only change is its numbers translated from its first translation alone', async () =>
    {
        document.body.innerHTML = '<main><p>Start</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const translateBatch = Translator.prototype.translateBatch as jest.Mock;

        await session.switchTo('it');
        const paragraph = document.querySelector('p') as HTMLParagraphElement;

        for (let index = 0; index < 20; index += 1)
        {
            paragraph.textContent = `Item ${index}`;
            await flushMutations();
        }

        const itemValuesSent = translateBatch.mock.calls
            .flatMap(call => call[0] as Array<{ value: string }>)
            .filter(entry => entry.value.startsWith('Item '))
            .map(entry => entry.value);

        expect(paragraph.textContent).toBe('it:Item 19');
        expect(itemValuesSent).toEqual(['Item 0']);
        session.dispose();
    });

    it('keeps a clock that rewrites its text node live from its first translation alone', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p><span id="clock">20:46:03</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const translateBatch = Translator.prototype.translateBatch as jest.Mock;

        await session.switchTo('it');
        const clockText = document.querySelector('#clock')?.firstChild as Text;
        expect(clockText.data).toBe('it:20:46:03');

        for (let second = 4; second < 30; second += 1)
        {
            clockText.data = `20:46:${String(second).padStart(2, '0')}`;
            await flushMutations();
            expect(clockText.data).toBe(`it:20:46:${String(second).padStart(2, '0')}`);
        }

        const clockValuesSent = translateBatch.mock.calls
            .flatMap(call => call[0] as Array<{ value: string }>)
            .filter(entry => /^\d{2}:\d{2}:\d{2}$/.test(entry.value))
            .map(entry => entry.value);
        expect(clockValuesSent).toEqual(['20:46:03']);

        await session.switchTo('en');
        expect(clockText.data).toBe('20:46:29');
        session.dispose();
    });

    it('serves a clock that ticked between switches from its first translation alone', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p><span id="clock">00:00:00</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const clock = document.querySelector('#clock') as HTMLSpanElement;
        const translateBatch = Translator.prototype.translateBatch as jest.Mock;
        const switches = VolatilityTracker.DEFAULT_THRESHOLD + 2;

        for (let second = 0; second < switches; second += 1)
        {
            clock.textContent = `00:00:${String(second).padStart(2, '0')}`;
            await session.switchTo('it');
            expect(clock.textContent).toBe(`it:00:00:${String(second).padStart(2, '0')}`);
            await session.switchTo('en');
        }

        const clockValuesSent = translateBatch.mock.calls
            .flatMap(call => call[0] as Array<{ value: string }>)
            .filter(entry => /^\d{2}:\d{2}:\d{2}$/.test(entry.value))
            .map(entry => entry.value);

        expect(clockValuesSent).toEqual(['00:00:00']);
        expect(clock.textContent).toBe(`00:00:${String(switches - 1).padStart(2, '0')}`);
        session.dispose();
    });

    it('asks the translator again when the translation changed the numbers', async () =>
    {
        (Translator.prototype.translateBatch as jest.Mock).mockImplementation(async function (entries, options = {})
        {
            const translated = entries.map(entry => entry.withTranslation(
                entry.value === '3:05 PM' ? '15:05' : `${this.targetLang}:${entry.value}`
            ));
            for (const entry of translated)
            {
                options.onEntryTranslated?.(entry);
            }
            return { translated, failures: [], totalGroups: translated.length > 0 ? 1 : 0 };
        });
        document.body.innerHTML = '<main><p>Original content</p><span id="time">3:05 PM</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const translateBatch = Translator.prototype.translateBatch as jest.Mock;

        await session.switchTo('de');
        const time = document.querySelector('#time') as HTMLSpanElement;
        expect(time.textContent).toBe('15:05');

        time.textContent = '3:06 PM';
        await flushMutations();

        const timeValuesSent = translateBatch.mock.calls
            .flatMap(call => call[0] as Array<{ value: string }>)
            .filter(entry => entry.value.endsWith(' PM'))
            .map(entry => entry.value);
        expect(timeValuesSent).toEqual(['3:05 PM', '3:06 PM']);
        expect(time.textContent).toBe('de:3:06 PM');
        session.dispose();
    });

    it('stops sending a clock whose translation keeps changing the numbers', async () =>
    {
        (Translator.prototype.translateBatch as jest.Mock).mockImplementation(async function (entries, options = {})
        {
            const translated = entries.map(entry => entry.withTranslation(
                entry.value.endsWith(' PM') ? `${Number(entry.value.slice(0, 1)) + 12}:${entry.value.slice(2, 4)}` : `${this.targetLang}:${entry.value}`
            ));
            for (const entry of translated)
            {
                options.onEntryTranslated?.(entry);
            }
            return { translated, failures: [], totalGroups: translated.length > 0 ? 1 : 0 };
        });
        document.body.innerHTML = '<main><p>Original content</p><span id="time">3:05 PM</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const translateBatch = Translator.prototype.translateBatch as jest.Mock;

        await session.switchTo('de');
        const time = document.querySelector('#time') as HTMLSpanElement;

        for (let minute = 6; minute < 20; minute += 1)
        {
            time.textContent = `3:${String(minute).padStart(2, '0')} PM`;
            await flushMutations();
        }

        const timeValuesSent = translateBatch.mock.calls
            .flatMap(call => call[0] as Array<{ value: string }>)
            .filter(entry => entry.value.endsWith(' PM'));
        expect(timeValuesSent.length).toBe(VolatilityTracker.DEFAULT_THRESHOLD - 1);
        expect(time.textContent).toBe('3:19 PM');
        session.dispose();
    });

    it('does no work for content that changes while the page is in its source language', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const paragraph = document.querySelector('p') as HTMLParagraphElement;

        paragraph.textContent = 'Updated content';
        document.body.insertAdjacentHTML('beforeend', '<footer><p>Contact us</p></footer>');
        await Promise.resolve();
        await Promise.resolve();

        expect(jest.getTimerCount()).toBe(0);
        await flushMutations();
        expect(sentValues()).toEqual([]);
        session.dispose();
    });

    it('translates a clock that ticked while the page was in its source language', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p><span id="clock">20:46:03</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const clock = document.querySelector('#clock') as HTMLSpanElement;

        const ticks = VolatilityTracker.DEFAULT_THRESHOLD * 2;
        for (let second = 4; second < 4 + ticks; second += 1)
        {
            clock.textContent = `20:46:${String(second).padStart(2, '0')}`;
            await flushMutations();
        }

        await session.switchTo('it');

        expect(clock.textContent).toBe(`it:20:46:${3 + ticks}`);
        session.dispose();
    });

    it('holds the ticks of a text whose first translation is in flight and serves them with the current digits once it lands', async () =>
    {
        const batches: Array<{ values: string[]; resolve: () => void }> = [];
        (Translator.prototype.translateBatch as jest.Mock).mockImplementation(function (entries, options = {})
        {
            return new Promise(resolve =>
            {
                batches.push({
                    values: entries.map(entry => entry.value),
                    resolve: () =>
                    {
                        const translated = entries.map(entry => entry.withTranslation(`${this.targetLang}:${entry.value}`));
                        for (const entry of translated)
                        {
                            options.onEntryTranslated?.(entry);
                        }
                        resolve({ translated, failures: [], totalGroups: translated.length > 0 ? 1 : 0 });
                    },
                });
            });
        });
        document.body.innerHTML = '<main><p>Original content</p><span id="clock">20:46:03</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const clockText = document.querySelector('#clock')?.firstChild as Text;

        const sentBatches = (): string[][] => batches.map(batch => batch.values).filter(values => values.length > 0);

        const switching = session.switchTo('it');
        await Promise.resolve();
        await Promise.resolve();
        expect(sentBatches()).toEqual([['Original content', '20:46:03']]);

        for (let second = 4; second <= 8; second += 1)
        {
            clockText.data = `20:46:0${second}`;
            await jest.advanceTimersByTimeAsync(1000);
        }
        expect(sentBatches()).toEqual([['Original content', '20:46:03']]);
        expect(clockText.data).toBe('20:46:08');

        batches[0].resolve();
        await switching;
        expect(clockText.data).toBe('it:20:46:08');

        clockText.data = '20:46:09';
        await flushMutations();
        expect(clockText.data).toBe('it:20:46:09');
        expect(sentBatches()).toEqual([['Original content', '20:46:03']]);
        session.dispose();
    });

    it('lets a text go its own way once its in-flight translation landed without keeping the digits', async () =>
    {
        const batches: Array<{ values: string[]; resolve: () => void }> = [];
        (Translator.prototype.translateBatch as jest.Mock).mockImplementation(function (entries, options = {})
        {
            return new Promise(resolve =>
            {
                batches.push({
                    values: entries.map(entry => entry.value),
                    resolve: () =>
                    {
                        const translated = entries.map(entry => entry.withTranslation(
                            entry.value.endsWith(' PM') ? `${Number(entry.value.slice(0, 1)) + 12}:${entry.value.slice(2, 4)}` : `${this.targetLang}:${entry.value}`
                        ));
                        for (const entry of translated)
                        {
                            options.onEntryTranslated?.(entry);
                        }
                        resolve({ translated, failures: [], totalGroups: translated.length > 0 ? 1 : 0 });
                    },
                });
            });
        });
        document.body.innerHTML = '<main><p>Original content</p><span id="time">3:05 PM</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const time = document.querySelector('#time') as HTMLSpanElement;
        const sentBatches = (): string[][] => batches.map(batch => batch.values).filter(values => values.length > 0);

        const switching = session.switchTo('de');
        await Promise.resolve();
        await Promise.resolve();
        batches[0].resolve();
        await switching;
        expect(time.textContent).toBe('15:05');

        time.textContent = '3:06 PM';
        await flushMutations();

        expect(sentBatches()).toEqual([['Original content', '3:05 PM'], ['3:06 PM']]);
        session.dispose();
    });

    it('stops re-translating text a widget keeps writing back in its source language', async () =>
    {
        document.body.innerHTML = '<main><p id="live">Ready</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const live = document.querySelector('#live') as HTMLParagraphElement;
        expect(live.textContent).toBe('it:Ready');

        for (let round = 0; round < 8; round += 1)
        {
            live.textContent = 'Ready';
            await flushMutations();
        }

        expect(sentValues().filter(value => value === 'Ready')).toHaveLength(VolatilityTracker.STRIKES);
        expect(live.textContent).toBe('Ready');
        session.dispose();
    });

    it('does not keep a clock a failed batch left untranslated as its own translation', async () =>
    {
        let batches = 0;
        (Translator.prototype.translateBatch as jest.Mock).mockImplementation(async function (entries, options = {})
        {
            batches += 1;
            const failed = batches === 1;
            const translated = entries.map(entry => entry.withTranslation(failed ? entry.value : `${this.targetLang}:${entry.value}`));
            for (const entry of translated)
            {
                options.onEntryTranslated?.(entry);
            }
            return {
                translated,
                failures: failed ? [{ groupId: 'grp_00000001', error: 'translation_pending' }] : [],
                totalGroups: 1,
            };
        });
        document.body.innerHTML = '<main><p>Original content</p><span id="clock">20:46:03</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const clock = document.querySelector('#clock') as HTMLSpanElement;

        await session.switchTo('it');
        expect(clock.textContent).toBe('20:46:03');

        clock.textContent = '20:46:04';
        await flushMutations();

        expect(clock.textContent).toBe('it:20:46:04');
        session.dispose();
    });

    it('treats a rewritten text node as new source content', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('de');
        const text = document.querySelector('p')?.firstChild as Text;
        expect(text.data).toBe('de:Original content');

        text.data = 'Updated content';
        await flushMutations();
        expect(text.data).toBe('de:Updated content');

        await session.switchTo('en');
        expect(text.data).toBe('Updated content');
        session.dispose();
    });

    it('keeps translating a rotator that repeats a fixed set of phrases', async () =>
    {
        document.body.innerHTML = '<main><p>Fast</p></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });

        await session.switchTo('it');
        const paragraph = document.querySelector('p') as HTMLParagraphElement;

        for (const phrase of ['Simple', 'Global', 'Fast', 'Simple', 'Global', 'Fast', 'Simple'])
        {
            paragraph.textContent = phrase;
            await flushMutations();
        }

        expect(paragraph.textContent).toBe('it:Simple');
        session.dispose();
    });

    it('stops handing a value that keeps changing on every switch to the translator, not only on every mutation', async () =>
    {
        document.body.innerHTML = '<main><p>Original content</p><span id="ticker">Alpha</span></main>';
        const session = createSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: EXTRACTION_OPTIONS,
        });
        const ticker = document.querySelector('#ticker') as HTMLSpanElement;
        const words = Array.from({ length: VolatilityTracker.DEFAULT_THRESHOLD + 2 }, (_, index) => word(index));

        for (const value of words)
        {
            ticker.textContent = value;
            await session.switchTo('it');
            await session.switchTo('en');
        }

        expect(sentValues().filter(value => words.includes(value)).length).toBe(VolatilityTracker.DEFAULT_THRESHOLD - 1);
        session.dispose();
    });
});
