import { switcherLabel } from '@/shared/lib/languages/switcherLabel';
import languagesData from '@/shared/lib/languages/languages.json';

const language = (locale: string, switcher_label?: string) => ({ locale, switcher_label });

describe('switcherLabel', () =>
{
    it.each([
        // locale, regional names, flag shown, own-language names, expected
        ['de-at', 'full', false, true, 'Österreichisches Deutsch'],
        ['de-at', 'full', true, true, 'Österreichisches Deutsch'],
        ['de-at', 'full', false, false, 'Austrian German'],
        ['de-at', 'short', false, true, 'Deutsch (AT)'],
        ['de-at', 'short', false, false, 'German (AT)'],
        ['de-at', 'short', true, true, 'Deutsch'],
        ['de-at', 'short', true, false, 'German'],
        ['en-us', 'full', false, true, 'American English'],
        ['en-us', 'short', false, true, 'English (US)'],
        ['en-gb', 'short', true, true, 'English'],
        ['pt-br', 'short', false, true, 'Português (BR)'],
        ['es-mx', 'short', false, false, 'Spanish (MX)'],
        ['fr-ca', 'short', false, true, 'Français (CA)'],
        ['de', 'short', false, true, 'Deutsch'],
        ['it', 'short', false, true, 'Italiano'],
        ['ja', 'short', true, true, '日本語'],
        ['ja', 'short', false, false, 'Japanese'],
        ['zh-hans', 'short', false, true, '简体中文'],
        ['zh-hant', 'short', false, true, '繁體中文'],
        ['fr-ac', 'short', false, true, 'Acadien'],
        ['es-an', 'short', false, true, 'Español de Andalucía'],
        ['sgn-us', 'short', false, true, 'American Sign Language'],
    ] as const)('%s with %s names, flag %s, own-language %s reads %s', (locale, regionalNames, flagShown, nativeNames, expected) =>
    {
        expect(switcherLabel(language(locale), { regionalNames, flagShown, nativeNames })).toBe(expected);
    });

    it.each([
        ['full', false, true],
        ['short', false, true],
        ['short', true, true],
        ['short', false, false],
        ['short', true, false],
    ] as const)('a custom label wins with %s names, flag %s, own-language %s', (regionalNames, flagShown, nativeNames) =>
    {
        expect(switcherLabel(language('de-at', 'Österreich'), { regionalNames, flagShown, nativeNames })).toBe('Österreich');
    });

    it('defaults to own-language full names', () =>
    {
        expect(switcherLabel(language('de-at'))).toBe('Österreichisches Deutsch');
        expect(switcherLabel(language('de'))).toBe('Deutsch');
    });

    it('falls back to the locale for a language the catalogue does not know', () =>
    {
        expect(switcherLabel(language('xx-yy'), { regionalNames: 'short' })).toBe('xx-yy');
    });
});

describe('regional names in the language catalogue', () =>
{
    const catalogue = languagesData as Record<string, { nameNative: string; region?: string }>;

    it.each([
        ['de-at', 'Österreichisches Deutsch'],
        ['de-ch', 'Schweizer Hochdeutsch'],
        ['en-gb', 'British English'],
        ['en-us', 'American English'],
        ['pt-br', 'Português do Brasil'],
        ['pt-pt', 'Português de Portugal'],
        ['es-mx', 'Español de México'],
        ['fr-ca', 'Français du Canada'],
        ['fr-be', 'Français de Belgique'],
        ['fr-ch', 'Français de Suisse'],
        ['ar-ly', 'العربية - اللهجة الليبية'],
        ['zh-hans', '简体中文'],
        ['zh-hant', '繁體中文'],
    ])('%s is named %s in its own language', (locale, name) =>
    {
        expect(catalogue[locale].nameNative).toBe(name);
    });

    it.each([
        ['de-at', 'AT'], ['de-ch', 'CH'], ['en-us', 'US'], ['en-gb', 'GB'], ['pt-br', 'BR'], ['es-mx', 'MX'], ['fr-ca', 'CA'], ['ar-sa', 'SA'], ['nl-be', 'BE'],
    ])('%s carries the region %s', (locale, region) =>
    {
        expect(catalogue[locale].region).toBe(region);
    });

    it.each(['de', 'zh-hans', 'sr-latn', 'fr-ac', 'fr-qc', 'es-an', 'la-va', 'fa-af', 'fa-tj', 'no-nb', 'sgn-us', 'en-old'])('%s carries no region', (locale) =>
    {
        expect(catalogue[locale].region).toBeUndefined();
    });
});
