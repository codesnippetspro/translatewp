import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SwitcherTypeControl } from '@/apps/admin/components/Languages/Switcher/SwitcherTypeControl';
import { ShowSelectedLanguageControl } from '@/apps/admin/components/Languages/Switcher/ShowSelectedLanguageControl';
import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const render = async (element: React.ReactNode): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(element);
    });
    return container;
};

const proBadgeCount = (container: HTMLElement): number =>
    Array.from(container.querySelectorAll('span'))
        .filter(node => (node.textContent ?? '').trim() === 'Pro').length;

const unlockControlCount = (container: HTMLElement): number =>
    Array.from(container.querySelectorAll('[aria-label]'))
        .filter(node => (node.getAttribute('aria-label') ?? '').includes('Unlock')).length;

describe('the switcher page only offers options the free plugin renders', () =>
{
    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0)) {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
    });

    it('offers the switcher types the public switcher implements: two Free, the Side tab locked on Free', async () =>
    {
        const container = await render(<SwitcherTypeControl value="dropdown" onChange={() => {}} />);

        expect(container.textContent).toContain('Dropdown');
        expect(container.textContent).toContain('Native dropdown');
        expect(container.textContent).toContain('Side tab');
        expect(container.textContent).not.toContain('Native select');
        expect(container.textContent).not.toContain('Flat List');
        expect(container.textContent).not.toContain('Buttons');
        expect(container.textContent).not.toContain('Modal');
        expect(container.textContent).not.toContain('Popup');
        expect(proBadgeCount(container)).toBe(1);
        expect(unlockControlCount(container)).toBe(1);
    });

    it('offers three working Free options for the closed switcher', async () =>
    {
        const chosen: string[] = [];
        const container = await render(<ShowSelectedLanguageControl value="name_flag" onChange={(value) => chosen.push(value)} />);
        const cards = Array.from(container.querySelectorAll('button'));

        expect(cards.map(card => card.textContent)).toEqual([
            expect.stringContaining('Flag and name'),
            expect.stringContaining('Flag only'),
            expect.stringContaining('Icon only'),
        ]);
        expect(cards.map(card => card.getAttribute('aria-checked'))).toEqual(['true', 'false', 'false']);
        expect(proBadgeCount(container)).toBe(0);
        expect(unlockControlCount(container)).toBe(0);

        await act(async () =>
        {
            cards[1].click();
            cards[2].click();
            cards[0].click();
        });
        expect(chosen).toEqual(['flag', 'icon', 'name_flag']);
    });

    it('shows the Pro placements once, as the one locked row, and no position cards', async () =>
    {
        const container = await render(<LanguagesSwitcher />);
        const where = container.querySelector('[data-twp-where-it-shows]') as HTMLElement;

        // The page has three locked things on Free: the Side tab, the inline placement and the Support TranslateWP switch.
        expect(proBadgeCount(container)).toBe(3);
        expect(unlockControlCount(container)).toBe(3);
        expect(proBadgeCount(container.querySelector('[data-support-translatewp]') as HTMLElement)).toBe(1);
        expect(proBadgeCount(where)).toBe(1);
        expect(unlockControlCount(where)).toBe(1);
        expect(container.textContent).not.toContain('Top left');
        expect(container.textContent).not.toContain('Bottom right');
        expect(container.textContent).not.toContain('Top right (default)');
    });

    it('names placements only under Where it shows and no type it does not render', async () =>
    {
        const container = await render(<LanguagesSwitcher />);
        // "Where it shows" names the placements on purpose, and the
        // preview's <style> is CSS ("inline-block"), not copy; the sweep
        // covers the rest of the page.
        container.querySelectorAll('[data-twp-where-it-shows], style').forEach(node => node.remove());
        const text = (container.textContent ?? '').toLowerCase();

        for (const removed of ['menu item', 'appearance > menus', 'shortcode', 'twp-languages', 'gutenberg', 'block', 'flat list', 'buttons', 'modal']) {
            expect(text).not.toContain(removed);
        }
        expect(text).toContain('dropdown');
        expect(text).toContain('native dropdown');
    });
});
