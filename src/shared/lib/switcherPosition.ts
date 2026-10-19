export const SWITCHER_POSITION_MAX = 100000;
export const SWITCHER_MOBILE_WIDTH = 782;
/** A wide screen and a phone-width one keep separate floating positions. */
export type SwitcherDevice = 'desktop' | 'mobile';

export const SWITCHER_ANCHORS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'] as const;

export const SIDE_TAB_EDGES = ['top', 'right', 'bottom', 'left'] as const;

export type SideTabEdge = typeof SIDE_TAB_EDGES[number];

/**
 * A side tab's place: the edge it is attached to and how far along that edge
 * it sits, from 0 (flush with the start: the left, or the top below the
 * administrator bar) to 100 (flush with the end).
 */
export type SideTabPosition = { edge: SideTabEdge; offset: number };

export type FloatingSwitcherPosition = {
	anchor: typeof SWITCHER_ANCHORS[number];
	x: number;
	y: number;
};
