import { resolveSwitcherPosition } from '@/apps/translatewp/resolveSwitcherChrome';

describe('where the floating switcher sits on each screen width', () =>
{
    it('uses the dragged position on a wide screen, or the top right corner', () =>
    {
        expect(resolveSwitcherPosition({ offset: 24 })).toEqual({ anchor: 'top-right', x: 24, y: 24 });
        expect(resolveSwitcherPosition({ floating_position: { anchor: 'bottom-left', x: 40, y: 60 } })).toEqual({ anchor: 'bottom-left', x: 40, y: 60 });
    });

    it('keeps a phone in the bottom corner on the same side until the owner drags it there', () =>
    {
        expect(resolveSwitcherPosition({ offset: 16 }, 'mobile')).toEqual({ anchor: 'bottom-right', x: 16, y: 16 });
        expect(resolveSwitcherPosition({ offset: 16, floating_position: { anchor: 'top-left', x: 500, y: 300 } }, 'mobile')).toEqual({ anchor: 'bottom-left', x: 16, y: 16 });
    });

    it('uses the phone position once the owner dragged it on a phone', () =>
    {
        expect(resolveSwitcherPosition({ floating_position_mobile: { anchor: 'top-left', x: 12, y: 80 } }, 'mobile')).toEqual({ anchor: 'top-left', x: 12, y: 80 });
    });

    it('ignores a stored position that is not a position', () =>
    {
        expect(resolveSwitcherPosition({ offset: 24, floating_position_mobile: { anchor: 'middle' as never, x: 1, y: 1 } }, 'mobile')).toEqual({ anchor: 'bottom-right', x: 24, y: 24 });
    });
});
