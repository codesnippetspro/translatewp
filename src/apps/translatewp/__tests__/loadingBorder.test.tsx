import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import type { LanguageItem, PublicSwitcherSettings } from '@/apps/translatewp/types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

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

const ringRule = (): string =>
{
    const css = document.querySelector('style')?.textContent ?? '';
    const start = css.indexOf('.twp-trigger::after {');
    return start === -1 ? '' : css.slice(start, css.indexOf('}', start));
};

const render = (switcher: PublicSwitcherSettings, previewLoading = false): HTMLElement =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root!.render(
        <SwitcherView
            languages={languages} switcher={switcher} selectedLocale="en-gb" isOpen={false} notice={null}
            previewLoading={previewLoading}
            onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
        />
    ));
    return container.querySelector('.twp-trigger') as HTMLElement;
};

describe('the translating loading ring', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('uses the highlight colour as the ring by default so a saved site looks unchanged', () =>
    {
        render(PHP_DEFAULTS);
        expect(ringRule()).toContain('#0e3ecd 300deg');
    });

    it('colours the ring with the configured loading border colour, alpha kept', () =>
    {
        render({ ...PHP_DEFAULTS, highlight_bg: '#112233', loading_border_color: '#ff000080' });
        const rule = ringRule();
        expect(rule).toContain('#ff000080 300deg');
        expect(rule).not.toContain('#112233 300deg');
    });

    it('falls back to a customised highlight when no loading colour is set', () =>
    {
        render({ ...PHP_DEFAULTS, highlight_bg: '#abcdef' });
        expect(ringRule()).toContain('#abcdef 300deg');
    });

    it('shows the ring in the preview without reading as busy when previewLoading is set', () =>
    {
        const trigger = render({ ...PHP_DEFAULTS, variant: 'preview' } as PublicSwitcherSettings, true);
        expect(trigger.getAttribute('data-translating')).toBe('true');
        expect(trigger.hasAttribute('aria-busy')).toBe(false);
    });

    it('leaves the ring off and nothing busy when previewLoading is not set', () =>
    {
        const trigger = render(PHP_DEFAULTS, false);
        expect(trigger.hasAttribute('data-translating')).toBe(false);
        expect(trigger.hasAttribute('aria-busy')).toBe(false);
    });

    it('keeps a steady, unanimated ring under reduced motion', () =>
    {
        render({ ...PHP_DEFAULTS, loading_border_color: '#00ff00' });
        const css = document.querySelector('style')?.textContent ?? '';
        expect(css).toContain('@media (prefers-reduced-motion: reduce)');
        const reduced = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'));
        expect(reduced).toContain('animation: none !important;');
        expect(reduced).toContain('background: #00ff00 !important;');
    });
});
