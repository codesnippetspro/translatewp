import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';

jest.mock('@/shared/api/admin', () => ({
	updateAdminData: jest.fn(() => Promise.resolve({ success: true })),
	retranslatePage: jest.fn(() => Promise.resolve({ requeued: 3 })),
}));

import { SwitcherView } from '../SwitcherView';
import { editMode } from '../editMode';
import { retranslatePage } from '@/shared/api/admin';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;
const props = {
	languages: [{ locale: 'en', slug: 'en', active: true, default: true }, { locale: 'de', slug: 'de', active: true, default: false }],
	switcher: {}, selectedLocale: 'de', isOpen: true, notice: null,
	onOpenChange: jest.fn(), onLanguageSelect: jest.fn(), onDismissNotice: jest.fn(),
};
const pageObject = { type: 'page', id: 12, url: 'https://example.test/about/' };

const as = async (can: Record<string, boolean> | undefined, extra: Record<string, unknown> = {}) => {
	window.TranslateWP = {
		...(can ? { can } : { canManage: true }),
		settingsUrl: can?.settings ? 'https://example.test/wp-admin/admin.php?page=translatewp-settings#switcher' : undefined,
		context: { orbital: true, page_object: pageObject, pro: { entitled: true, installed: false, active: false } },
		...extra,
	} as any;
	editMode.set(true);
	await act(async () => { root.render(<SwitcherView {...props} />); });
};
const rows = () => Array.from(container.querySelectorAll('.twp-admin-item')).map(item => item.textContent?.replace('Pro', '').trim());

beforeEach(() => {
	container = document.createElement('div'); document.body.appendChild(container);
	root = createRoot(container);
	(retranslatePage as jest.Mock).mockClear();
});

afterEach(() => { act(() => root.unmount()); container.remove(); editMode.set(false); });

it('Edit translations only: the editing rows, nothing for settings or retranslation', async () => {
	await as({ edit: true, retranslate: false, settings: false, lexicon: false });
	expect(rows()).toEqual(['Exit edit mode', 'Edit translations', 'Add to Lexicon']);
});

it('Manage settings only: placement, the settings link and Support TranslateWP', async () => {
	await as({ edit: false, retranslate: false, settings: true, lexicon: false });
	expect(rows()).toEqual(['Exit edit mode', 'Switcher placement', 'Switcher settings', 'Support TranslateWP']);
});

it('Retranslate only: the one row, which asks once more and then queues this page in every language', async () => {
	await as({ edit: false, retranslate: true, settings: false, lexicon: false });
	expect(rows()).toEqual(['Exit edit mode', 'Retranslate this page']);
	const row = container.querySelector('[data-twp-retranslate-page]') as HTMLButtonElement;
	await act(async () => { row.click(); });
	expect(retranslatePage).not.toHaveBeenCalled();
	expect(row.textContent).toContain('Click again');
	await act(async () => { row.click(); });
	expect(retranslatePage).toHaveBeenCalledWith({ objectType: 'page', objectId: 12, url: 'https://example.test/about/' });
	expect(container.querySelector('[data-twp-retranslate-page]')?.textContent).toContain('3');
});

it('Retranslate is not offered on a page that is not a single post or page', async () => {
	await as({ edit: false, retranslate: true, settings: false, lexicon: false }, { context: { orbital: true, page_object: null } });
	expect(rows()).toEqual(['Exit edit mode']);
});

it('Manage the Lexicon only, or nothing: no edit mode at all', async () => {
	await as({ edit: false, retranslate: false, settings: false, lexicon: true });
	expect(container.querySelector('[data-twp-edit-mode]')).toBeNull();
});

it('an older server without the flags: canManage keeps every row', async () => {
	await as(undefined);
	expect(rows()).toEqual(expect.arrayContaining(['Edit translations', 'Switcher placement', 'Add to Lexicon', 'Support TranslateWP']));
});
