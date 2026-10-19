import { orderByViewport } from '@/shared/lib/translations/viewportOrder';

type Item = { group: { entries: { node: Node }[] }; name: string };

const itemAt = (name: string, top: number): Item =>
{
    const element = document.createElement('p');
    element.getBoundingClientRect = () => ({ top, bottom: top + 20, left: 0, right: 100, width: 100, height: 20, x: 0, y: top, toJSON: () => ({}) }) as DOMRect;
    const text = document.createTextNode(name);
    element.appendChild(text);
    document.body.appendChild(element);
    return { group: { entries: [{ node: text }] }, name };
};

describe('orderByViewport', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it('puts visible groups first top to bottom, then the rest by distance from the viewport', () =>
    {
        // Viewport 0 to 800 (scroll at 0): rects are viewport-relative.
        const far = itemAt('far below', 3000);
        const above = itemAt('just above', -50);
        const bottomVisible = itemAt('visible low', 700);
        const topVisible = itemAt('visible high', 100);
        const nearBelow = itemAt('near below', 900);

        const { ordered, visibleCount } = orderByViewport([far, above, bottomVisible, topVisible, nearBelow], { top: 0, bottom: 800 });

        expect(ordered.map(item => item.name)).toEqual(['visible high', 'visible low', 'just above', 'near below', 'far below']);
        expect(visibleCount).toBe(2);
    });

    it('keeps unmeasurable groups after the measurable ones in their original order', () =>
    {
        const visible = itemAt('visible', 10);
        const detached: Item = { group: { entries: [{ node: document.createTextNode('loose') }] }, name: 'loose' };
        const detached2: Item = { group: { entries: [{ node: document.createTextNode('loose 2') }] }, name: 'loose 2' };

        const { ordered, visibleCount } = orderByViewport([detached, visible, detached2], { top: 0, bottom: 800 });

        expect(ordered.map(item => item.name)).toEqual(['visible', 'loose', 'loose 2']);
        expect(visibleCount).toBe(1);
    });

    it('leaves a single group alone', () =>
    {
        const only = itemAt('only', 5000);
        expect(orderByViewport([only], { top: 0, bottom: 800 }).ordered).toEqual([only]);
    });
});
