import { cssPath, effectivePlacement, resolvePlacementTarget, resolveZoneElement } from '@/apps/translatewp/inlinePlacement';

describe('inline placement resolution', () =>
{
    beforeEach(() =>
    {
        document.body.innerHTML = `
            <header class="site-header"><nav>menu</nav></header>
            <main><article><div class="entry-content"><p>one</p><p>two</p></div></article></main>
            <aside class="widget-area"></aside>
            <footer class="site-footer"></footer>`;
    });

    it('resolves each suggested zone to its landmark', () =>
    {
        expect(resolveZoneElement('header')?.className).toBe('site-header');
        expect(resolveZoneElement('sidebar')?.className).toBe('widget-area');
        expect(resolveZoneElement('footer')?.className).toBe('site-footer');
        const before = resolvePlacementTarget({ kind: 'zone', zone: 'before_content' });
        expect(before?.parent.className).toBe('entry-content');
        expect((before?.before as Element).textContent).toBe('one');
        const after = resolvePlacementTarget({ kind: 'zone', zone: 'after_content' });
        expect(after?.parent.className).toBe('entry-content');
        expect(after?.before).toBeNull();
    });

    it('resolves an unrestricted anchor by its recorded path and position', () =>
    {
        const second = document.querySelectorAll('p')[1];
        const selector = cssPath(second);
        expect(document.querySelector(selector)).toBe(second);
        const target = resolvePlacementTarget({ kind: 'anchor', selector, position: 'before' });
        expect(target?.parent.className).toBe('entry-content');
        expect(target?.before).toBe(second);
        expect(resolvePlacementTarget({ kind: 'anchor', selector, position: 'append' })?.parent).toBe(second);
    });

    it('resolves to nothing when the theme no longer has the anchor or the zone', () =>
    {
        expect(resolvePlacementTarget({ kind: 'anchor', selector: '#gone', position: 'after' })).toBeNull();
        expect(resolvePlacementTarget({ kind: 'anchor', selector: '???', position: 'after' })).toBeNull();
        document.querySelector('aside')?.remove();
        expect(resolvePlacementTarget({ kind: 'zone', zone: 'sidebar' })).toBeNull();
        expect(resolvePlacementTarget(null)).toBeNull();
    });

    it('prefers a unique id in the recorded path', () =>
    {
        document.body.innerHTML = '<div id="hero"><span>x</span></div>';
        expect(cssPath(document.querySelector('span')!)).toBe('#hero > span');
    });

    it('a page placement wins over the site-wide one and hands back when removed', () =>
    {
        const site = { kind: 'zone' as const, zone: 'footer' as const };
        const page = { kind: 'zone' as const, zone: 'header' as const };
        expect(effectivePlacement(page, site)).toBe(page);
        expect(effectivePlacement(null, site)).toBe(site);
        expect(effectivePlacement(undefined, undefined)).toBeNull();
    });
});

describe('placement paths across an admin and a visitor page', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it('counts no admin-only or plugin-inserted sibling, so the visitor resolves the same element', () =>
    {
        document.body.innerHTML = '<div id="wpadminbar"></div><div class="twp-editor twp-editor-overlay"></div><div class="site"><p>One</p><p>Two</p></div><div class="twp-container"></div>';
        const site = document.querySelector('.site') as Element;
        const selector = cssPath(site);

        expect(selector).toBe('body > div');

        document.body.innerHTML = '<div class="site"><p>One</p><p>Two</p></div>';
        const target = resolvePlacementTarget({ kind: 'anchor', selector, position: 'after' });
        expect(target?.parent).toBe(document.body);
        expect((target?.before as Element | null)).toBeNull();
    });

    it('skips the admin bar when the same path also matches it on the admin page', () =>
    {
        document.body.innerHTML = '<div id="wpadminbar"></div><div class="site"><p>One</p></div>';
        const target = resolvePlacementTarget({ kind: 'anchor', selector: 'body > div', position: 'prepend' });
        expect((target?.parent as Element).className).toBe('site');
    });
});

describe('anchors on a side of the element', () =>
{
    beforeEach(() =>
    {
        document.body.innerHTML = '<footer id="site-footer"><p class="copy">Copyright</p></footer>';
    });

    it('a host inside a link keeps the link from following a click on the switcher, not on its own options', async () =>
    {
        const { mountPlacement } = await import('@/apps/translatewp/inlinePlacement');
        document.body.innerHTML = '<header><a id="phone" href="https://example.test/call"><span class="label">Call</span></a></header>';
        const host = document.createElement('span');
        host.innerHTML = '<button class="trigger">EN</button><a class="option" href="/fr/">FR</a>';
        mountPlacement(resolvePlacementTarget({ kind: 'anchor', selector: '#phone > span', position: 'before' })!, host);
        expect(host.closest('a')?.id).toBe('phone');

        const onTrigger = new MouseEvent('click', { bubbles: true, cancelable: true });
        host.querySelector('.trigger')!.dispatchEvent(onTrigger);
        expect(onTrigger.defaultPrevented).toBe(true);

        const onOption = new MouseEvent('click', { bubbles: true, cancelable: true });
        host.querySelector('.option')!.dispatchEvent(onOption);
        expect(onOption.defaultPrevented).toBe(false);

        const onLabel = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('.label')!.dispatchEvent(onLabel);
        expect(onLabel.defaultPrevented).toBe(false);
    });

    it('places the switcher beside the element on its own row for left and right, and takes it out again', async () =>
    {
        const { mountPlacement, unmountPlacement, ANCHOR_ROW_CLASS } = await import('@/apps/translatewp/inlinePlacement');
        const copy = document.querySelector('.copy') as HTMLElement;
        const host = document.createElement('span');
        host.className = 'twp-placed';

        const right = resolvePlacementTarget({ kind: 'anchor', selector: '#site-footer > p', position: 'right' });
        expect(right && 'beside' in right && right.side).toBe('right');
        mountPlacement(right!, host);
        const row = copy.parentElement as HTMLElement;
        expect(row.classList.contains(ANCHOR_ROW_CLASS)).toBe(true);
        expect(row.parentElement?.id).toBe('site-footer');
        expect([...row.children].map(c => c.className)).toEqual(['copy', 'twp-placed']);

        expect(row.classList.contains('translatewp-no-translate')).toBe(false);
        expect(row.closest('.translatewp-no-translate, [translate=no], [data-no-translate]')).toBeNull();

        unmountPlacement(host);
        expect(document.querySelector(`.${ANCHOR_ROW_CLASS}`)).toBeNull();
        expect(copy.parentElement?.id).toBe('site-footer');

        const left = resolvePlacementTarget({ kind: 'anchor', selector: '#site-footer > p', position: 'left' });
        mountPlacement(left!, host);
        expect([...(copy.parentElement as HTMLElement).children].map(c => c.className)).toEqual(['twp-placed', 'copy']);
        unmountPlacement(host);
    });

    it('still reads a stored prepend or append placement', () =>
    {
        const prepend = resolvePlacementTarget({ kind: 'anchor', selector: '#site-footer', position: 'prepend' });
        expect(prepend && 'parent' in prepend && prepend.parent.id).toBe('site-footer');
        const append = resolvePlacementTarget({ kind: 'anchor', selector: '#site-footer', position: 'append' });
        expect(append && 'parent' in append && append.before).toBeNull();
    });

    it('resolves nothing for an anchor the page does not have', () =>
    {
        expect(resolvePlacementTarget({ kind: 'anchor', selector: '#not-here', position: 'after' })).toBeNull();
    });
});
