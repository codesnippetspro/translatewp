import { MIN_SWITCHER_FONT_SIZE, MAX_SWITCHER_FONT_SIZE } from '@/shared/lib/switcherFontSize';

export const numberFieldBounds = Object.freeze({
    fontSize: { min: MIN_SWITCHER_FONT_SIZE, max: MAX_SWITCHER_FONT_SIZE },
    switcherMetric: { min: 0, max: 500 },
    cookieDays: { min: 1, max: 365 },
});

export const clampNumberField = (value: number, min: number, max: number): number =>
    Math.min(max, Math.max(min, Math.trunc(value)));

export const numberFieldDragValue = (value: number, deltaY: number, step: number, min: number, max: number): number =>
    clampNumberField(value + Math.trunc(deltaY / 4) * step, min, max);
