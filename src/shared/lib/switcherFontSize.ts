export const DEFAULT_SWITCHER_FONT_SIZE = 14;
export const SWITCHER_LANGUAGE_LINE_HEIGHT_RATIO = 20 / DEFAULT_SWITCHER_FONT_SIZE;
export const MIN_SWITCHER_FONT_SIZE = 10;
export const MAX_SWITCHER_FONT_SIZE = 32;

export const resolveSwitcherFontSize = (value: unknown): number =>
{
    if (typeof value !== 'number' || !Number.isFinite(value)) return DEFAULT_SWITCHER_FONT_SIZE;
    return Math.min(MAX_SWITCHER_FONT_SIZE, Math.max(MIN_SWITCHER_FONT_SIZE, Math.trunc(value)));
};
