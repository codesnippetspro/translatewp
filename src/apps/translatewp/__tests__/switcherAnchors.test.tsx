import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { SwitcherView } from '../SwitcherView';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;
let css: string;
beforeAll(() => {
	const container = document.createElement('div');
	const root = createRoot(container);
	act(() => root.render(<SwitcherView
		languages={[]} switcher={{}} selectedLocale="en" isOpen={false} notice={null}
		onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
	/>));
	css = container.querySelector('style')!.textContent!;
	act(() => root.unmount());
});
const rule = (selector: string) => {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	return css.match(new RegExp(`^\\s*${escaped}\\s*\\{([^}]+)}`, 'm'))?.[1];
};

it('aligns the left-anchor menu and notice inward', () => {
	expect(rule('.twp-container[data-anchor$="left"] .twp-popover-stack'))
		.toMatch(/left:\s*0 !important;\s*right:\s*auto !important;\s*align-items:\s*flex-start/);
	expect(rule('.twp-container[data-anchor$="left"] .twp-toast'))
		.toMatch(/left:\s*100% !important;\s*right:\s*auto !important/);
});

it('aligns the default right-anchor menu and notice inward', () => {
	expect(rule('.twp-popover-stack')).toMatch(/right:\s*0 !important/);
	expect(rule('.twp-popover-stack')).toMatch(/align-items:\s*flex-end !important/);
	expect(rule('.twp-toast')).toMatch(/right:\s*100% !important/);
});

it('opens the bottom-anchor menu upward', () => {
	expect(rule('.twp-container[data-anchor^="bottom"] .twp-popover-stack'))
		.toMatch(/top:\s*auto !important;\s*bottom:\s*100% !important/);
});
