import { resolveSwitcherChrome, resolveSwitcherPosition } from '@/apps/translatewp/resolveSwitcherChrome';

describe('resolveSwitcherChrome', () => {
    it('defaults missing metrics to inset 24, icon 24, trigger 48', () => {
        expect(resolveSwitcherChrome({})).toEqual({
            insetPx: 24,
            iconSizePx: 24,
            triggerSizePx: 48,
        });
    });

    it.each([
        ['string', '32'],
        ['null', null],
        ['NaN', Number.NaN],
        ['Infinity', Number.POSITIVE_INFINITY],
        ['object', {}],
    ])('defaults invalid offset %s to 24 and keeps other defaults', (_label, offset) => {
        expect(
            resolveSwitcherChrome({
                offset: offset as unknown as number,
            })
        ).toEqual({
            insetPx: 24,
            iconSizePx: 24,
            triggerSizePx: 48,
        });
    });

    it.each([
        ['string', '24'],
        ['null', null],
        ['NaN', Number.NaN],
        ['Infinity', Number.POSITIVE_INFINITY],
    ])('defaults invalid icon_size %s to 24', (_label, icon_size) => {
        expect(
            resolveSwitcherChrome({
                offset: 40,
                icon_size: icon_size as unknown as number,
                padding: 8,
            })
        ).toEqual({
            insetPx: 40,
            iconSizePx: 24,
            triggerSizePx: 40,
        });
    });

    it.each([
        ['string', '12'],
        ['null', null],
        ['NaN', Number.NaN],
        ['Infinity', Number.POSITIVE_INFINITY],
    ])('defaults invalid padding %s to 12', (_label, padding) => {
        expect(
            resolveSwitcherChrome({
                offset: 40,
                icon_size: 20,
                padding: padding as unknown as number,
            })
        ).toEqual({
            insetPx: 40,
            iconSizePx: 20,
            triggerSizePx: 44,
        });
    });

    it('clamps negative metrics to 0', () => {
        expect(
            resolveSwitcherChrome({
                offset: -1,
                icon_size: -3,
                padding: -2,
            })
        ).toEqual({
            insetPx: 0,
            iconSizePx: 0,
            triggerSizePx: 0,
        });
    });

    it('truncates fractional metrics toward zero', () => {
        expect(
            resolveSwitcherChrome({
                offset: 24.7,
                icon_size: 24.9,
                padding: 12.1,
            })
        ).toEqual({
            insetPx: 24,
            iconSizePx: 24,
            triggerSizePx: 48,
        });
    });

    it('maps offset 40, icon 20, padding 8 to inset 40, icon 20, trigger 36', () => {
        expect(
            resolveSwitcherChrome({
                offset: 40,
                icon_size: 20,
                padding: 8,
            })
        ).toEqual({
            insetPx: 40,
            iconSizePx: 20,
            triggerSizePx: 36,
        });
    });

    it('maps offset 0, icon 16, padding 0 to inset 0, icon 16, trigger 16', () => {
        expect(
            resolveSwitcherChrome({
                offset: 0,
                icon_size: 16,
                padding: 0,
            })
        ).toEqual({
            insetPx: 0,
            iconSizePx: 16,
            triggerSizePx: 16,
        });
    });
});


describe('saved floating position', () => {
    it('falls back to the existing offset at the top-right corner', () => {
        expect(resolveSwitcherPosition({ offset: 40 })).toEqual({ anchor: 'top-right', x: 40, y: 40 });
    });
    it.each(['top-left', 'top-right', 'bottom-left', 'bottom-right'] as const)(
        'preserves the %s anchor and its independent offsets', anchor => {
            expect(resolveSwitcherPosition({ floating_position: { anchor, x: 52, y: 152 } }))
                .toEqual({ anchor, x: 52, y: 152 });
        }
    );
    it.each([null, {}, { anchor: 'middle', x: 1, y: 2 },
        { anchor: 'top-left', x: NaN, y: 2 }, { anchor: 'top-left', x: -1, y: 2 },
        { anchor: 'top-left', x: '2', y: 2 }, { anchor: 'top-left', x: 2 }])(
        'falls back when the saved position is invalid', floating_position => {
            expect(resolveSwitcherPosition({ floating_position } as any))
                .toEqual({ anchor: 'top-right', x: 24, y: 24 });
        }
    );
});
