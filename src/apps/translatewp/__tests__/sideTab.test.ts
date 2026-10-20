import { resolveSideTabPosition, sideTabCss, sideTabFromDrop, SIDE_TAB_DEFAULT } from '../sideTab';

const drop = (left: number, top: number, extra: Partial<Parameters<typeof sideTabFromDrop>[0]> = {}) => sideTabFromDrop({
	left, top, width: 100, height: 40, viewportWidth: 1000, viewportHeight: 800, adminBarHeight: 0, device: 'desktop', ...extra,
});

describe('side tab drop geometry', () => {
	it.each([
		// left, top: the tab's box where it was let go; its centre picks the nearest edge
		[450, 5, 'top', 50],
		[450, 755, 'bottom', 50],
		[0, 380, 'left', 50],
		[900, 380, 'right', 50],
		[0, 760, 'bottom', 0],
		[900, 760, 'bottom', 100],
		[905, 0, 'top', 100],
		[-80, -40, 'top', 0],
		[2000, 2000, 'bottom', 100],
	])('snaps a drop at %i, %i to the %s edge at %i', (left, top, edge, offset) => {
		expect(drop(left as number, top as number)).toEqual({ edge, offset });
	});

	it('measures a side edge between the administrator bar and the bottom', () => {
		expect(drop(0, 132, { adminBarHeight: 32 })).toEqual({ edge: 'left', offset: 13.7 });
		expect(drop(0, 32, { adminBarHeight: 32 })).toEqual({ edge: 'top', offset: 0 });
		expect(drop(0, 760, { adminBarHeight: 32, height: 40 })).toEqual({ edge: 'bottom', offset: 0 });
		expect(drop(0, 416 - 20, { adminBarHeight: 32 })).toEqual({ edge: 'left', offset: 50 });
	});

	it('keeps the share to a tenth of a percent, so the tab follows the pointer without steps', () => {
		// 300 of the 900 pixels the tab can travel is 33.333…%.
		expect(drop(300, 760)).toEqual({ edge: 'bottom', offset: 33.3 });
		expect(drop(301, 760)).toEqual({ edge: 'bottom', offset: 33.4 });
	});

	it('never snaps to the top edge on a phone-width screen', () => {
		expect(drop(140, 0, { viewportWidth: 400, viewportHeight: 800, device: 'mobile' })).toEqual({ edge: 'left', offset: 0 });
		expect(drop(160, 0, { viewportWidth: 400, viewportHeight: 800, device: 'mobile' })).toEqual({ edge: 'right', offset: 0 });
		expect(drop(150, 760, { viewportWidth: 400, viewportHeight: 800, device: 'mobile' })).toEqual({ edge: 'bottom', offset: 50 });
	});
});

describe('stored side tab places', () => {
	it('defaults to the bottom edge near the right on both widths', () => {
		expect(SIDE_TAB_DEFAULT).toEqual({ edge: 'bottom', offset: 85 });
		expect(resolveSideTabPosition({}, 'desktop')).toEqual(SIDE_TAB_DEFAULT);
		expect(resolveSideTabPosition({}, 'mobile')).toEqual(SIDE_TAB_DEFAULT);
	});

	it('reads each width on its own and keeps a phone off the top edge', () => {
		const switcher = { side_tab_position: { edge: 'top', offset: 20 }, side_tab_position_mobile: { edge: 'top', offset: 30 } } as const;
		expect(resolveSideTabPosition(switcher, 'desktop')).toEqual({ edge: 'top', offset: 20 });
		expect(resolveSideTabPosition(switcher, 'mobile')).toEqual({ edge: 'bottom', offset: 30 });
	});

	it('falls back to the default for anything malformed and clamps the share', () => {
		expect(resolveSideTabPosition({ side_tab_position: { edge: 'middle', offset: 5 } } as never, 'desktop')).toEqual(SIDE_TAB_DEFAULT);
		expect(resolveSideTabPosition({ side_tab_position: { edge: 'left', offset: 400 } }, 'desktop')).toEqual({ edge: 'left', offset: 100 });
	});
});

describe('side tab drag handle', () => {
	it.each([
		['bottom', 'top: -11px !important; right: auto !important; bottom: auto !important; left: calc(50% - 11px) !important;'],
		['top', 'top: auto !important; right: auto !important; bottom: -11px !important; left: calc(50% - 11px) !important;'],
		['left', 'top: calc(50% - 11px) !important; right: -11px !important; bottom: auto !important; left: auto !important;'],
		['right', 'top: calc(50% - 11px) !important; right: auto !important; bottom: auto !important; left: -11px !important;'],
	])('sits in the middle of the tab side that faces the page on the %s edge', (edge, box) => {
		expect(sideTabCss(120)).toContain(`.twp-container.twp-side-tab[data-edge="${edge}"] .twp-drag-dot { ${box} }`);
	});
});

describe('side tab docked edge', () => {
	it.each([
		['bottom', '0.5rem 0.5rem 0 0', 'border-bottom-width'],
		['top', '0 0 0.5rem 0.5rem', 'border-top-width'],
		['left', '0 0.5rem 0.5rem 0', 'border-left-width'],
		['right', '0.5rem 0 0 0.5rem', 'border-right-width'],
	])('rounds only the page-facing corners and drops the border flush with the %s edge', (edge, radius, borderSide) => {
		expect(sideTabCss(120)).toContain(`.twp-container.twp-side-tab[data-edge="${edge}"] .twp-trigger, .twp-container.twp-side-tab[data-edge="${edge}"] .twp-trigger[data-drag-handle="true"] { border-radius: ${radius} !important; ${borderSide}: 0 !important; }`);
	});

	it.each([
		['bottom', '-3px -3px 0 -3px', '2px 2px 0 2px', 'calc(0.5rem + 3px) calc(0.5rem + 3px) 0 0'],
		['top', '0 -3px -3px -3px', '0 2px 2px 2px', '0 0 calc(0.5rem + 3px) calc(0.5rem + 3px)'],
		['left', '-3px -3px -3px 0', '2px 2px 2px 0', '0 calc(0.5rem + 3px) calc(0.5rem + 3px) 0'],
		['right', '-3px 0 -3px -3px', '2px 0 2px 2px', 'calc(0.5rem + 3px) 0 0 calc(0.5rem + 3px)'],
	])('traces the translating ring to the %s docked corners with no segment on the docked edge', (edge, inset, pad, radius) => {
		expect(sideTabCss(120)).toContain(`.twp-container.twp-side-tab[data-edge="${edge}"] .twp-trigger::after { inset: ${inset} !important; padding: ${pad} !important; border-radius: ${radius} !important; }`);
	});

	it('applies the same docked-edge geometry on a phone-width screen', () => {
		expect(sideTabCss(120)).toContain('.twp-container.twp-side-tab[data-mobile-edge="bottom"] .twp-trigger::after { inset: -3px -3px 0 -3px !important; padding: 2px 2px 0 2px !important; border-radius: calc(0.5rem + 3px) calc(0.5rem + 3px) 0 0 !important; }');
	});
});
