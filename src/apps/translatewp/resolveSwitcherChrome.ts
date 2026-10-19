import { SWITCHER_ANCHORS, SWITCHER_POSITION_MAX } from '@/shared/lib/switcherPosition';
import type { FloatingSwitcherPosition } from '@/shared/lib/switcherPosition';
import type { PublicSwitcherSettings } from '@/apps/translatewp/types';

export const DEFAULT_SWITCHER_OFFSET = 24;
export const DEFAULT_SWITCHER_ICON_SIZE = 24;
export const DEFAULT_SWITCHER_PADDING = 12;

export type SwitcherChromeMetrics = {
    insetPx: number;
    iconSizePx: number;
    triggerSizePx: number;
};

const normalizeNonNegativeInt = (value: unknown, defaultValue: number): number =>
{
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return defaultValue;
    }

    if (value < 0) {
        return 0;
    }

    return Math.trunc(value);
};

export function resolveSwitcherChrome(
    switcher: Pick<PublicSwitcherSettings, 'offset' | 'icon_size' | 'padding'>
): SwitcherChromeMetrics
{
    const iconSizePx = normalizeNonNegativeInt(switcher.icon_size, DEFAULT_SWITCHER_ICON_SIZE);
    const paddingPx = normalizeNonNegativeInt(switcher.padding, DEFAULT_SWITCHER_PADDING);

    return {
        insetPx: normalizeNonNegativeInt(switcher.offset, DEFAULT_SWITCHER_OFFSET),
        iconSizePx,
        triggerSizePx: iconSizePx + 2 * paddingPx,
    };
}

const validPosition = (position: FloatingSwitcherPosition | undefined): FloatingSwitcherPosition | null =>
	position && SWITCHER_ANCHORS.includes(position.anchor)
		&& [position.x, position.y].every(value => typeof value === 'number'
			&& Number.isFinite(value) && value >= 0 && value <= SWITCHER_POSITION_MAX)
		? { anchor: position.anchor, x: Math.trunc(position.x), y: Math.trunc(position.y) }
		: null;

/**
 * Where the floating switcher sits. A wide screen uses the position the owner
 * dragged it to, or the top right corner. A phone-width screen uses its own
 * position, since a place on a desktop screen means nothing on a phone; until
 * the owner drags it there, it sits in the bottom corner on the same side.
 */
export function resolveSwitcherPosition(switcher: PublicSwitcherSettings, device: 'desktop' | 'mobile' = 'desktop'): FloatingSwitcherPosition
{
	const { insetPx } = resolveSwitcherChrome(switcher);
	const desktop = validPosition(switcher.floating_position) ?? { anchor: 'top-right', x: insetPx, y: insetPx };
	if (device === 'desktop') return desktop;

	return validPosition(switcher.floating_position_mobile)
		?? { anchor: desktop.anchor.endsWith('left') ? 'bottom-left' : 'bottom-right', x: insetPx, y: insetPx };
}
