import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import type { LanguageItem, PublicSwitcherSettings } from '@/apps/translatewp/types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const switcher: PublicSwitcherSettings = {
    type: 'dropdown',
    trigger: 'name_flag',
    show_flag: true,
    native_names: true,
    regional_names: 'full',
    icon_style: 'rectangle',
    offset: 24,
    icon_size: 24,
    padding: 12,
};

const languages: LanguageItem[] = [
    { locale: 'en-gb', slug: 'en', active: true, default: true },
    { locale: 'de', slug: 'de', active: true, default: false },
];

let root: Root | null = null;

const open = (pro: Record<string, unknown> | undefined, orbital?: boolean): HTMLElement =>
{
    window.TranslateWP = { canManage: false, context: { pro, orbital } } as unknown as typeof window.TranslateWP;
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root!.render(
        <SwitcherView
            languages={languages} switcher={switcher} selectedLocale="en-gb" isOpen={true} notice={null}
            onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
        />
    ));
    return container;
};

describe('the by TranslateWP link in the open switcher', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('shows on a Terrestrial site', () =>
    {
        const container = open({ entitled: false, installed: false, active: false });
        const link = container.querySelector('.twp-promo') as HTMLAnchorElement | null;
        expect(link?.textContent).toContain('by TranslateWP');
        expect(link?.href).toBe('https://translatewp.ai/');
        expect(link?.getAttribute('aria-label')).toContain('opens in a new tab');
    });

    it('shows on a site with no Pro state at all', () =>
    {
        expect(open(undefined).querySelector('.twp-promo')).not.toBeNull();
    });

    it('is not rendered for a visitor, who only learns the public orbital flag', () =>
    {
        expect(open(undefined, true).querySelector('.twp-promo')).toBeNull();
        act(() => root?.unmount());
        document.body.innerHTML = '';
        expect(open(undefined, false).querySelector('.twp-promo')).not.toBeNull();
    });

    it('is not rendered on an Orbital site, with or without the addon active', () =>
    {
        expect(open({ entitled: true, installed: true, active: true }).querySelector('.twp-promo')).toBeNull();
        act(() => root?.unmount());
        document.body.innerHTML = '';
        expect(open({ entitled: true, installed: false, active: false }).querySelector('.twp-promo')).toBeNull();
    });
});
