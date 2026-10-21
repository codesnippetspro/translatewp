import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { SwitcherView } from '@/apps/translatewp/SwitcherView';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const originalRect = HTMLElement.prototype.getBoundingClientRect;
const originalOffset = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight');
const originalClient = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientHeight');
const originalScroll = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollHeight');
let triggerTop: number;
let root: Root;
let container: HTMLDivElement;

const rect = (top: number, height: number) => ({ top, bottom: top + height, left: 0, right: 200, width: 200, height, x: 0, y: top, toJSON() {} });

beforeEach(() =>
{
    triggerTop = 10;
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 400 });
    HTMLElement.prototype.getBoundingClientRect = function ()
    {
        if (this.classList.contains('twp-dropdown-item')) return rect(0, 40) as DOMRect;
        if (this.classList.contains('twp-trigger')) return rect(triggerTop, 40) as DOMRect;
        if (this.classList.contains('twp-dropdown')) return rect(triggerTop > 200 ? triggerTop - 244 : triggerTop + 44, 240) as DOMRect;
        return originalRect.call(this);
    };
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get() { return this.classList.contains('twp-dropdown') || this.classList.contains('twp-dropdown-list') ? 240 : 0; } });
    Object.defineProperty(HTMLElement.prototype, 'clientHeight', { configurable: true, get() { return this.classList.contains('twp-dropdown-list') ? Number.parseFloat(this.style.getPropertyValue('--twp-list-max-height')) || 240 : 0; } });
    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', { configurable: true, get() { return this.classList.contains('twp-dropdown-list') ? this.children.length * 40 : 0; } });
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});

afterEach(async () =>
{
    await act(async () => root.unmount());
    container.remove();
    HTMLElement.prototype.getBoundingClientRect = originalRect;
    for (const [name, descriptor] of [['offsetHeight', originalOffset], ['clientHeight', originalClient], ['scrollHeight', originalScroll]] as const)
        if (descriptor) Object.defineProperty(HTMLElement.prototype, name, descriptor);
        else delete (HTMLElement.prototype as unknown as Record<string, unknown>)[name];
});

const render = async (count: number, variant: 'floating' | 'inline' = 'floating') =>
{
    await act(async () => root.render(<SwitcherView
        languages={Array.from({ length: count }, (_, i) => ({ locale: `en-${i}`, slug: `en-${i}`, active: true, default: i === 0 })) as never}
        variant={variant} switcher={{ type: 'dropdown' } as never} selectedLocale="en-0" isOpen={true} notice={null}
        onOpenChange={() => undefined} onLanguageSelect={() => undefined} onDismissNotice={() => undefined}
    />));
    return container.querySelector('.twp-dropdown-list') as HTMLDivElement;
};

it('fits a short list to its contents without a scroll invitation', async () =>
{
    const list = await render(2);
    expect(list.style.getPropertyValue('--twp-list-max-height')).toBe('80px');
    expect(container.querySelector('.twp-scroll-cue')).toBeNull();
});

it('shows a partial next row and clears the lower cue at the end', async () =>
{
    const list = await render(12);
    expect(list.style.getPropertyValue('--twp-list-max-height')).toBe('304px');
    expect(container.querySelector('.twp-scroll-cue-down')).not.toBeNull();
    await act(async () => { list.scrollTop = list.scrollHeight - list.clientHeight; list.dispatchEvent(new Event('scroll')); });
    expect(container.querySelector('.twp-scroll-cue-down')).toBeNull();
    expect(container.querySelector('.twp-scroll-cue-up')).not.toBeNull();
});

it('opens above a low trigger and keeps its list within the screen', async () =>
{
    triggerTop = 310;
    const list = await render(12);
    expect(list.style.getPropertyValue('--twp-list-max-height')).toBe('264px');
    expect((container.querySelector('.twp-popover-stack') as HTMLElement).style.bottom).toBe('100%');
    expect(container.querySelector('.twp-scroll-cue-down')).not.toBeNull();
});

it('keeps a visible teaser when the screen would otherwise leave only a thin sliver', async () =>
{
    const list = await render(12);
    await act(async () =>
    {
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 264 });
        window.dispatchEvent(new Event('resize'));
    });
    expect(list.style.getPropertyValue('--twp-list-max-height')).toBe('184px');
    expect(container.querySelector('.twp-scroll-cue-down')).not.toBeNull();
});

it('sizes a switcher placed inside page content too', async () =>
{
    const list = await render(12, 'inline');
    expect(list.style.getPropertyValue('--twp-list-max-height')).toBe('304px');
    expect(container.querySelector('.twp-scroll-cue-down')).not.toBeNull();
});
