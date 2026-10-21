import type { SwitcherBackgroundStyle } from './types';

/**
 * How the switcher's surfaces are painted for each background choice.
 *
 * - solid: the background colour, as before.
 * - transparent: the closed switcher shows the page through a thin outline,
 *   with a soft halo behind the text so it reads on light and dark pages; the
 *   open list is frosted glass, since a list over the page must stay readable.
 * - glass: a frosted, slightly see-through surface over whatever is behind it.
 *
 * A browser without backdrop-filter gets a nearly opaque background instead.
 */
export type SwitcherSurface = {
    /** The closed switcher: the button, the browser dropdown, the edit button. */
    closed: string;
    /** What opens over the page: the language list and the message bubble. */
    panel: string;
    /** Rules for browsers that cannot blur what is behind an element. */
    fallback: string;
};

export const DEFAULT_SWITCHER_BORDER = '#E2E2E4';
const GLASS_ALPHA = 0.6;
const FALLBACK_ALPHA = 0.92;

/** The red, green and blue of a hex colour, dropping any alpha channel (#RGBA or #RRGGBBAA). */
export const rgbOf = (hex: string): [number, number, number] | null =>
{
    const value = hex.trim().replace(/^#/, '');
    const expanded = value.length === 3 || value.length === 4
        ? value.split('').map(digit => digit + digit).join('')
        : value;
    const full = expanded.length === 8 ? expanded.slice(0, 6) : expanded;
    if (!/^[0-9a-f]{6}$/i.test(full)) return null;
    return [0, 2, 4].map(start => parseInt(full.slice(start, start + 2), 16)) as [number, number, number];
};

const rgba = (hex: string, alpha: number, fallback: string): string =>
{
    const rgb = rgbOf(hex);
    return rgb ? `rgba(${rgb.join(', ')}, ${alpha})` : fallback;
};

/** Relative luminance, 0 for black and 1 for white. */
export const luminanceOf = (hex: string): number =>
{
    const rgb = rgbOf(hex);
    if (!rgb) return 0;
    const [r, g, b] = rgb.map(channel =>
    {
        const value = channel / 255;
        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

const glassBackground = (background: string, defaultAlpha: number): string =>
    /^#(?:[0-9a-f]{4}|[0-9a-f]{8})$/i.test(background.trim())
        ? background.trim()
        : rgba(background, defaultAlpha, `rgba(255, 255, 255, ${defaultAlpha})`);

const glass = (background: string, border: string): string => [
    `background-color: ${glassBackground(background, GLASS_ALPHA)} !important`,
    'backdrop-filter: blur(14px) saturate(160%) !important',
    '-webkit-backdrop-filter: blur(14px) saturate(160%) !important',
    `border: 1px solid ${border} !important`,
].join(';\n') + ';';

export const switcherSurface = (style: SwitcherBackgroundStyle | undefined, background: string, text: string, border: string = DEFAULT_SWITCHER_BORDER): SwitcherSurface =>
{
    if (style === 'glass' || style === 'transparent')
    {
        // Light text gets a soft drop shadow, as on a photo; dark text a faint light edge.
        const shadow = luminanceOf(text) < 0.4 ? '0 0 2px rgba(255, 255, 255, 0.7)' : '0 1px 2px rgba(0, 0, 0, 0.45)';
        const closed = style === 'glass'
            ? glass(background, border)
            : [
                'background-color: transparent !important',
                `border: 1px solid ${rgba(text, 0.45, 'rgba(0, 0, 0, 0.35)')} !important`,
                `text-shadow: ${shadow} !important`,
                'box-shadow: none !important',
            ].join(';\n') + ';';
        const fallbackBackground = glassBackground(background, FALLBACK_ALPHA);

        return {
            closed,
            panel: glass(background, border),
            fallback: `@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
                ${style === 'glass' ? '.twp-trigger, .twp-native-select, .twp-native-admin,' : ''} .twp-dropdown, .twp-toast {
                    background-color: ${fallbackBackground} !important;
                }
            }`,
        };
    }

    const solid = `background-color: ${background} !important;\nborder: 1px solid ${border} !important;`;
    return { closed: solid, panel: solid, fallback: '' };
};
