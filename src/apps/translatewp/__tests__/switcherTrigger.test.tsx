import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import type { LanguageItem, PublicSwitcherSettings } from '@/apps/translatewp/types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

// The frontend defaults from Entities/Scripts/TranslateWP.php.
const PHP_DEFAULTS: PublicSwitcherSettings = {
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

const cssRule = (selector: string): string =>
{
    const css = document.querySelector('style')?.textContent ?? '';
    const start = css.indexOf(`${selector} {`);
    return start === -1 ? '' : css.slice(start, css.indexOf('}', start));
};

const trigger = (switcher: PublicSwitcherSettings, selectedLocale = 'en-gb', items: LanguageItem[] = languages): HTMLElement =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root!.render(
        <SwitcherView
            languages={items} switcher={switcher} selectedLocale={selectedLocale} isOpen={false} notice={null}
            onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
        />
    ));
    return container.querySelector('.twp-trigger') as HTMLElement;
};

describe('the closed switcher trigger', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        document.documentElement.removeAttribute('dir');
    });

    it('caps the name trigger to the viewport minus both insets and clips a long name', () =>
    {
        trigger({ ...PHP_DEFAULTS, offset: 40 });
        expect(cssRule('.twp-trigger[data-trigger="name_flag"]')).toContain('max-width: calc(100vw - 2 * 40px) !important;');
        const label = cssRule('.twp-trigger .twp-trigger-label');
        expect(label).toContain('min-width: 0 !important;');
        expect(label).toContain('overflow: hidden !important;');
        expect(label).toContain('text-overflow: ellipsis !important;');
    });

    it('renders the label under a right-to-left page with the same size limit', () =>
    {
        document.documentElement.setAttribute('dir', 'rtl');
        const button = trigger(PHP_DEFAULTS, 'ar', [
            { locale: 'ar', slug: 'ar', active: true, default: true },
            { locale: 'en-gb', slug: 'en', active: true, default: false },
        ]);
        const label = button.querySelector('.twp-trigger-label');
        expect(label).not.toBeNull();
        expect(label?.textContent?.trim()).not.toBe('');
        expect(label?.textContent).toMatch(/[\u0600-\u06FF]/);
        expect(cssRule('.twp-trigger[data-trigger="name_flag"]')).toContain('max-width: calc(100vw - 2 * 24px) !important;');
    });

    it('the default trigger shows the selected language flag and native name', () =>
    {
        const button = trigger(PHP_DEFAULTS);
        expect(button.textContent).toContain('English');
        expect(button.querySelector('.twp-flag-wrap')).not.toBeNull();
        expect(button.querySelector('.twp-trigger-caret')).not.toBeNull();
        expect(button.getAttribute('data-trigger')).toBe('name_flag');
    });

    it('shows the flag and name when no trigger is stored', () =>
    {
        const button = trigger({ ...PHP_DEFAULTS, trigger: undefined }, 'de');
        expect(button.textContent).toContain('Deutsch');
        expect(button.querySelector('.twp-flag-wrap')).not.toBeNull();
    });

    it('shows only the flag for Flag only', () =>
    {
        const button = trigger({ ...PHP_DEFAULTS, trigger: 'flag' });
        expect(button.textContent).not.toContain('English');
        expect(button.querySelector('.twp-flag-wrap')).not.toBeNull();
        expect(button.getAttribute('aria-label')).toContain('English');
    });

    it('keeps the TranslateWP icon for Icon only', () =>
    {
        const button = trigger({ ...PHP_DEFAULTS, trigger: 'icon' });
        expect(button.textContent).not.toContain('English');
        expect(button.querySelector('.twp-flag-wrap')).toBeNull();
        expect(button.querySelector('svg')).not.toBeNull();
        // The mark follows the switcher's text colour; its outline is never filled in.
        expect(button.querySelector('svg[data-twp-mark="current"]')).not.toBeNull();
        const css = document.querySelector('style')?.textContent ?? '';
        expect(css).toContain('.twp-trigger svg[data-twp-mark] path[stroke]');
        expect(css).toContain('.twp-trigger svg:not([data-twp-mark]) path');
    });

    it('clamps the floating switcher to the measured trigger width, not the square size', () =>
    {
        const original = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetWidth');
        Object.defineProperty(HTMLElement.prototype, 'offsetWidth', { configurable: true, get: () => 164 });
        try {
            trigger(PHP_DEFAULTS);
            const css = document.querySelector('style')?.textContent ?? '';
            expect(css).toContain('100% - 164px');
            expect(css).not.toContain('100% - 48px');
        } finally {
            if (original) Object.defineProperty(HTMLElement.prototype, 'offsetWidth', original);
        }
    });
});
