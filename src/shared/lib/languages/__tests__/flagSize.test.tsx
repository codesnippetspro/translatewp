import { getCountryFlagFromLocale } from '@/shared/lib/languages';
import { getShapedFlagStyle } from '@/shared/lib/languages/shapedFlagStyle';

const sheetSize = (style: { backgroundSize?: string | number }) => String(style.backgroundSize).split(' ').map(parseFloat);

describe('scaled flag sprites', () =>
{
    it('keeps the 24 px default and scales the rectangle and its sprite together', () =>
    {
        const normal = getShapedFlagStyle('en-us', { shape: 'rectangle', sizePx: 24 })!;
        const large = getShapedFlagStyle('en-us', { shape: 'rectangle', sizePx: 40 })!;
        expect(normal.width).toBe(24);
        expect(normal.height).toBeCloseTo(14.4);
        expect(large.width).toBe(40);
        expect(large.height).toBe(24);
        sheetSize(large).forEach((value, index) => expect(value / sheetSize(normal)[index]).toBeCloseTo(40 / 24));
        expect(getCountryFlagFromLocale('en-us', { sizePx: 40 })?.props.style).toEqual(large);
    });

    it.each(['circle', 'square'] as const)('scales %s flags to the full configured size', shape =>
    {
        const style = getShapedFlagStyle('en-us', { shape, sizePx: 40 })!;
        expect(style.width).toBe(40);
        expect(style.height).toBe(40);
        expect(style.borderRadius).toBe(shape === 'circle' ? '50%' : '3px');
        expect(getCountryFlagFromLocale('en-us', { iconStyle: shape, sizePx: 40 })?.props.style).toEqual(style);
    });

    it('a zero size hides the sprite and an unknown language has no flag', () =>
    {
        const style = getCountryFlagFromLocale('en-us', { sizePx: 0 })?.props.style;
        expect(style?.width).toBe(0);
        expect(style?.height).toBe(0);
        expect(style?.backgroundSize).toBe('0px 0px');
        expect(getCountryFlagFromLocale('not-a-language', { sizePx: 40 })).toBeNull();
    });
});
