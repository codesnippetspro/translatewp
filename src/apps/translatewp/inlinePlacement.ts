// A Pro inline placement is stored as a reproducible
// description of where the switcher goes in the document, never as viewport
// coordinates: an anchor is the CSS path of an element and the side of it the
// switcher sits on. A zone, stored by an earlier version, still resolves to its
// landmark. When the page has no element at the path, the placement resolves
// to nothing and the site's anchor fallback decides what shows.
export type SwitcherZone = 'header' | 'before_content' | 'after_content' | 'sidebar' | 'footer';
export type AnchorPosition = 'before' | 'after' | 'left' | 'right' | 'prepend' | 'append';

export type InlinePlacement =
    | { kind: 'zone'; zone: SwitcherZone }
    | { kind: 'anchor'; selector: string; position: AnchorPosition };

export const SWITCHER_ZONES: SwitcherZone[] = ['header', 'before_content', 'after_content', 'sidebar', 'footer'];

export const ZONE_LABELS: Record<SwitcherZone, string> = {
    header: 'Header',
    before_content: 'Before content',
    after_content: 'After content',
    sidebar: 'Sidebar',
    footer: 'Footer',
};

const ZONE_SELECTORS: Record<SwitcherZone, string[]> = {
    header: ['header.site-header', 'header[role="banner"]', '[role="banner"]', 'body > header', 'header'],
    before_content: ['main article .entry-content', 'main .entry-content', 'main article', 'article', 'main', '#content', '#primary'],
    after_content: ['main article .entry-content', 'main .entry-content', 'main article', 'article', 'main', '#content', '#primary'],
    sidebar: ['aside.widget-area', 'aside[role="complementary"]', '[role="complementary"]', 'aside', '#secondary'],
    footer: ['footer.site-footer', 'footer[role="contentinfo"]', '[role="contentinfo"]', 'body > footer', 'footer'],
};

// Where the host goes: into a parent before a node, or beside an element on
// its own row (the row is made on insertion, so the switcher shares the
// element's flow, scroll and stickiness).
export type PlacementTarget =
    | { parent: Element; before: Node | null }
    | { beside: Element; side: 'left' | 'right' };

export const ANCHOR_ROW_CLASS = 'twp-anchor-row';

// Puts the host where the target says and returns it; a beside target wraps
// the element and the host in one inline-flex row.
// A click on the switcher must not follow a link the anchor sits in; the
// switcher's own option links keep their default.
const guardEnclosingLink = (host: HTMLElement): void =>
{
    host.addEventListener('click', event =>
    {
        const link = (event.target as Element | null)?.closest('a');
        if (link && !host.contains(link)) event.preventDefault();
    });
};

// The row the anchor element and host share for left and right must stay
// translatable: only the host carries the no-translate class.
export const mountPlacement = (target: PlacementTarget, host: HTMLElement): HTMLElement =>
{
    guardEnclosingLink(host);
    if ('beside' in target)
    {
        const element = target.beside;
        let row = element.parentElement;
        if (!row || !row.classList.contains(ANCHOR_ROW_CLASS))
        {
            row = document.createElement('span');
            row.className = ANCHOR_ROW_CLASS;
            element.parentNode?.insertBefore(row, element);
            row.appendChild(element);
        }
        row.insertBefore(host, target.side === 'left' ? element : element.nextSibling);
        return host;
    }
    target.parent.insertBefore(host, target.before);
    return host;
};

// Takes the host out again and unwraps the row it may have made.
export const unmountPlacement = (host: HTMLElement): void =>
{
    const row = host.parentElement;
    host.remove();
    if (row && row.classList.contains(ANCHOR_ROW_CLASS) && row.parentNode)
    {
        while (row.firstChild) row.parentNode.insertBefore(row.firstChild, row);
        row.remove();
    }
};

const firstMatch = (selectors: string[], root: ParentNode): Element | null =>
{
    for (const selector of selectors)
    {
        const element = root.querySelector(selector);
        if (element && !element.closest('#wpadminbar')) return element;
    }
    return null;
};

// The element a stored zone lands in.
export const resolveZoneElement = (zone: SwitcherZone, root: ParentNode = document): Element | null =>
    firstMatch(ZONE_SELECTORS[zone], root);

export const resolvePlacementTarget = (placement: InlinePlacement | null | undefined, root: ParentNode = document): PlacementTarget | null =>
{
    if (!placement) return null;
    if (placement.kind === 'zone')
    {
        const element = resolveZoneElement(placement.zone, root);
        if (!element) return null;
        switch (placement.zone)
        {
            case 'header':
            case 'footer':
            case 'sidebar':
                return { parent: element, before: null };
            case 'before_content':
                return { parent: element, before: element.firstChild };
            case 'after_content':
                return { parent: element, before: null };
        }
    }
    let element: Element | null = null;
    try
    {
        // The first match that belongs to the page itself: the admin bar and
        // the plugin's own elements exist only for some visitors.
        element = Array.from(root.querySelectorAll(placement.selector)).find(candidate => !isTransientElement(candidate)) ?? null;
    }
    catch
    {
        return null;
    }
    if (!element || element === document.body || element === document.documentElement) return null;
    switch (placement.position)
    {
        case 'before': return element.parentElement ? { parent: element.parentElement, before: element } : null;
        case 'after': return element.parentElement ? { parent: element.parentElement, before: element.nextSibling } : null;
        case 'left': return element.parentElement ? { beside: element, side: 'left' } : null;
        case 'right': return element.parentElement ? { beside: element, side: 'right' } : null;
        case 'prepend': return { parent: element, before: element.firstChild };
        case 'append': return { parent: element, before: null };
    }
};

// A path the placement can be found by again on a later load: the nearest id
// when there is one, otherwise a tag/nth-of-type chain from the body.
// Elements a visitor's page does not carry: the admin bar, the editor's
// overlays and the switcher itself. A path must neither count them as
// siblings nor land on them, or the same path names another element for
// the visitor than for the administrator who saved it.
const TRANSIENT_SELECTOR = '#wpadminbar, .twp-editor, .twp-container, [data-twp-switcher], .twp-placed';

export const isTransientElement = (element: Element): boolean =>
    element.matches(TRANSIENT_SELECTOR) || element.closest(TRANSIENT_SELECTOR) !== null;

export const cssPath = (element: Element): string =>
{
    const steps: string[] = [];
    let current: Element | null = element;
    while (current && current !== document.body && current !== document.documentElement)
    {
        if (current.id && /^[A-Za-z][\w-]*$/.test(current.id) && document.querySelectorAll(`#${current.id}`).length === 1)
        {
            steps.unshift(`#${current.id}`);
            return steps.join(' > ');
        }
        const tag = current.tagName.toLowerCase();
        const parent: Element | null = current.parentElement;
        const siblings = parent
            ? Array.from(parent.children).filter(child => child.tagName === current!.tagName && !isTransientElement(child))
            : [];
        const index = siblings.indexOf(current) + 1;
        // A same-tag sibling that is itself transient is not counted, so the
        // index is the visitor's; the path uses nth-of-type whenever the
        // visitor's page has more than one such sibling.
        steps.unshift(siblings.length > 1 ? `${tag}:nth-of-type(${index})` : tag);
        current = parent;
    }
    steps.unshift('body');
    return steps.join(' > ');
};

export const effectivePlacement = (pagePlacement: InlinePlacement | null | undefined, sitePlacement: InlinePlacement | null | undefined): InlinePlacement | null =>
    pagePlacement ?? sitePlacement ?? null;

// A placement the Pro editor has just saved, applied on the open page so the
// switcher moves to its new place without a reload. Undefined while nothing
// was applied: the page then uses the placements it loaded with. Null means
// the placement in force was removed.
let applied: { placement: InlinePlacement | null } | undefined;
let appliedVersion = 0;
const appliedListeners = new Set<() => void>();

export const livePlacement = {
    current: (): { placement: InlinePlacement | null } | undefined => applied,
    version: (): number => appliedVersion,
    apply(placement: InlinePlacement | null): void
    {
        applied = { placement };
        appliedVersion++;
        for (const listener of appliedListeners) listener();
    },
    subscribe(listener: () => void): () => void
    {
        appliedListeners.add(listener);
        return () => { appliedListeners.delete(listener); };
    },
    /** For tests: back to the placements the page loaded with. */
    reset(): void
    {
        applied = undefined;
        appliedVersion = 0;
    },
};
