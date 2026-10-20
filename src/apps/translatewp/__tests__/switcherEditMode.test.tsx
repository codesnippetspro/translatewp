import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';
import { addFilter, removeFilter } from '@wordpress/hooks';

jest.mock('@/shared/api/admin', () => ({ updateAdminData: jest.fn(() => Promise.resolve({ success: true })) }));

import { SwitcherView } from '../SwitcherView';
import { editMode } from '../editMode';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;
const props = {
	languages: [{ locale: 'en', slug: 'en', active: true, default: true }, { locale: 'de', slug: 'de', active: true, default: false }],
	switcher: {}, selectedLocale: 'de', isOpen: true, notice: null,
	onOpenChange: jest.fn(), onLanguageSelect: jest.fn(), onDismissNotice: jest.fn(),
};

const render = async () => {
	await act(async () => { root.render(<SwitcherView {...props} />); });
};

beforeEach(() => {
	container = document.createElement('div'); document.body.appendChild(container);
	root = createRoot(container);
	editMode.set(false);
	removeFilter('translatewp.frontend.slot.switcherAdminMenu', 'test');
});

afterEach(() => { act(() => root.unmount()); container.remove(); });

it('a visitor sees no edit mode', async () => {
	window.TranslateWP = { canManage: false, context: {} } as any;
	await render();
	expect(container.querySelector('[data-twp-edit-mode]')).toBeNull();
});

it('an unentitled administrator gets three clickable Pro actions without a separate unlock link', async () => {
	window.TranslateWP = { canManage: true, context: { pro: { entitled: false, upgrade_url: 'https://example.test/upgrade' } } } as any;
	await render();
	const toggle = container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement;
	expect(toggle).not.toBeNull();
	expect(container.querySelectorAll('[data-twp-locked]')).toHaveLength(0);

	await act(async () => { toggle.click(); });
	expect(editMode.get()).toBe(true);
	const lockedActions = Array.from(container.querySelectorAll<HTMLAnchorElement>('[data-twp-locked]'));
	expect(lockedActions).toHaveLength(3);
	expect(lockedActions.every(action => action.href === 'https://example.test/upgrade')).toBe(true);
	expect(lockedActions.every(action => action.target === '_blank')).toBe(true);
	expect(lockedActions.every(action => !action.hasAttribute('disabled'))).toBe(true);
	expect(container.querySelector('[data-twp-upgrade]')).toBeNull();
	expect(container.textContent).not.toContain('Unlock with Pro');
});

it('edit mode has a link to the plugin settings in wp-admin, for a manager only, just above Support TranslateWP', async () => {
	window.TranslateWP = { canManage: true, settingsUrl: 'https://example.test/wp-admin/admin.php?page=translatewp-settings#switcher', context: { pro: { entitled: false, upgrade_url: 'https://example.test/upgrade' } } } as any;
	await render();
	expect(container.querySelector('[data-twp-settings-link]')).toBeNull();

	await act(async () => { (container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement).click(); });
	const items = Array.from(container.querySelectorAll('.twp-admin-item'));
	const link = container.querySelector('a[data-twp-settings-link]') as HTMLAnchorElement;
	expect(link.href).toBe('https://example.test/wp-admin/admin.php?page=translatewp-settings#switcher');
	expect(items[items.length - 2]).toBe(link);
	expect(items[items.length - 1].hasAttribute('data-twp-support-branding')).toBe(true);
});

it('uses the same icon column for language flags and administrator actions', async () => {
	window.TranslateWP = { canManage: true, context: {} } as any;
	await render();
	expect(container.querySelector('.twp-dropdown-item .twp-item-icon')).not.toBeNull();
	expect(container.querySelector('[data-twp-edit-mode] .twp-item-icon')).not.toBeNull();
	const css = container.querySelector('style')?.textContent ?? '';
	const languageItemRule = css.match(/\.twp-dropdown-item\s*\{([^}]*)\}/)?.[1] ?? '';
	const adminItemRule = css.match(/\.twp-admin-item\s*\{([^}]*)\}/)?.[1] ?? '';
	const proTagRule = css.match(/\.twp-pro-tag\s*\{([^}]*)\}/)?.[1] ?? '';
	expect(adminItemRule).toMatch(/white-space:\s*nowrap !important/);
	for (const rule of [languageItemRule, adminItemRule]) {
		expect(rule).toMatch(/padding:\s*0\.625rem 0\.75rem !important/);
		expect(rule).toMatch(/font-size:\s*0\.875rem !important/);
		expect(rule).toMatch(/line-height:\s*1\.25rem !important/);
	}
	expect(proTagRule).toMatch(/line-height:\s*1rem !important/);
	await act(async () => { (container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement).click(); });
	expect(container.querySelector('.twp-dropdown')?.getAttribute('data-editing')).toBe('true');
	const editingRule = css.match(/\.twp-dropdown\[data-editing="true"\]\s*\{([^}]*)\}/)?.[1] ?? '';
	expect(editingRule).toContain('min-width: min(15rem, var(--twp-list-room, 15rem)) !important');
});

it('uses the notice surface to explain dragging while edit mode can reposition the switcher', async () => {
	window.TranslateWP = { canManage: true, context: {} } as any;
	editMode.set(true);
	await act(async () => {
		root.render(<SwitcherView {...props} triggerBindings={{ 'data-drag-handle': true }} />);
	});
	expect(container.querySelector('.twp-toast')?.textContent)
		.toContain('Drag the switcher to move it. It stays where you let go, on every page.');
	const close = container.querySelector('.twp-toast-close') as HTMLButtonElement;
	expect(close).not.toBeNull();
	expect(container.querySelector('[data-twp-drag-dot]')).toBeNull();
	await act(async () => { close.click(); });
	expect(container.querySelector('.twp-toast')).toBeNull();
	expect(container.querySelector('[data-twp-drag-dot]')).not.toBeNull();
});

it('keeps an active translation or error notice ahead of the drag instruction', async () => {
	window.TranslateWP = { canManage: true, context: {} } as any;
	editMode.set(true);
	await act(async () => {
		root.render(<SwitcherView
			{...props}
			notice="The switcher position could not be saved."
			triggerBindings={{ 'data-drag-handle': true }}
		/>);
	});
	expect(container.querySelector('.twp-toast')?.textContent).toContain('could not be saved');
	expect(container.querySelector('.twp-toast-close')).not.toBeNull();
});

it('an entitled administrator with the Pro menu registered gets the Pro menu instead of the locks', async () => {
	window.TranslateWP = { canManage: true, context: { pro: { entitled: true, upgrade_url: 'https://example.test/upgrade' } } } as any;
	addFilter('translatewp.frontend.slot.switcherAdminMenu', 'test', () => () => <div data-twp-pro-menu />);
	await render();
	await act(async () => { (container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement).click(); });
	expect(container.querySelector('[data-twp-pro-menu]')).not.toBeNull();
	expect(container.querySelectorAll('[data-twp-locked]')).toHaveLength(0);
});

const entitledContext = (installed: boolean, active: boolean) => ({
	canManage: true,
	context: { pro: { entitled: true, installed, active, upgrade_url: 'https://example.test/upgrade', install_url: 'https://example.test/wp-admin/admin.php?page=translatewp' } },
});

it('an entitled account without the addon is told the purchase is done and offered Install now, never the upgrade', async () => {
	window.TranslateWP = entitledContext(false, false) as any;
	await render();
	await act(async () => { (container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement).click(); });
	const rows = Array.from(container.querySelectorAll<HTMLElement>('[data-twp-locked]'));
	expect(rows).toHaveLength(3);
	expect(rows.every(row => row.tagName === 'BUTTON')).toBe(true);
	expect(rows.every(row => (row.getAttribute('title') ?? '') !== '')).toBe(true);
	expect(container.querySelector('a[href="https://example.test/upgrade"]')).toBeNull();
	expect(container.querySelector('[data-twp-install-prompt]')).toBeNull();

	await act(async () => { rows[0].click(); });
	const prompt = container.querySelector('[data-twp-install-prompt]');
	expect(prompt?.textContent).toContain('Your account includes Pro. Install it on this site to use Edit translations.');
	const link = prompt?.querySelector<HTMLAnchorElement>('a[href="https://example.test/wp-admin/admin.php?page=translatewp"]');
	expect(link?.textContent).toBe('Install now');
	expect(link?.getAttribute('target')).toBeNull();
	expect(container.querySelector('a[href="https://example.test/upgrade"]')).toBeNull();
	expect(container.textContent).not.toMatch(/upgrade|buy/i);
});

it('an entitled site with the addon inactive is offered Activate now', async () => {
	window.TranslateWP = entitledContext(true, false) as any;
	await render();
	await act(async () => { (container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement).click(); });
	await act(async () => { (container.querySelectorAll<HTMLElement>('[data-twp-locked]')[1]).click(); });
	const prompt = container.querySelector('[data-twp-install-prompt]');
	expect(prompt?.textContent).toContain('Your account includes Pro. Activate it on this site to use Switcher placement.');
	expect(prompt?.querySelector('a[href="https://example.test/wp-admin/admin.php?page=translatewp"]')?.textContent).toBe('Activate now');
	expect(container.querySelector('a[href="https://example.test/upgrade"]')).toBeNull();
});

it('an unentitled account with the addon registered still sees the locks, never the pro menu', async () => {
	window.TranslateWP = { canManage: true, context: { pro: { entitled: false, upgrade_url: 'https://example.test/upgrade' } } } as any;
	addFilter('translatewp.frontend.slot.switcherAdminMenu', 'test', () => () => <div data-twp-pro-menu />);
	await render();
	await act(async () => { (container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement).click(); });
	expect(container.querySelector('[data-twp-pro-menu]')).toBeNull();
	expect(container.querySelectorAll('[data-twp-locked]')).toHaveLength(3);
});

describe('native select', () => {
	const nativeProps = { ...props, switcher: { type: 'native_select' as const }, isOpen: false };

	it('a manager on native select can enter edit mode', async () => {
		window.TranslateWP = { canManage: true, context: { pro: { entitled: false, upgrade_url: 'https://example.test/upgrade' } } } as any;
		let open = false;
		const renderNative = async () => {
			await act(async () => {
				root.render(<SwitcherView {...nativeProps} isOpen={open} onOpenChange={(next: boolean) => { open = next; }} />);
			});
		};
		await renderNative();
		const select = container.querySelector('select.twp-native-select');
		expect(select).not.toBeNull();
		const menuButton = select?.nextElementSibling as HTMLButtonElement | null;
		expect(menuButton?.matches('button[data-twp-admin-menu]')).toBe(true);
		expect(menuButton?.getAttribute('aria-label')).toBeTruthy();

		await act(async () => { menuButton!.click(); });
		await renderNative();
		const toggle = container.querySelector('[data-twp-edit-mode]') as HTMLButtonElement;
		expect(toggle).not.toBeNull();
		expect(container.querySelector('.twp-dropdown-item')).toBeNull();
		await act(async () => { toggle.click(); });
		expect(editMode.get()).toBe(true);
		expect(container.querySelectorAll('[data-twp-locked]')).toHaveLength(3);
	});

	it('a visitor on native select gets no admin button', async () => {
		window.TranslateWP = { canManage: false, context: {} } as any;
		await act(async () => { root.render(<SwitcherView {...nativeProps} isOpen />); });
		expect(container.querySelector('[data-twp-admin-menu]')).toBeNull();
		expect(container.querySelector('[data-twp-edit-mode]')).toBeNull();
		expect(container.querySelector('.twp-dropdown')).toBeNull();
	});

	it('native select carries a label and hardened visibility', async () => {
		window.TranslateWP = { canManage: false, context: {} } as any;
		await act(async () => { root.render(<SwitcherView {...nativeProps} />); });
		const select = container.querySelector('select.twp-native-select')!;
		expect(select.getAttribute('aria-label')).toBe('Language');
		const css = container.querySelector('style')?.textContent ?? '';
		const rule = css.match(/\.twp-native-select\s*\{([^}]*)\}/)?.[1] ?? '';
		expect(rule).toMatch(/display:\s*inline-block !important/);
		expect(rule).toMatch(/visibility:\s*visible !important/);
		expect(rule).toMatch(/opacity:\s*1 !important/);
		expect(rule).toMatch(/appearance:\s*none !important/);
		expect(rule).toMatch(/background-image: url\("data:image\/svg\+xml/);
		expect(rule).toMatch(/width:\s*auto !important/);
		expect(rule).toMatch(/position:\s*static !important/);
	});
});

it('a Terrestrial site renders Support TranslateWP as a toggle switched on, disabled and non-mutating, keeping the blue row hover and the Terrestrial tooltip', async () => {
	const { updateAdminData } = jest.requireMock('@/shared/api/admin') as { updateAdminData: jest.Mock };
	updateAdminData.mockClear();
	window.TranslateWP = { canManage: true, context: {} } as any;
	editMode.set(true);
	await act(async () => {
		root.render(<SwitcherView {...props} isOpen switcher={{ ...props.switcher, hide_branding: true }} />);
	});
	expect(container.querySelector('.twp-promo')).not.toBeNull();
	const row = container.querySelector('[data-twp-support-branding]') as HTMLButtonElement;
	expect(row).not.toBeNull();
	expect(row.textContent).toContain('Support TranslateWP');
	expect(row.textContent).not.toContain('Hide branding');
	// An actual toggle, switched on.
	expect(row.getAttribute('role')).toBe('switch');
	const track = row.querySelector('.twp-admin-switch');
	expect(track).not.toBeNull();
	expect(track?.getAttribute('data-on')).toBe('true');
	expect(row.getAttribute('aria-checked')).toBe('true');
	// Disabled semantics without the disabled attribute, so the row wrapper keeps hover and tooltip.
	expect(row.getAttribute('aria-disabled')).toBe('true');
	expect(row.disabled).toBe(false);
	expect(row.getAttribute('title')).toBe('Always on with Terrestrial. Pro lets you turn it off.');
	expect(row.classList.contains('twp-admin-item')).toBe(true);
	expect(row.classList.contains('twp-admin-locked')).toBe(false);
	const css = container.querySelector('style')?.textContent ?? '';
	const hoverRule = css.match(/\.twp-admin-item:hover, \.twp-admin-item:focus-visible[^{]*\{([^}]*)\}/)?.[1] ?? '';
	expect(hoverRule).toMatch(/background-color/);
	// The switch track carries its own outline and gets a legible treatment on the
	// highlighted row, so it reads as a toggle in both the resting and hovered state.
	const trackRule = css.match(/\.twp-admin-switch \{([^}]*)\}/)?.[1] ?? '';
	expect(trackRule).toMatch(/border:/);
	const switchHoverRule = css.match(/\.twp-admin-item:hover \.twp-admin-switch[^{]*\{([^}]*)\}/)?.[1] ?? '';
	expect(switchHoverRule).toMatch(/rgba\(255, 255, 255/);
	expect(css).toMatch(/\.twp-admin-item:focus-visible/);
	// Clicking and keyboard activation do not mutate or call save.
	await act(async () => { row.click(); });
	expect(updateAdminData).not.toHaveBeenCalled();
	expect(row.getAttribute('aria-checked')).toBe('true');
	await act(async () => {
		row.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
		row.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
	});
	expect(updateAdminData).not.toHaveBeenCalled();
	expect(row.getAttribute('aria-checked')).toBe('true');
});

it('an Orbital site has Support TranslateWP off by default as the last row, below Switcher settings; turning it on shows the line and saves', async () => {
	const { updateAdminData } = jest.requireMock('@/shared/api/admin') as { updateAdminData: jest.Mock };
	updateAdminData.mockResolvedValue({ success: true });
	window.TranslateWP = { canManage: true, context: { orbital: true }, settingsUrl: '/wp-admin/admin.php?page=translatewp-settings#switcher' } as any;
	editMode.set(true);
	await act(async () => {
		root.render(<SwitcherView {...props} isOpen />);
	});
	expect(container.querySelector('.twp-promo')).toBeNull();
	const row = container.querySelector('[data-twp-support-branding]') as HTMLButtonElement;
	expect(row.getAttribute('aria-checked')).toBe('false');
	const items = Array.from(container.querySelectorAll('.twp-admin-item'));
	expect(items[items.length - 1]).toBe(row);
	expect(items[items.length - 2].hasAttribute('data-twp-settings-link')).toBe(true);
	await act(async () => { row.click(); });
	expect(updateAdminData).toHaveBeenCalledWith('languages.switcher', { hide_branding: false });
	expect(container.querySelector('.twp-promo')).not.toBeNull();
	expect(row.getAttribute('aria-checked')).toBe('true');
});

it('an eligible site gets an enabled toggle that reflects a saved support-on value and toggles it off through the save path', async () => {
	const { updateAdminData } = jest.requireMock('@/shared/api/admin') as { updateAdminData: jest.Mock };
	updateAdminData.mockClear();
	updateAdminData.mockResolvedValue({ success: true });
	window.TranslateWP = { canManage: true, context: { pro: { entitled: true } } } as any;
	editMode.set(true);
	await act(async () => {
		root.render(<SwitcherView {...props} isOpen switcher={{ ...props.switcher, hide_branding: false }} />);
	});
	const row = container.querySelector('[data-twp-support-branding]') as HTMLButtonElement;
	expect(row.disabled).toBe(false);
	expect(row.getAttribute('aria-disabled')).toBeNull();
	expect(row.getAttribute('aria-checked')).toBe('true');
	expect(row.querySelector('.twp-admin-switch')?.getAttribute('data-on')).toBe('true');
	expect(row.querySelector('.twp-admin-switch')?.getAttribute('data-locked')).toBeNull();
	await act(async () => { row.click(); });
	expect(updateAdminData).toHaveBeenCalledWith('languages.switcher', { hide_branding: true });
	expect(row.getAttribute('aria-checked')).toBe('false');
});

describe('Switcher placement while Side tab is the selected type', () => {
	const lockedLabels = () => Array.from(container.querySelectorAll('[data-twp-locked]')).map(item => item.textContent?.replace('Pro', '').trim());
	const proMenu = () => {
		addFilter('translatewp.frontend.slot.switcherAdminMenu', 'test', () =>
			({ switcherType }: { switcherType?: string }) => <div data-twp-pro-menu data-switcher-type={switcherType ?? 'none'} />);
	};
	const show = async (switcher: Record<string, unknown>, variant?: 'inline') => {
		editMode.set(true);
		await act(async () => { root.render(<SwitcherView {...props} switcher={switcher} variant={variant} />); });
	};

	it('passes the selected Side tab to the Pro menu while Pro serves it', async () => {
		window.TranslateWP = { canManage: true, context: { pro: { entitled: true } } } as any;
		proMenu();
		await show({ type: 'side_tab', selected_type: 'side_tab' });
		expect(container.querySelector('[data-twp-pro-menu]')?.getAttribute('data-switcher-type')).toBe('side_tab');
		expect(container.querySelector('.twp-side-tab')).not.toBeNull();
	});

	it('keeps the selected Side tab for the Pro menu in a switcher placed in the page, which stays a dropdown', async () => {
		window.TranslateWP = { canManage: true, context: { pro: { entitled: true } } } as any;
		proMenu();
		await show({ type: 'side_tab', selected_type: 'side_tab' }, 'inline');
		expect(container.querySelector('.twp-side-tab')).toBeNull();
		expect(container.querySelector('.twp-dropdown')).not.toBeNull();
		expect(container.querySelector('[data-twp-pro-menu]')?.getAttribute('data-switcher-type')).toBe('side_tab');
	});

	it('hides the locked placement row when Side tab is still selected but the plan no longer serves it', async () => {
		window.TranslateWP = { canManage: true, context: { pro: { entitled: false, upgrade_url: 'https://example.test/upgrade' } } } as any;
		await show({ type: 'dropdown', selected_type: 'side_tab' });
		expect(container.querySelector('.twp-side-tab')).toBeNull();
		expect(lockedLabels()).toEqual(['Edit translations', 'Add to Lexicon']);
	});

	it('hides the placement row with Pro installed but inactive while Side tab is selected', async () => {
		window.TranslateWP = { ...entitledContext(true, false) } as any;
		await show({ type: 'dropdown', selected_type: 'side_tab' });
		expect(lockedLabels()).toEqual(['Edit translations', 'Add to Lexicon']);
	});

	it('keeps the placement row for the dropdown and the native dropdown', async () => {
		window.TranslateWP = { canManage: true, context: { pro: { entitled: false, upgrade_url: 'https://example.test/upgrade' } } } as any;
		await show({ type: 'dropdown', selected_type: 'dropdown' });
		expect(lockedLabels()).toEqual(['Edit translations', 'Switcher placement', 'Add to Lexicon']);

		await show({ type: 'native_select', selected_type: 'native_select' });
		expect(lockedLabels()).toEqual(['Edit translations', 'Switcher placement', 'Add to Lexicon']);

		window.TranslateWP = { canManage: true, context: { pro: { entitled: true } } } as any;
		proMenu();
		await show({ type: 'dropdown', selected_type: 'dropdown' });
		expect(container.querySelector('[data-twp-pro-menu]')?.getAttribute('data-switcher-type')).toBe('dropdown');
	});

	it('reads the type alone from a site that sends no selected type', async () => {
		window.TranslateWP = { canManage: true, context: { pro: { entitled: true } } } as any;
		proMenu();
		await show({ type: 'side_tab' });
		expect(container.querySelector('[data-twp-pro-menu]')?.getAttribute('data-switcher-type')).toBe('side_tab');
	});

	it('still lets the floating switcher be dragged in edit mode while Side tab is selected but not served', async () => {
		window.TranslateWP = { canManage: true, context: {} } as any;
		editMode.set(true);
		await act(async () => {
			root.render(<SwitcherView {...props} switcher={{ type: 'dropdown', selected_type: 'side_tab' }} isOpen={false} triggerBindings={{ 'data-drag-handle': true }} />);
		});
		// The drag line, or once it was dismissed its round handle.
		const dragHint = container.querySelector('.twp-toast')?.textContent?.includes('Drag the switcher to move it.') || container.querySelector('[data-twp-drag-dot]') !== null;
		expect(dragHint).toBe(true);
	});
});
