import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { isTextNodeInViewport, observePendingTextEntries } from '@/shared/lib/translations/viewportText';

const rect = (extra: Partial<DOMRect> = {}) => ({
    top: 10, left: 10, bottom: 30, right: 110, width: 100, height: 20, ...extra,
} as DOMRect);
const entry = (id: string, node: Node) => new TranslatableEntry({
    id, node, type: 'text', attributeName: null, value: node.textContent ?? '',
});

describe('current viewport pending text', () =>
{
    let rangeRects: jest.SpyInstance;
    let originalRects: PropertyDescriptor | undefined;
    let originalObserver: typeof IntersectionObserver;
    let intersection: IntersectionObserverCallback;
    let frames: Map<number, FrameRequestCallback>;
    let observe: jest.Mock;
    let disconnect: jest.Mock;
    const flush = () =>
    {
        const callbacks = [...frames.values()];
        frames.clear();
        callbacks.forEach(callback => callback(1));
    };

    beforeEach(() =>
    {
        frames = new Map();
        let nextFrame = 0;
        jest.spyOn(window, 'requestAnimationFrame').mockImplementation(callback =>
        {
            frames.set(++nextFrame, callback);
            return nextFrame;
        });
        jest.spyOn(window, 'cancelAnimationFrame').mockImplementation(id => { frames.delete(id); });
        originalRects = Object.getOwnPropertyDescriptor(Range.prototype, 'getClientRects');
        Object.defineProperty(Range.prototype, 'getClientRects', {
            configurable: true, value: () => [rect()], writable: true,
        });
        rangeRects = jest.spyOn(Range.prototype, 'getClientRects');
        originalObserver = globalThis.IntersectionObserver;
        observe = jest.fn();
        disconnect = jest.fn();
        globalThis.IntersectionObserver = jest.fn(callback =>
        {
            intersection = callback;
            return { observe, disconnect };
        }) as unknown as typeof IntersectionObserver;
        document.body.innerHTML = '<p>Visible</p><p>Offscreen</p>';
    });

    afterEach(() =>
    {
        jest.restoreAllMocks();
        if (originalRects) Object.defineProperty(Range.prototype, 'getClientRects', originalRects);
        else delete (Range.prototype as Partial<Range>).getClientRects;
        globalThis.IntersectionObserver = originalObserver;
        document.body.innerHTML = '';
    });

    it.each([
        { width: 0 }, { height: 0 }, { bottom: 0 }, { right: 0 },
        { top: 768 }, { left: 1024 },
    ])('ignores text outside the visible rectangle: %j', geometry =>
    {
        rangeRects.mockReturnValue([rect(geometry)]);
        expect(isTextNodeInViewport(document.querySelector('p')!.firstChild!)).toBe(false);
    });

    it('accepts a partially visible line and rejects disconnected and non-text nodes', () =>
    {
        rangeRects.mockReturnValue([rect({ top: -10, left: -10 })]);
        expect(isTextNodeInViewport(document.querySelector('p')!.firstChild!)).toBe(true);
        expect(isTextNodeInViewport(document.createTextNode('Detached'))).toBe(false);
        expect(isTextNodeInViewport(document.querySelector('p')!)).toBe(false);
    });

    it.each(['display:none', 'visibility:hidden', 'visibility:collapse', 'opacity:0'])(
        'does not count text hidden by an ancestor with %s', style =>
        {
            document.body.innerHTML = `<section style="${style}"><p>Hidden</p></section>`;
            expect(isTextNodeInViewport(document.querySelector('p')!.firstChild!)).toBe(false);
        },
    );

    it('fails closed when text geometry is unavailable', () =>
    {
        delete (Range.prototype as Partial<Range>).getClientRects;
        expect(isTextNodeInViewport(document.querySelector('p')!.firstChild!)).toBe(false);
    });

    it('batches settlements, intersection, scroll and resize into one measurement per frame', () =>
    {
        const nodes = Array.from(document.querySelectorAll('p')).map(element => element.firstChild!);
        const entries = nodes.map((node, index) => entry(String(index), node));
        const changed = jest.fn();
        const tracker = observePendingTextEntries(entries, changed);
        expect(changed.mock.calls).toEqual([[2]]);
        rangeRects.mockClear();
        tracker.settle(entries[0]);
        tracker.settle(entries[0]);
        intersection([], {} as IntersectionObserver);
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('resize'));
        expect(rangeRects).not.toHaveBeenCalled();
        expect(frames.size).toBe(1);
        flush();
        expect(rangeRects).toHaveBeenCalledTimes(1);
        expect(changed.mock.calls).toEqual([[2], [1]]);
        window.dispatchEvent(new Event('scroll'));
        flush();
        expect(changed).toHaveBeenCalledTimes(2);
        tracker.settle(entries[1]);
        flush();
        expect(changed.mock.calls).toEqual([[2], [1], [0]]);
        tracker.disconnect();
    });

    it('follows text scrolled into and out of view, without an IntersectionObserver', () =>
    {
        globalThis.IntersectionObserver = undefined as unknown as typeof IntersectionObserver;
        rangeRects.mockReturnValue([rect({ top: 900 })]);
        const changed = jest.fn();
        const tracker = observePendingTextEntries([entry('a', document.querySelector('p')!.firstChild!)], changed);
        expect(changed.mock.calls).toEqual([[0]]);
        rangeRects.mockReturnValue([rect()]);
        window.dispatchEvent(new Event('scroll'));
        flush();
        expect(changed.mock.calls).toEqual([[0], [1]]);
        rangeRects.mockReturnValue([rect({ top: 900 })]);
        window.dispatchEvent(new Event('resize'));
        flush();
        expect(changed.mock.calls).toEqual([[0], [1], [0]]);
        tracker.disconnect();
    });

    it('observes each parent once and cancels pending reports and listeners on disconnect', () =>
    {
        const paragraph = document.querySelector('p')!;
        paragraph.appendChild(document.createTextNode('Second'));
        const entries = Array.from(paragraph.childNodes).map((node, index) => entry(String(index), node));
        const changed = jest.fn();
        const tracker = observePendingTextEntries(entries, changed);
        expect(observe.mock.calls).toEqual([[paragraph]]);
        window.dispatchEvent(new Event('scroll'));
        const callback = [...frames.values()][0];
        tracker.disconnect();
        expect(disconnect).toHaveBeenCalledTimes(1);
        expect(frames.size).toBe(0);
        callback(1);
        intersection([], {} as IntersectionObserver);
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('resize'));
        tracker.settle(entries[0]);
        expect(frames.size).toBe(0);
        expect(changed.mock.calls).toEqual([[2]]);
    });
    it('counts a wrapped text line when any one of its rectangles is visible', () =>
    {
        rangeRects.mockReturnValue([rect({ top: 900 }), rect()]);
        expect(isTextNodeInViewport(document.querySelector('p')!.firstChild!)).toBe(true);
    });

    it('does not observe a null parent and handles a non-bubbling nested scroll', () =>
    {
        const detached = entry('detached', document.createTextNode('Detached'));
        const node = document.querySelector('p')!.firstChild!;
        rangeRects.mockReturnValue([rect({ top: 900 })]);
        const changed = jest.fn();
        const tracker = observePendingTextEntries([detached, entry('visible', node)], changed);
        expect(observe.mock.calls).toEqual([[node.parentElement]]);
        rangeRects.mockReturnValue([rect()]);
        node.parentElement!.dispatchEvent(new Event('scroll', { bubbles: false }));
        flush();
        expect(changed.mock.calls).toEqual([[0], [1]]);
        tracker.disconnect();
    });

    it('releases the registered listeners with matching capture and avoids cancelling an absent frame', () =>
    {
        const add = jest.spyOn(window, 'addEventListener');
        const remove = jest.spyOn(window, 'removeEventListener');
        const tracker = observePendingTextEntries([entry('one', document.querySelector('p')!.firstChild!)], jest.fn());
        tracker.disconnect();
        for (const call of add.mock.calls.filter(call => ['scroll', 'resize'].includes(call[0]))) {
            expect(remove).toHaveBeenCalledWith(...call);
        }
        expect(window.cancelAnimationFrame).not.toHaveBeenCalled();
    });

});
