import { SIDE_TAB_EDGES, type SideTabEdge, type SideTabPosition } from '@/shared/lib/switcherPosition';
import type { SwitcherDrop } from './switcherDrag';

export { SIDE_TAB_EDGES, type SideTabEdge, type SideTabPosition };
/** A phone-width screen keeps the top edge for the site's own header. */
export const SIDE_TAB_PHONE_EDGES = ['right', 'bottom', 'left'] as const;

export type SideTabPlaces = {
	side_tab_position?: SideTabPosition | null;
	side_tab_position_mobile?: SideTabPosition | null;
};

export const SIDE_TAB_DEFAULT: SideTabPosition = { edge: 'bottom', offset: 85 };

/** A share of the edge, 0 to 100, to a tenth of a percent: fine enough that the tab never steps while dragged. */
const clampShare = (value: number): number => Math.round(Math.min(100, Math.max(0, value)) * 10) / 10;

const valid = (position: SideTabPosition | null | undefined): SideTabPosition | null =>
	position && SIDE_TAB_EDGES.includes(position.edge) && typeof position.offset === 'number' && Number.isFinite(position.offset)
		? { edge: position.edge, offset: clampShare(position.offset) }
		: null;

export function resolveSideTabPosition(switcher: SideTabPlaces, device: 'desktop' | 'mobile'): SideTabPosition
{
	if (device === 'desktop') return valid(switcher.side_tab_position) ?? SIDE_TAB_DEFAULT;

	const phone = valid(switcher.side_tab_position_mobile) ?? SIDE_TAB_DEFAULT;
	return phone.edge === 'top' ? { edge: 'bottom', offset: phone.offset } : phone;
}

/** The nearest allowed edge to where the tab was let go, and its share along it. */
export function sideTabFromDrop(drop: SwitcherDrop & { device: 'desktop' | 'mobile' }): SideTabPosition
{
	const { width, height, viewportWidth, viewportHeight, adminBarHeight, device } = drop;
	const left = Math.max(0, Math.min(drop.left, viewportWidth - width));
	const top = Math.max(adminBarHeight, Math.min(drop.top, viewportHeight - height));
	const centreX = left + width / 2;
	const centreY = top + height / 2;
	const distance: Record<SideTabEdge, number> = {
		top: centreY - adminBarHeight,
		right: viewportWidth - centreX,
		bottom: viewportHeight - centreY,
		left: centreX,
	};
	const allowed: readonly SideTabEdge[] = device === 'mobile' ? SIDE_TAB_PHONE_EDGES : SIDE_TAB_EDGES;
	const edge = allowed.reduce((nearest, candidate) => distance[candidate] < distance[nearest] ? candidate : nearest);
	const along = edge === 'top' || edge === 'bottom'
		? (left / Math.max(1, viewportWidth - width)) * 100
		: ((top - adminBarHeight) / Math.max(1, viewportHeight - adminBarHeight - height)) * 100;

	return { edge, offset: clampShare(along) };
}

/** Which way along its edge the list opens: toward the larger room left. */
export const sideTabHalf = (place: SideTabPosition): 'start' | 'end' => place.offset < 50 ? 'start' : 'end';

const TAB_RADIUS = '0.5rem';
// The translating ring (.twp-trigger::after) overhangs the trigger by RING_OVERHANG and is
// RING_THICK wide; these mirror the base ring in SwitcherView so the docked edge drops the
// same rendered geometry the page-facing edges keep.
const RING_OVERHANG = '3px';
const RING_THICK = '2px';
const SIDES = ['top', 'right', 'bottom', 'left'] as const;
const perSide = (docked: SideTabEdge, atDocked: string, atOthers: string): string =>
	SIDES.map(side => (side === docked ? atDocked : atOthers)).join(' ');
const ringRadius = (radius: string): string => radius.replaceAll(TAB_RADIUS, `calc(${TAB_RADIUS} + ${RING_OVERHANG})`);
const GAP = '0.25rem';
/** The least space a switcher's list keeps from the side of the screen. */
export const LIST_SCREEN_MARGIN = '0.5rem';

// How wide the list may grow before the screen ends: from the tab's start or end
// across the rest of the screen on a top or bottom edge, beside the tab on a side edge.
const listRoom = (edge: SideTabEdge, half: 'start' | 'end', tabWidthPx: number): string =>
{
	const tab = `${Math.ceil(tabWidthPx)}px`;
	if (edge === 'left' || edge === 'right') return `calc(100vw - ${tab} - ${GAP} - ${LIST_SCREEN_MARGIN})`;
	const tabStart = `var(--twp-p) * (100vw - ${tab})`;
	return half === 'start'
		? `calc(100vw - ${tabStart} - ${LIST_SCREEN_MARGIN})`
		: `calc(${tabStart} + ${tab} - ${LIST_SCREEN_MARGIN})`;
};

type Box = { top: string; right: string; bottom: string; left: string };
const box = (top: string, right: string, bottom: string, left: string): string =>
	`top: ${top} !important; right: ${right} !important; bottom: ${bottom} !important; left: ${left} !important;`;

const ALONG_X = 'calc(var(--twp-p) * 100%)';
const ALONG_Y = 'calc(var(--twp-admin-bar-height) + var(--twp-p) * (100% - var(--twp-admin-bar-height)))';

const TAB: Record<SideTabEdge, { place: Box; transform: string; radius: string; slide: string }> = {
	bottom: { place: { top: 'auto', right: 'auto', bottom: '0', left: ALONG_X }, transform: 'translateX(calc(var(--twp-p) * -100%))', radius: `${TAB_RADIUS} ${TAB_RADIUS} 0 0`, slide: 'translateY(8px)' },
	top: { place: { top: 'var(--twp-admin-bar-height)', right: 'auto', bottom: 'auto', left: ALONG_X }, transform: 'translateX(calc(var(--twp-p) * -100%))', radius: `0 0 ${TAB_RADIUS} ${TAB_RADIUS}`, slide: 'translateY(-8px)' },
	left: { place: { top: ALONG_Y, right: 'auto', bottom: 'auto', left: '0' }, transform: 'translateY(calc(var(--twp-p) * -100%))', radius: `0 ${TAB_RADIUS} ${TAB_RADIUS} 0`, slide: 'translateX(-8px)' },
	right: { place: { top: ALONG_Y, right: '0', bottom: 'auto', left: 'auto' }, transform: 'translateY(calc(var(--twp-p) * -100%))', radius: `${TAB_RADIUS} 0 0 ${TAB_RADIUS}`, slide: 'translateX(8px)' },
};

// The list opens away from the edge, aligned with the tab's end that has more room.
const LIST: Record<SideTabEdge, Record<'start' | 'end', { place: Box; margin: string; align: string }>> = {
	bottom: {
		start: { place: { top: 'auto', right: 'auto', bottom: '100%', left: '0' }, margin: `0 0 ${GAP} 0`, align: 'flex-start' },
		end: { place: { top: 'auto', right: '0', bottom: '100%', left: 'auto' }, margin: `0 0 ${GAP} 0`, align: 'flex-end' },
	},
	top: {
		start: { place: { top: '100%', right: 'auto', bottom: 'auto', left: '0' }, margin: `${GAP} 0 0 0`, align: 'flex-start' },
		end: { place: { top: '100%', right: '0', bottom: 'auto', left: 'auto' }, margin: `${GAP} 0 0 0`, align: 'flex-end' },
	},
	left: {
		start: { place: { top: '0', right: 'auto', bottom: 'auto', left: '100%' }, margin: `0 0 0 ${GAP}`, align: 'flex-start' },
		end: { place: { top: 'auto', right: 'auto', bottom: '0', left: '100%' }, margin: `0 0 0 ${GAP}`, align: 'flex-start' },
	},
	right: {
		start: { place: { top: '0', right: '100%', bottom: 'auto', left: 'auto' }, margin: `0 ${GAP} 0 0`, align: 'flex-end' },
		end: { place: { top: 'auto', right: '100%', bottom: '0', left: 'auto' }, margin: `0 ${GAP} 0 0`, align: 'flex-end' },
	},
};

// The handle sits in the middle of the side facing the page, so the tab stays centred under the pointer that drags it.
const DOT_CENTRE = 'calc(50% - 11px)';
const DOT: Record<SideTabEdge, Box> = {
	bottom: { top: '-11px', right: 'auto', bottom: 'auto', left: DOT_CENTRE },
	top: { top: 'auto', right: 'auto', bottom: '-11px', left: DOT_CENTRE },
	left: { top: DOT_CENTRE, right: '-11px', bottom: 'auto', left: 'auto' },
	right: { top: DOT_CENTRE, right: 'auto', bottom: 'auto', left: '-11px' },
};

const edgeRules = (edgeAttr: string, halfAttr: string, shareVar: string, tabWidthPx: number): string => SIDE_TAB_EDGES.map(edge =>
{
	const tab = TAB[edge];
	const at = `.twp-container.twp-side-tab[${edgeAttr}="${edge}"]`;
	const lists = (['start', 'end'] as const).map(half =>
	{
		const list = LIST[edge][half];
		return `${at}[${halfAttr}="${half}"] { --twp-list-room: ${listRoom(edge, half, tabWidthPx)}; }
${at}[${halfAttr}="${half}"] .twp-popover-stack { ${box(list.place.top, list.place.right, list.place.bottom, list.place.left)} margin: ${list.margin} !important; align-items: ${list.align} !important; }`;
	}).join('\n');
	const dot = DOT[edge];
	const ring = `inset: ${perSide(edge, '0', `-${RING_OVERHANG}`)} !important; padding: ${perSide(edge, '0', RING_THICK)} !important; border-radius: ${ringRadius(tab.radius)} !important;`;
	return `${at} { --twp-p: calc(var(${shareVar}) / 100); ${box(tab.place.top, tab.place.right, tab.place.bottom, tab.place.left)} transform: ${tab.transform} !important; }
${at} .twp-trigger, ${at} .twp-trigger[data-drag-handle="true"] { border-radius: ${tab.radius} !important; border-${edge}-width: 0 !important; }
${at} .twp-trigger::after { ${ring} }
${at} .twp-dropdown { animation: twp-tab-slide-${edge} 150ms ease-out !important; }
${at} .twp-drag-dot { ${box(dot.top, dot.right, dot.bottom, dot.left)} }
${lists}`;
}).join('\n');

/** The side tab's own rules: its place on the edge, its shape, and where its list opens. */
export function sideTabCss(tabWidthPx: number): string
{
	const slides = SIDE_TAB_EDGES.map(edge => `@keyframes twp-tab-slide-${edge} { from { opacity: 0; transform: ${TAB[edge].slide}; } }`).join('\n');
	return `${slides}
${edgeRules('data-edge', 'data-half', '--twp-tab-share', tabWidthPx)}
.twp-container.twp-side-tab[data-preview="true"] { position: absolute !important; --twp-admin-bar-height: 0px !important; z-index: 1 !important; }
@media screen and (max-width: 782px) {
${edgeRules('data-mobile-edge', 'data-mobile-half', '--twp-tab-share-mobile', tabWidthPx)}
.twp-side-tab .twp-dropdown-item { white-space: normal !important; overflow-wrap: anywhere !important; }
}
@media (prefers-reduced-motion: reduce) {
.twp-side-tab .twp-dropdown { animation: none !important; }
}`;
}
