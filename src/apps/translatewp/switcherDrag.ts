import type { FloatingSwitcherPosition } from '@/shared/lib/switcherPosition';
import { SWITCHER_POSITION_MAX } from '@/shared/lib/switcherPosition';

const DRAG_THRESHOLD = 6;

export function hasDragMovement(delta: { x: number; y: number }): boolean
{
	return Math.hypot(delta.x, delta.y) >= DRAG_THRESHOLD;
}

export type SwitcherDrop = {
	left: number;
	top: number;
	width: number;
	height: number;
	viewportWidth: number;
	viewportHeight: number;
	adminBarHeight: number;
};

export function positionFromDrop(drop: SwitcherDrop): FloatingSwitcherPosition
{
	const { width, height, viewportWidth, viewportHeight, adminBarHeight } = drop;
	const left = Math.max(0, Math.min(drop.left, viewportWidth - width));
	const top = Math.max(adminBarHeight, Math.min(drop.top, viewportHeight - height));
	const right = left + width / 2 >= viewportWidth / 2;
	const bottom = top + height / 2 >= (viewportHeight + adminBarHeight) / 2;
	const x = right ? viewportWidth - left - width : left;
	const y = bottom ? viewportHeight - top - height : top - adminBarHeight;
	return {
		anchor: `${bottom ? 'bottom' : 'top'}-${right ? 'right' : 'left'}`,
		x: Math.min(SWITCHER_POSITION_MAX, Math.max(0, Math.round(x))),
		y: Math.min(SWITCHER_POSITION_MAX, Math.max(0, Math.round(y))),
	};
}
