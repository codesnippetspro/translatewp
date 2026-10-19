import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';

jest.mock('@/shared/api/admin', () => ({ updateAdminData: jest.fn(() => Promise.resolve({ success: true })) }));

import { SwitcherView } from '../SwitcherView';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;
const onOpenChange = jest.fn();
const props = {
	languages: [{ locale: 'en', slug: 'en', active: true, default: true }, { locale: 'de', slug: 'de', active: true, default: false }],
	selectedLocale: 'de', notice: null,
	onOpenChange, onLanguageSelect: jest.fn(), onDismissNotice: jest.fn(),
};

const render = async (switcher: Record<string, unknown>, extra: Record<string, unknown> = {}) => {
	await act(async () => { root.render(<SwitcherView {...props} isOpen={false} switcher={{ type: 'side_tab', ...switcher } as any} {...extra} />); });
};
const tab = () => container.querySelector('.twp-container') as HTMLElement;

beforeEach(() => {
	window.TranslateWP = { canManage: false, context: {} } as any;
	container = document.createElement('div'); document.body.appendChild(container);
	root = createRoot(container);
	onOpenChange.mockReset();
});

afterEach(() => { act(() => root.unmount()); container.remove(); });

it('attaches the tab to its stored edge, each screen width on its own', async () => {
	await render({ side_tab_position: { edge: 'right', offset: 30 }, side_tab_position_mobile: { edge: 'left', offset: 70 } });
	expect(tab().classList.contains('twp-side-tab')).toBe(true);
	expect(tab().getAttribute('data-edge')).toBe('right');
	expect(tab().getAttribute('data-mobile-edge')).toBe('left');
	expect(tab().style.getPropertyValue('--twp-tab-share')).toBe('30');
	expect(tab().style.getPropertyValue('--twp-tab-share-mobile')).toBe('70');
});

it.each([
	[{ edge: 'right', offset: 30 }, 'start'],
	[{ edge: 'right', offset: 50 }, 'end'],
	[{ edge: 'bottom', offset: 85 }, 'end'],
	[{ edge: 'top', offset: 10 }, 'start'],
])('opens the list toward the room left on the edge: %o', async (position, half) => {
	await render({ side_tab_position: position });
	expect(tab().getAttribute('data-half')).toBe(half);
});

it('opens on a click, not on hover', async () => {
	await render({});
	const trigger = container.querySelector('.twp-trigger') as HTMLButtonElement;
	await act(async () => { trigger.dispatchEvent(new MouseEvent('mouseover', { bubbles: true })); });
	expect(onOpenChange).not.toHaveBeenCalled();
	await act(async () => { trigger.click(); });
	expect(onOpenChange).toHaveBeenCalledWith(true);
});

it('opens the same language list as the dropdown', async () => {
	await act(async () => { root.render(<SwitcherView {...props} isOpen switcher={{ type: 'side_tab' } as any} />); });
	expect(container.querySelectorAll('.twp-side-tab [role="option"]')).toHaveLength(2);
});

it('is a dropdown when placed in the page', async () => {
	await render({}, { variant: 'inline' });
	expect(container.querySelector('.twp-side-tab')).toBeNull();
	expect(container.querySelector('.twp-inline .twp-trigger')).not.toBeNull();
});

it('the settings preview draws the tab inside its box', async () => {
	await render({ side_tab_position: { edge: 'left', offset: 20 } }, { variant: 'preview' });
	expect(tab().classList.contains('twp-side-tab')).toBe(true);
	expect(tab().getAttribute('data-preview')).toBe('true');
	expect(tab().getAttribute('data-edge')).toBe('left');
});

it('keeps the list within the room beside a phone-width side tab', async () => {
	await render({});
	const css = container.querySelector('style')?.textContent ?? '';
	expect(css).toMatch(/max-width:\s*782px[\s\S]*\.twp-side-tab\[data-mobile-edge="(left|right)"\]\[data-mobile-half="(start|end)"\]\s*\{\s*--twp-list-room:\s*calc\(100vw - \d+px - 0\.25rem - 0\.5rem\)/);
	expect(css).toContain('min-width: min(12rem, var(--twp-list-room, 12rem)) !important');
});
