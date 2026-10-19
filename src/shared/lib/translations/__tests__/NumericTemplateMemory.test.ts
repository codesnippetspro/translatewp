import { NumericTemplateMemory } from '@/shared/lib/translations/NumericTemplateMemory';

describe('NumericTemplateMemory', () =>
{
    it('re-translates a later value of the same text with its own digits', () =>
    {
        const memory = new NumericTemplateMemory();

        expect(memory.remember('it', '3 days 12:04:11 left', '3 giorni 12:04:11 rimanenti')).toBe(true);

        expect(memory.resolve('it', '3 days 12:04:10 left')).toBe('3 giorni 12:04:10 rimanenti');
        expect(memory.resolve('it', '2 days 23:59:59 left')).toBe('2 giorni 23:59:59 rimanenti');
    });

    it('keeps an identity translation so a clock stays live without a request', () =>
    {
        const memory = new NumericTemplateMemory();

        memory.remember('de', '20:46:03', '20:46:03');

        expect(memory.resolve('de', '20:46:04')).toBe('20:46:04');
    });

    it('keeps each target language apart', () =>
    {
        const memory = new NumericTemplateMemory();

        memory.remember('it', '5 days left', '5 giorni rimasti');

        expect(memory.resolve('de', '5 days left')).toBeNull();
        expect(memory.resolve('it', '6 days left')).toBe('6 giorni rimasti');
    });

    it('does not remember a translation that changed a number', () =>
    {
        const memory = new NumericTemplateMemory();

        expect(memory.remember('de', '10 miles away', '16 km entfernt')).toBe(false);
        expect(memory.remember('en', '3:05 PM', '15:05')).toBe(false);
        expect(memory.remember('de', '05/12/2026', '12.05.2026')).toBe(false);
        expect(memory.remember('it', 'Top 10', 'I dieci migliori')).toBe(false);

        expect(memory.resolve('de', '12 miles away')).toBeNull();
        expect(memory.resolve('en', '4:05 PM')).toBeNull();
        expect(memory.resolve('de', '06/15/2026')).toBeNull();
    });

    it('serves a text whose digit runs repeat by position', () =>
    {
        const memory = new NumericTemplateMemory();

        expect(memory.remember('it', '00:00:05', 'it:00:00:05')).toBe(true);
        expect(memory.resolve('it', '00:00:06')).toBe('it:00:00:06');
        expect(memory.resolve('it', '23:59:59')).toBe('it:23:59:59');
    });

    it('holds a text whose translation is in flight until it is cleared', () =>
    {
        const memory = new NumericTemplateMemory();

        memory.markPending('it', '20:46:03');

        expect(memory.isPending('it', '20:46:07')).toBe(true);
        expect(memory.isPending('de', '20:46:07')).toBe(false);
        expect(memory.isPending('it', 'Hello')).toBe(false);

        memory.clearPending('it', '20:46:03');
        expect(memory.isPending('it', '20:46:07')).toBe(false);
    });

    it('never holds text without digits', () =>
    {
        const memory = new NumericTemplateMemory();

        memory.markPending('it', 'Hello');

        expect(memory.isPending('it', 'Hello')).toBe(false);
    });

    it('ignores text without digits', () =>
    {
        const memory = new NumericTemplateMemory();

        expect(memory.remember('it', 'Welcome', 'Benvenuto')).toBe(false);
        expect(memory.resolve('it', 'Welcome')).toBeNull();
    });

    it('keeps the latest translation of a text', () =>
    {
        const memory = new NumericTemplateMemory();

        memory.remember('it', '5 days left', '5 giorni rimasti');
        memory.remember('it', '4 days left', '4 giorni restanti');

        expect(memory.resolve('it', '2 days left')).toBe('2 giorni restanti');
    });

    it('forgets the oldest text once a language holds too many', () =>
    {
        const memory = new NumericTemplateMemory();

        const word = (index: number): string =>
            String.fromCharCode(97 + (index % 26)) + String.fromCharCode(97 + Math.floor(index / 26));

        memory.remember('it', 'first 1', 'primo 1');
        for (let index = 0; index < NumericTemplateMemory.MAX_TEMPLATES_PER_LANGUAGE; index += 1)
        {
            memory.remember('it', `${word(index)} ${index} of ${index + 1000}`, `${word(index)} ${index} di ${index + 1000}`);
        }

        expect(memory.resolve('it', 'first 2')).toBeNull();
        expect(memory.resolve('it', `${word(0)} 7 of 8`)).toBe(`${word(0)} 7 di 8`);
    });
});
