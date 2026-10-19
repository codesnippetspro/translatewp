import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import { resolveSwitcherChrome } from '@/apps/translatewp/resolveSwitcherChrome';
import { sideTabCss } from '@/apps/translatewp/sideTab';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

type Switcher = Parameters<typeof SwitcherView>[0]['switcher'];

const renderWith = async (switcher: Switcher): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    await act(async () =>
    {
        createRoot(container).render(
            <SwitcherView
                languages={[
                    { locale: 'de', slug: 'de', active: true, default: false },
                    { locale: 'zh-hans', slug: 'zh', active: true, default: false },
                ] as never}
                switcher={switcher}
                selectedLocale="de"
                isOpen={true}
                onOpenChange={() => undefined}
                onLanguageSelect={() => undefined}
            />
        );
    });
    return container;
};

// The declarations of exactly the `.twp-dropdown { … }` rule.
const dropdownRule = (container: HTMLElement): string =>
{
    const css = [...container.querySelectorAll('style')].map(s => s.textContent ?? '').join('\n');
    const match = css.match(/(?:^|\s)\.twp-dropdown\s*\{([^}]*)\}/);
    return match ? match[1] : '';
};

describe('switcher dropdown width', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it.each([
        [{ type: 'dropdown', offset: 16 }],
        [{ type: 'dropdown', offset: 40 }],
    ])('fits its longest language name, within the screen, for %j', async (settings) =>
    {
        const switcher = settings as unknown as Switcher;
        const container = await renderWith(switcher);
        const { insetPx } = resolveSwitcherChrome(switcher);
        const rule = dropdownRule(container);

        expect(container.querySelector('.twp-dropdown')).not.toBeNull();
        expect(rule).toMatch(/width:\s*max-content !important/);
        // Never wider than the room the switcher's place leaves; off a placed switcher, the inset rule.
        expect(rule).toContain('min-width: min(12rem, var(--twp-list-room, 12rem)) !important');
        expect(rule).toContain(`max-width: min(20rem, var(--twp-list-room, calc(100vw - 2 * ${insetPx}px))) !important`);
    });

    it('measures the room of a dragged floating switcher from where it actually sits', async () =>
    {
        const container = await renderWith({ type: 'dropdown', floating_position: { anchor: 'top-left', x: 170, y: 40 } } as unknown as Switcher);
        const css = [...container.querySelectorAll('style')].map(s => s.textContent ?? '').join('\n');
        const floating = container.querySelector('.twp-container[data-anchor="top-left"]') as HTMLElement;

        expect(floating.style.getPropertyValue('--twp-position-x')).toBe('170px');
        expect(css).toMatch(/\.twp-container\[data-anchor\]\s*\{[^}]*--twp-list-room:\s*calc\(100vw - min\(var\(--twp-position-x\), max\(0px, 100vw - \d+px\)\) - 0\.5rem\)/);
    });

    it('measures the room of a side tab from its place along the edge and the way its list opens', () =>
    {
        const css = sideTabCss(120);

        expect(css).toContain('.twp-container.twp-side-tab[data-edge="bottom"][data-half="start"] { --twp-list-room: calc(100vw - var(--twp-p) * (100vw - 120px) - 0.5rem); }');
        expect(css).toContain('.twp-container.twp-side-tab[data-edge="bottom"][data-half="end"] { --twp-list-room: calc(var(--twp-p) * (100vw - 120px) + 120px - 0.5rem); }');
        expect(css).toContain('.twp-container.twp-side-tab[data-mobile-edge="left"][data-mobile-half="start"] { --twp-list-room: calc(100vw - 120px - 0.25rem - 0.5rem); }');
    });
});
