import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import AdminFloatingSwitcher from '../AdminFloatingSwitcher';
import { SwitcherView } from '../SwitcherView';
import { saveSwitcherPosition } from '@/shared/api/wordpress';
import { editMode } from '../editMode';
import { dragHint } from '../dragHint';

jest.mock('../SwitcherView', () => {
	const actual = jest.requireActual('../SwitcherView');
	return { ...actual, SwitcherView: jest.fn(actual.SwitcherView) };
});

jest.mock('@/shared/api/wordpress', () => ({ saveSwitcherPosition: jest.fn() }));
(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;
const open = jest.fn();
const props = {
	languages: [{ locale: 'en', slug: 'en', active: true, default: true }],
	switcher: {}, selectedLocale: 'en', isOpen: false, notice: null,
	onOpenChange: open, onLanguageSelect: jest.fn(), onDismissNotice: jest.fn(),
};

const render = async (admin = true, overrides: Partial<typeof props> = {}) => {
	await act(async () => {
		root.render(<QueryClientProvider client={new QueryClient()}>
			{admin
				? <AdminFloatingSwitcher {...props} {...overrides} />
				: <SwitcherView {...props} {...overrides} />}
		</QueryClientProvider>);
	});
	const trigger = container.querySelector('.twp-trigger') as HTMLButtonElement;
	trigger.getBoundingClientRect = () => ({ left: 900, top: 20, width: 48, height: 48 } as DOMRect);
	trigger.setPointerCapture = jest.fn();
	trigger.releasePointerCapture = jest.fn();
	trigger.hasPointerCapture = () => true;
	return trigger;
};

const pointer = (element: HTMLElement, type: string, options: Record<string, number> = {}) => {
	const event = new MouseEvent(type, { bubbles: true, cancelable: true, clientX: 910,
		clientY: 30, button: 0, ...options });
	Object.defineProperty(event, 'pointerId', { value: options.pointerId ?? 1 });
	Object.defineProperty(event, 'isPrimary', { value: options.isPrimary !== 0 });
	act(() => element.dispatchEvent(event));
};

beforeEach(() => {
	jest.clearAllMocks();
	dragHint.reset();
	container = document.createElement('div'); document.body.appendChild(container);
	root = createRoot(container);
	Object.defineProperty(window, 'innerWidth', { value: 1000, configurable: true });
	Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true });
	// The page's own size, without a scrollbar; the switcher is placed against it.
	Object.defineProperty(document.documentElement, 'clientWidth', { value: 1000, configurable: true });
	Object.defineProperty(document.documentElement, 'clientHeight', { value: 800, configurable: true });
	window.TranslateWP = { canManage: true, context: { settings_revision: 7 } } as any;
	editMode.set(true);
	(saveSwitcherPosition as jest.Mock).mockResolvedValue({ success: true,
		position: { anchor: 'bottom-right', x: 52, y: 152 }, revision: 8 });
});

afterEach(() => { act(() => root.unmount()); container.remove(); });

it('outside edit mode a drag moves nothing and saves nothing', async () => {
	editMode.set(false);
	const button = await render();
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup', { clientY: 610 }));
	expect(saveSwitcherPosition).not.toHaveBeenCalled();
	expect(button.getAttribute('data-drag-handle')).not.toBe('true');
	expect(container.querySelector('.twp-container')?.getAttribute('data-anchor')).toBe('top-right');
});

it('keeps a short press and keyboard click as normal menu clicks without saving', async () => {
	const button = await render();
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientX: 915 });
	pointer(button, 'pointerup', { clientX: 915 });
	act(() => button.click());
	expect(open).toHaveBeenLastCalledWith(true);
	expect(saveSwitcherPosition).not.toHaveBeenCalled();
});

it('saves one anchored position preserving the grab offset and suppresses only the drag click', async () => {
	const button = await render();
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup', { clientY: 610 }));
	expect(saveSwitcherPosition).toHaveBeenCalledTimes(1);
	expect(saveSwitcherPosition).toHaveBeenCalledWith({
		position: { anchor: 'bottom-right', x: 52, y: 152 }, device: 'desktop', revision: 7,
	});
	open.mockClear();
	act(() => button.click()); expect(open).not.toHaveBeenCalled();
	act(() => button.click()); expect(open).toHaveBeenCalledWith(true);
	expect(container.querySelector('.twp-container')?.getAttribute('data-anchor')).toBe('bottom-right');
});

it('keeps the drawer open and quick-fades it with the notice while dragging', async () => {
	const button = await render(true, { isOpen: true });
	pointer(button, 'pointerdown');
	pointer(button, 'pointermove', { clientY: 610 });
	const floating = container.querySelector('.twp-container');
	expect(floating?.getAttribute('data-dragging')).toBe('true');
	expect(container.querySelector('.twp-dropdown')).not.toBeNull();
	expect(open).not.toHaveBeenCalledWith(false);
	const css = container.querySelector('style')?.textContent ?? '';
	expect(css).toMatch(/data-dragging="true"[^}]+opacity:\s*0\.6 !important/);
	expect(css).toMatch(/transition:\s*opacity 120ms ease-out !important/);
});

it.each(['pointercancel', 'lostpointercapture', 'escape', 'resize'])(
	'rolls back a drag without saving on %s', async reason => {
		const button = await render(); pointer(button, 'pointerdown');
		pointer(button, 'pointermove', { clientY: 610 });
		if (reason === 'escape') {
			act(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })));
		} else if (reason === 'resize') {
			Object.defineProperty(window, 'innerWidth', { value: 782 });
			act(() => window.dispatchEvent(new Event('resize')));
		} else pointer(button, reason);
		pointer(button, 'pointerup');
		expect(saveSwitcherPosition).not.toHaveBeenCalled();
		expect(container.querySelector('.twp-container')?.getAttribute('data-anchor')).toBe('top-right');
	}
);

it.each([782, 375])('drags on a %i pixel screen too and saves it as the phone position', async width => {
	Object.defineProperty(window, 'innerWidth', { value: width });
	const button = await render();
	expect(button.getAttribute('data-drag-handle')).toBe('true');
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup', { clientY: 610 }));
	expect(saveSwitcherPosition).toHaveBeenCalledTimes(1);
	expect((saveSwitcherPosition as jest.Mock).mock.calls[0][0]).toMatchObject({ device: 'mobile' });
	const floating = container.querySelector('.twp-container');
	// The phone position moved; the wide-screen one is as it was.
	expect(floating?.getAttribute('data-mobile-anchor')).toBe('bottom-right');
	expect(floating?.getAttribute('data-anchor')).toBe('top-right');
});

it('follows the pointer exactly on a page with a scrollbar', async () => {
	// A 15 pixel scrollbar: the window is wider than the page.
	Object.defineProperty(document.documentElement, 'clientWidth', { value: 985, configurable: true });
	const button = await render();
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup', { clientY: 610 }));
	// Measured from the page's right edge, not the window's: 15 pixels less than without a scrollbar.
	expect((saveSwitcherPosition as jest.Mock).mock.calls[0][0].position).toEqual({ anchor: 'bottom-right', x: 37, y: 152 });
});

it.each([{ button: 2 }, { isPrimary: 0 }])('ignores a nonprimary press %j', async options => {
	const button = await render(); pointer(button, 'pointerdown', options);
	pointer(button, 'pointermove', { clientY: 610 }); pointer(button, 'pointerup');
	expect(saveSwitcherPosition).not.toHaveBeenCalled();
});

it('does not let a second pointer finish a drag', async () => {
	const button = await render(); pointer(button, 'pointerdown');
	pointer(button, 'pointermove', { clientY: 610 });
	pointer(button, 'pointerup', { pointerId: 2 });
	expect(saveSwitcherPosition).not.toHaveBeenCalled();
});

it('never gives a visitor a drag handle or a save request', async () => {
	window.TranslateWP.canManage = false;
	const button = await render(true);
	expect((SwitcherView as jest.Mock).mock.calls.at(-1)?.[0].triggerBindings).toBeUndefined();
	pointer(button, 'pointerdown');
	pointer(button, 'pointermove', { clientY: 610 }); pointer(button, 'pointerup');
	expect(button.hasAttribute('data-drag-handle')).toBe(false);
	expect(saveSwitcherPosition).not.toHaveBeenCalled();
});

it('keeps the dropped position when the save fails and says so quietly', async () => {
	(saveSwitcherPosition as jest.Mock).mockRejectedValueOnce({ code: 'server_error' });
	const button = await render(); pointer(button, 'pointerdown');
	pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup'));
	expect(container.querySelector('.twp-container')?.getAttribute('data-anchor')).toBe('bottom-right');
	expect(container.querySelector('[role="status"]')?.textContent).toContain('stays here for now');
});

it('keeps the dropped position after a stale revision and the next drop still saves', async () => {
	(saveSwitcherPosition as jest.Mock).mockRejectedValueOnce({ code: 'settings_changed' });
	const button = await render(); pointer(button, 'pointerdown');
	pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup'));
	expect(container.querySelector('.twp-container')?.getAttribute('data-anchor')).toBe('bottom-right');
	expect(container.querySelector('[role="status"]')?.textContent).not.toContain('Reload');
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup'));
	expect(saveSwitcherPosition).toHaveBeenCalledTimes(2);
});

it('a drop during a slow save still saves, the last drop wins, and normal clicks keep working', async () => {
	(saveSwitcherPosition as jest.Mock).mockImplementationOnce(() => new Promise(() => {}));
	const button = await render(); pointer(button, 'pointerdown');
	pointer(button, 'pointermove', { clientY: 610 }); pointer(button, 'pointerup');
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup'));
	expect(saveSwitcherPosition).toHaveBeenCalledTimes(2);
	act(() => button.click()); act(() => button.click());
	expect(open).toHaveBeenLastCalledWith(true);
});

it('grabbing the reposition badge drags the switcher like the trigger', async () => {
	const button = await render();
	// The badge appears once the drag hint was dismissed.
	const close = container.querySelector('.twp-toast-close') as HTMLButtonElement;
	await act(async () => { close.click(); });
	const badge = container.querySelector('[data-twp-drag-dot]') as HTMLElement;
	expect(badge).not.toBeNull();
	badge.setPointerCapture = jest.fn(); badge.releasePointerCapture = jest.fn(); badge.hasPointerCapture = () => true;
	pointer(badge, 'pointerdown');
	pointer(badge, 'pointermove', { clientY: 610 });
	await act(async () => pointer(badge, 'pointerup'));
	expect(saveSwitcherPosition).toHaveBeenCalledTimes(1);
	expect(container.querySelector('.twp-container')?.getAttribute('data-anchor')).toBe('bottom-right');
	expect(button).toBeDefined();
});

it('shows the drag hint once: closed, it stays closed when the page is opened again', async () => {
	await render();
	expect(container.querySelector('.twp-toast')?.textContent).toContain('Drag the switcher');
	await act(async () => { (container.querySelector('.twp-toast-close') as HTMLButtonElement).click(); });
	act(() => root.unmount());
	root = createRoot(container);
	await render();
	expect(container.querySelector('.twp-toast')).toBeNull();
	expect(window.localStorage.getItem('translatewp-drag-hint-done')).toBe('1');
});

it('does not show the drag hint again to someone who has dragged the switcher', async () => {
	const button = await render();
	pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientY: 610 });
	await act(async () => pointer(button, 'pointerup', { clientY: 610 }));
	expect(container.querySelector('.twp-toast')).toBeNull();
	expect(container.querySelector('[data-twp-drag-dot]')).not.toBeNull();
});

describe('side tab', () => {
	const sideTab = { switcher: { type: 'side_tab' } as any };

	it('a drag snaps the tab to the nearest edge, previews it there and saves the edge for the screen width', async () => {
		(saveSwitcherPosition as jest.Mock).mockResolvedValue({ success: true, position: { edge: 'left', offset: 50 }, revision: 8 });
		const button = await render(true, sideTab);
		// The 48px tab grabbed at (910, 30) and let go with its box at (0, 376): nearest the left edge, halfway down.
		pointer(button, 'pointerdown'); pointer(button, 'pointermove', { clientX: 10, clientY: 386 });
		expect(container.querySelector('.twp-container')?.getAttribute('data-edge')).toBe('left');
		await act(async () => pointer(button, 'pointerup', { clientX: 10, clientY: 386 }));
		expect(saveSwitcherPosition).toHaveBeenCalledWith({ kind: 'side_tab', position: { edge: 'left', offset: 50 }, device: 'desktop', revision: 7 });
		expect(container.querySelector('.twp-container')?.getAttribute('data-edge')).toBe('left');
		expect(container.querySelector('.twp-container')?.hasAttribute('data-anchor')).toBe(false);
	});

	it('on a phone-width screen a drop near the top never snaps to the top edge', async () => {
		Object.defineProperty(window, 'innerWidth', { value: 400, configurable: true });
		Object.defineProperty(document.documentElement, 'clientWidth', { value: 400, configurable: true });
		const button = await render(true, sideTab);
		button.getBoundingClientRect = () => ({ left: 300, top: 20, width: 48, height: 48 } as DOMRect);
		pointer(button, 'pointerdown', { clientX: 310 }); pointer(button, 'pointermove', { clientX: 330, clientY: 20 });
		await act(async () => pointer(button, 'pointerup', { clientX: 330, clientY: 20 }));
		const call = (saveSwitcherPosition as jest.Mock).mock.calls[0][0];
		expect(call.kind).toBe('side_tab');
		expect(call.device).toBe('mobile');
		expect(call.position.edge).not.toBe('top');
	});
});
