import { getSuggestedTargets } from '@/apps/setup/components/languagesStep/suggestedTargets';

describe('getSuggestedTargets', () =>
{
    it('offers common targets for an English site and never suggests the source language', () =>
    {
        const targets = getSuggestedTargets('en-us');

        expect(targets).toEqual(['es', 'fr', 'de', 'pt-br', 'it', 'zh-hans']);
        expect(targets).not.toContain('en');
        expect(targets).not.toContain('en-us');
    });

    it('keys suggestions off the base language, not the exact locale', () =>
    {
        expect(getSuggestedTargets('pt-br')).toEqual(getSuggestedTargets('pt-pt'));
    });

    it('falls back to a default set for an unmapped source and drops that source', () =>
    {
        const targets = getSuggestedTargets('ar');

        expect(targets.length).toBeGreaterThan(0);
        expect(targets).not.toContain('ar');
    });

    it('puts the admin locale first when it differs from the site language', () =>
    {
        const targets = getSuggestedTargets('en-us', ['nl_NL']);

        expect(targets[0]).toBe('nl');
        expect(new Set(targets).size).toBe(targets.length);
        expect(targets).not.toContain('en-us');
    });

    it('drops preferred locales in the site language and keeps common targets once', () =>
    {
        const targets = getSuggestedTargets('en-us', ['en_GB', 'fr-FR', 'nl']);

        expect(targets.slice(0, 2)).toEqual(['fr', 'nl']);
        expect(targets.filter((locale) => locale === 'fr')).toHaveLength(1);
        expect(targets.some((locale) => locale.startsWith('en'))).toBe(false);
    });
});
