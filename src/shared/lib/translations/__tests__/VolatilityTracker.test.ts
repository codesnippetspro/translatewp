import { VolatilityTracker } from '@/shared/lib/translations/VolatilityTracker';

function nodeFor(): Node
{
    return document.createElement('span');
}

describe('VolatilityTracker', () =>
{
    it('marks a node volatile once it produces enough never-seen values in the window', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 6, windowMs: 10_000 });
        const clock = nodeFor();

        for (let second = 0; second < 5; second += 1)
        {
            now = second * 1000;
            expect(tracker.record(clock, `20:46:0${second}`)).toBe(false);
        }

        now = 5000;
        expect(tracker.record(clock, '20:46:05')).toBe(true);
        expect(tracker.isVolatile(clock)).toBe(true);

        now = 6000;
        expect(tracker.record(clock, '20:46:06')).toBe(true);
    });

    it('never trips on a rotator that cycles through a fixed set of phrases', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 6, windowMs: 10_000 });
        const tagline = nodeFor();
        const phrases = ['Fast.', 'Simple.', 'Global.', 'Fast.', 'Simple.', 'Global.', 'Fast.', 'Simple.'];

        phrases.forEach((phrase, index) =>
        {
            now = index * 1500;
            expect(tracker.record(tagline, phrase)).toBe(false);
        });

        expect(tracker.isVolatile(tagline)).toBe(false);
    });

    it('does not trip when novelty is spread beyond the window', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 6, windowMs: 10_000 });
        const node = nodeFor();

        for (let index = 0; index < 8; index += 1)
        {
            now = index * 3000;
            expect(tracker.record(node, `value ${index}`)).toBe(false);
        }
    });

    it('trusts a node again after it has stayed quiet', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 3, windowMs: 10_000, quietMs: 60_000 });
        const node = nodeFor();

        expect(tracker.record(node, 'a')).toBe(false);
        expect(tracker.record(node, 'b')).toBe(false);
        expect(tracker.record(node, 'c')).toBe(true);

        now = 61_000;
        expect(tracker.isVolatile(node)).toBe(false);
        expect(tracker.record(node, 'settled text')).toBe(false);
    });

    it('trusts a node again that only repeats values it has shown before', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 3, windowMs: 10_000, quietMs: 60_000 });
        const node = nodeFor();
        const phrases = ['a', 'b', 'c'];

        phrases.forEach(phrase => tracker.record(node, phrase));
        expect(tracker.isVolatile(node)).toBe(true);

        for (let index = 0; index < 30; index += 1)
        {
            now = 2_000 * (index + 1);
            const phrase = phrases[index % phrases.length];
            expect(tracker.record(node, phrase)).toBe(now - 0 < 60_000);
        }

        expect(tracker.isVolatile(node)).toBe(false);
    });

    it('reports a node settled once its last never-seen value is old, whatever it repeated since', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 3, windowMs: 10_000, quietMs: 60_000 });
        const node = nodeFor();

        for (const phrase of ['a', 'b', 'c']) tracker.record(node, phrase);

        now = 59_000;
        expect(tracker.record(node, 'a')).toBe(true);
        expect(tracker.isVolatile(node)).toBe(true);

        now = 60_500;
        expect(tracker.isVolatile(node)).toBe(false);
    });

    it('stays volatile while never-seen values keep arriving', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 3, windowMs: 10_000, quietMs: 60_000 });
        const node = nodeFor();

        for (let index = 0; index < 40; index += 1)
        {
            now = index < 3 ? index * 4_000 : 8_000 + (index - 2) * 30_000;
            const volatile = tracker.record(node, `value ${index}`);
            expect(volatile).toBe(index >= 2);
        }

        expect(tracker.isVolatile(node)).toBe(true);
    });

    it('marks a node volatile once the page has written it back enough times inside the window', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, windowMs: 10_000, quietMs: 60_000 });
        const node = nodeFor();

        expect(tracker.strike(node)).toBe(false);
        now = 1000;
        expect(tracker.strike(node)).toBe(false);
        now = 2000;
        expect(tracker.strike(node)).toBe(true);
        expect(tracker.isVolatile(node)).toBe(true);

        now = 3000;
        expect(tracker.strike(node)).toBe(true);
        now = 62_500;
        expect(tracker.isVolatile(node)).toBe(false);
    });

    it('forgets write-backs spread beyond the window', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, windowMs: 10_000, quietMs: 60_000 });
        const node = nodeFor();

        expect(tracker.strike(node)).toBe(false);
        now = 6000;
        expect(tracker.strike(node)).toBe(false);
        now = 12_000;
        expect(tracker.strike(node)).toBe(false);
        now = 13_000;
        expect(tracker.strike(node)).toBe(true);
    });

    it('keeps one record for a text node whose parent replaces it each change', () =>
    {
        let now = 0;
        const tracker = new VolatilityTracker({ now: () => now, threshold: 3, windowMs: 10_000 });
        const parent = document.createElement('div');

        for (let index = 0; index < 2; index += 1)
        {
            now = index * 1000;
            const text = document.createTextNode(`tick ${index}`);
            parent.appendChild(text);
            expect(tracker.record(text, `tick ${index}`)).toBe(false);
            parent.removeChild(text);
        }

        now = 2000;
        const last = document.createTextNode('tick 2');
        parent.appendChild(last);
        expect(tracker.record(last, 'tick 2')).toBe(true);
    });
});
