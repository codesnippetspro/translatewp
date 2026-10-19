import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import type { LanguageItem, PublicSwitcherSettings } from '@/apps/translatewp/types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const languages: LanguageItem[] = [
    { locale: 'de-at', slug: 'de', active: true, default: true },
    { locale: 'en-us', slug: 'en', active: true, default: false },
    { locale: 'it', slug: 'it', active: true, default: false },
    { locale: 'pt-br', slug: 'pt', active: true, default: false, switcher_label: 'Brasil' },
];

let root: Root | null = null;

const render = (switcher: PublicSwitcherSettings): HTMLElement =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root!.render(
        <SwitcherView
            languages={languages} switcher={switcher} selectedLocale="de-at" isOpen notice={null}
            onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
        />
    ));
    return container;
};

const dropdownLabels = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('.twp-dropdown-item')).map(item => item.textContent);

const selectLabels = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('option')).map(option => option.textContent);

describe('the switcher labels regional languages by the Regional language names setting', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('shows full names by default', () =>
    {
        expect(dropdownLabels(render({ show_flag: false }))).toEqual(['Österreichisches Deutsch', 'American English', 'Italiano', 'Brasil']);
    });

    it('shows the base name with the region code when Short and flags are off', () =>
    {
        expect(dropdownLabels(render({ show_flag: false, regional_names: 'short' }))).toEqual(['Deutsch (AT)', 'English (US)', 'Italiano', 'Brasil']);
    });

    it('shows the region flag and the base name when Short and flags are on', () =>
    {
        const container = render({ show_flag: true, regional_names: 'short' });
        expect(dropdownLabels(container)).toEqual(['Deutsch', 'English', 'Italiano', 'Brasil']);
        expect(container.querySelectorAll('.twp-dropdown-item .twp-flag-wrap *').length).toBeGreaterThan(0);
    });

    it('follows the base-language names setting', () =>
    {
        expect(dropdownLabels(render({ show_flag: false, regional_names: 'short', native_names: false }))).toEqual(['German (AT)', 'English (US)', 'Italian', 'Brasil']);
    });

    it('a native select with flags on carries the emoji flag before each name and the region code after it', () =>
    {
        expect(selectLabels(render({ type: 'native_select', show_flag: true, regional_names: 'short' }))).toEqual(['🇦🇹 Deutsch', '🇺🇸 English', '🇮🇹 Italiano', '🇧🇷 Brasil']);
        expect(selectLabels(render({ type: 'native_select', show_flag: false, regional_names: 'short' }))).toEqual(['Deutsch (AT)', 'English (US)', 'Italiano', 'Brasil']);
    });
});
