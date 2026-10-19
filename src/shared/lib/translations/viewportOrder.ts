/**
 * On a visit, text groups are served in the order the visitor
 * benefits from: groups inside the viewport first, top to bottom, then the
 * rest by distance from the viewport, nearest first. Lookups, miss reports
 * and job dispatch follow this order, so the visible skeletons clear first
 * and the service translates the visible text before the rest of the page.
 */

export type Viewport = { top: number; bottom: number };

type Positioned<T> = { item: T; top: number; distance: number; index: number };

function elementOf(node: Node | undefined): Element | null
{
    if (!node) return null;
    if (node.nodeType === 1) return node as Element;
    return (node as ChildNode).parentElement ?? null;
}

/** Vertical position of a group: the top of its first measurable entry, in document coordinates. */
function groupTop(entries: ReadonlyArray<{ node: Node }>, scrollTop: number): number | null
{
    for (const entry of entries)
    {
        const element = elementOf(entry.node);
        if (!element || typeof element.getBoundingClientRect !== 'function') continue;
        const rect = element.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0 && rect.top === 0) continue;
        return rect.top + scrollTop;
    }
    return null;
}

export function currentViewport(): Viewport
{
    const scrollTop = typeof window === 'undefined' ? 0 : (window.scrollY || 0);
    const height = typeof window === 'undefined' ? 0 : (window.innerHeight || 0);
    return { top: scrollTop, bottom: scrollTop + height };
}

/**
 * Orders items that carry entries with DOM nodes. Returns the ordered list and
 * how many of its leading items are inside the viewport. Items that cannot be
 * measured keep their original relative order after the measurable ones.
 */
export function orderByViewport<T extends { group: { entries: ReadonlyArray<{ node: Node }> } }>(
    items: ReadonlyArray<T>,
    viewport: Viewport = currentViewport(),
): { ordered: T[]; visibleCount: number }
{
    if (items.length < 2) return { ordered: [...items], visibleCount: items.length };

    const measured: Positioned<T>[] = [];
    const unmeasured: T[] = [];
    items.forEach((item, index) =>
    {
        const top = groupTop(item.group.entries, viewport.top);
        if (top === null)
        {
            unmeasured.push(item);
            return;
        }
        const distance = top < viewport.top ? viewport.top - top : (top > viewport.bottom ? top - viewport.bottom : 0);
        measured.push({ item, top, distance, index });
    });

    measured.sort((a, b) => (a.distance - b.distance) || (a.top - b.top) || (a.index - b.index));
    const visibleCount = measured.filter(entry => entry.distance === 0).length;

    return { ordered: [...measured.map(entry => entry.item), ...unmeasured], visibleCount };
}
