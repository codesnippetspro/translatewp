import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import { dragHint } from '@/apps/translatewp/dragHint';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;
const original = window.TranslateWP;

beforeEach(() =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    dragHint.reset();
    window.TranslateWP = { canManage: true, context: {} } as Window['TranslateWP'];
});

afterEach(() =>
{
    act(() => root.unmount());
    container.remove();
    document.documentElement.classList.remove('twp-placing');
    window.TranslateWP = original;
});

const render = async () =>
{
    await act(async () => root.render(<SwitcherView
        languages={[{ locale: 'en', slug: 'en', active: true, default: true }]}
        switcher={{}} selectedLocale="en" isOpen={false} notice={null} variant="inline"
        onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
    />));
};

it('shows the inline manager handle only while placement is active, without floating bindings or a dismissed hint', async () =>
{
    await render();
    const dot = container.querySelector('[data-twp-drag-dot]') as HTMLElement;

    expect(dot).not.toBeNull();
    expect(container.querySelector('[data-drag-handle]')).toBeNull();
    expect(dragHint.isDone()).toBe(false);
    expect(getComputedStyle(dot).display).toBe('none');

    document.documentElement.classList.add('twp-placing');
    expect(getComputedStyle(dot).display).toBe('inline-flex');

    document.documentElement.classList.remove('twp-placing');
    expect(getComputedStyle(dot).display).toBe('none');
});

it('renders no inline drag handle for a visitor even when placement styling is present', async () =>
{
    window.TranslateWP.canManage = false;
    document.documentElement.classList.add('twp-placing');
    await render();

    expect(container.querySelector('[data-twp-drag-dot]')).toBeNull();
});

it('renders no inline drag handle for a manager without switcher settings permission', async () =>
{
    window.TranslateWP.can = { edit: true, retranslate: true, settings: false, lexicon: true };
    document.documentElement.classList.add('twp-placing');
    await render();

    expect(container.querySelector('[data-twp-drag-dot]')).toBeNull();
});
