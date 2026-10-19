import { luminanceOf, rgbOf, switcherSurface } from '@/apps/translatewp/switcherSurface';

describe('the switcher background choices', () =>
{
    it('paints the background colour for solid, as before', () =>
    {
        const surface = switcherSurface('solid', '#FFFFFF', '#393535');
        expect(surface.closed).toContain('background-color: #FFFFFF');
        expect(surface.closed).toContain('#E2E2E4');
        expect(surface.panel).toBe(surface.closed);
        expect(surface.fallback).toBe('');
    });

    it('treats a missing choice as solid', () =>
    {
        expect(switcherSurface(undefined, '#123456', '#FFFFFF').closed).toContain('background-color: #123456');
    });

    it('shows the page through an outline when transparent, and keeps the open list readable', () =>
    {
        const surface = switcherSurface('transparent', '#FFFFFF', '#393535');
        expect(surface.closed).toContain('background-color: transparent');
        expect(surface.closed).toMatch(/border: 1px solid rgba\(57, 53, 53, 0\.45\)/);
        expect(surface.closed).toContain('text-shadow');
        expect(surface.panel).toContain('backdrop-filter: blur(14px)');
    });

    it('gives light text a soft drop shadow and dark text a faint light edge', () =>
    {
        expect(switcherSurface('transparent', '#000000', '#F5F5F5').closed).toContain('text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45)');
        expect(switcherSurface('transparent', '#000000', '#222222').closed).toContain('text-shadow: 0 0 2px rgba(255, 255, 255, 0.7)');
    });

    it('frosts the closed switcher and the open list for matte glass', () =>
    {
        const surface = switcherSurface('glass', '#FFFFFF', '#393535');
        for (const part of [surface.closed, surface.panel])
        {
            expect(part).toContain('background-color: rgba(255, 255, 255, 0.6)');
            expect(part).toContain('-webkit-backdrop-filter: blur(14px) saturate(160%)');
        }
    });

    it('falls back to a nearly solid surface where the browser cannot blur', () =>
    {
        const glass = switcherSurface('glass', '#102030', '#FFFFFF').fallback;
        expect(glass).toContain('@supports not');
        expect(glass).toContain('.twp-trigger');
        expect(glass).toContain('rgba(16, 32, 48, 0.92)');
        expect(switcherSurface('transparent', '#102030', '#FFFFFF').fallback).not.toContain('.twp-trigger');
    });

    it('reads short and long colour codes and rejects anything else', () =>
    {
        expect(rgbOf('#fff')).toEqual([255, 255, 255]);
        expect(rgbOf('0e3ecd')).toEqual([14, 62, 205]);
        expect(rgbOf('red')).toBeNull();
        expect(luminanceOf('#FFFFFF')).toBeCloseTo(1);
        expect(luminanceOf('#000000')).toBe(0);
    });
});
