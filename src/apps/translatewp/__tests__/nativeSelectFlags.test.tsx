import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';

jest.mock('@/shared/api/admin', () => ({ updateAdminData: jest.fn(() => Promise.resolve({ success: true })) }));

import { SwitcherView } from '../SwitcherView';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;
const props = {
	languages: [{ locale: 'en', slug: 'en', active: true, default: true }, { locale: 'de', slug: 'de', active: true, default: false }],
	selectedLocale: 'de', isOpen: false, notice: null,
	onOpenChange: jest.fn(), onLanguageSelect: jest.fn(), onDismissNotice: jest.fn(),
};

const render = async (showFlag: boolean) => {
	await act(async () => { root.render(<SwitcherView {...props} switcher={{ type: 'native_select', show_flag: showFlag } as any} />); });
};

beforeEach(() => {
	window.TranslateWP = { canManage: false, context: {} } as any;
	container = document.createElement('div'); document.body.appendChild(container);
	root = createRoot(container);
});

afterEach(() => { act(() => root.unmount()); container.remove(); });

it('writes each native option as one piece of text, so turning the emoji flags on and off never breaks the list', async () => {
	await render(true);
	const options = () => Array.from(container.querySelectorAll('option'));

	expect(options().every(option => option.childNodes.length === 1)).toBe(true);
	expect(options()[1].textContent?.startsWith('🇩🇪 ')).toBe(true);

	await render(false);
	expect(options().every(option => option.childNodes.length === 1)).toBe(true);
	expect(options()[1].textContent?.startsWith('🇩🇪')).toBe(false);

	await render(true);
	expect(options().every(option => option.childNodes.length === 1)).toBe(true);
	expect(options()[1].textContent?.startsWith('🇩🇪 ')).toBe(true);
});
