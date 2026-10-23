import { frontendHost } from '@/shared/host/frontend';
import { livePlacement } from '@/apps/translatewp/inlinePlacement';

const sitePlacement = { kind: 'anchor' as const, selector: '#site-header', position: 'right' as const };
const pagePlacement = { kind: 'anchor' as const, selector: '#page-footer', position: 'before' as const };
const movedPlacement = { kind: 'anchor' as const, selector: '#page-title', position: 'after' as const };

const original = window.TranslateWP;

beforeEach(() =>
{
    livePlacement.reset();
    window.TranslateWP = {
        context: { page_placement: null },
        options: { languages: { switcher: { inline_placement: sitePlacement } } },
    } as Window['TranslateWP'];
});

afterEach(() =>
{
    livePlacement.reset();
    window.TranslateWP = original;
});

it('reports the loaded site placement when no live change or page override exists', () =>
{
    expect(frontendHost.placement.current()).toEqual(sitePlacement);
});

it('reports the page override ahead of the loaded site placement', () =>
{
    window.TranslateWP.context.page_placement = pagePlacement;

    expect(frontendHost.placement.current()).toEqual(pagePlacement);
});

it('reports the latest applied placement while preserving the loaded settings', () =>
{
    window.TranslateWP.context.page_placement = pagePlacement;
    frontendHost.placement.apply(movedPlacement);

    expect(frontendHost.placement.current()).toEqual(movedPlacement);
    expect(window.TranslateWP.context.page_placement).toEqual(pagePlacement);
    expect(window.TranslateWP.options.languages.switcher.inline_placement).toEqual(sitePlacement);
});

it('reports an explicit live removal instead of falling back to the loaded placement', () =>
{
    frontendHost.placement.apply(null);

    expect(frontendHost.placement.current()).toBeNull();
});
