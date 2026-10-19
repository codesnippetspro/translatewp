import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import { resolveSwitcherChrome } from '@/apps/translatewp/resolveSwitcherChrome';

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
                languages={[{ locale: 'de', slug: 'de', active: true, default: false } as never]}
                switcher={switcher}
                selectedLocale="de"
                isOpen={false}
                notice="Translating this page for you, a few more seconds."
                onOpenChange={() => undefined}
                onLanguageSelect={() => undefined}
                onDismissNotice={() => undefined}
            />
        );
    });
    return container;
};

// The declarations of exactly the `.twp-toast { … }` rule, nothing before or after it.
const toastRule = (container: HTMLElement): string =>
{
    const css = [...container.querySelectorAll('style')].map(s => s.textContent ?? '').join('\n');
    const match = css.match(/(?:^|\s)\.twp-toast\s*\{([^}]*)\}/);
    return match ? match[1] : '';
};

describe('translating toast', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it.each([
        [{ type: 'dropdown', icon_size: 24, padding: 12 }],
        [{ type: 'dropdown', icon_size: 40, padding: 16 }],
    ])('is as tall as the switcher trigger for the settings %j', async (settings) =>
    {
        const switcher = settings as unknown as Switcher;
        const container = await renderWith(switcher);
        const { triggerSizePx } = resolveSwitcherChrome(switcher);
        const rule = toastRule(container);

        expect(container.querySelector('.twp-toast')?.textContent).toContain('Translating this page');
        // At least as tall as the switcher; a longer line wraps and grows it.
        expect(rule).toMatch(new RegExp(`min-height:\\s*${triggerSizePx}px !important`));
        expect(rule).toMatch(/align-items:\s*center !important/);
        expect(rule).toMatch(/white-space:\s*normal !important/);
        // It never runs past its own edge and over the switcher.
        expect(rule).toMatch(/max-width:\s*min\(20rem, calc\(100vw - \d+px - 2rem\)\) !important/);
        expect(rule).toMatch(/box-sizing:\s*border-box !important/);
    });

    it('follows the trigger size, so two settings give two different toast heights', async () =>
    {
        const small = { type: 'dropdown', icon_size: 24, padding: 12 } as unknown as Switcher;
        const large = { type: 'dropdown', icon_size: 40, padding: 16 } as unknown as Switcher;
        const heightOf = (rule: string) => Number(rule.match(/height:\s*(\d+)px/)?.[1] ?? NaN);

        const smallHeight = heightOf(toastRule(await renderWith(small)));
        document.body.innerHTML = '';
        const largeHeight = heightOf(toastRule(await renderWith(large)));

        expect(smallHeight).toBe(resolveSwitcherChrome(small).triggerSizePx);
        expect(largeHeight).toBe(resolveSwitcherChrome(large).triggerSizePx);
        expect(largeHeight).toBeGreaterThan(smallHeight);
    });
});
