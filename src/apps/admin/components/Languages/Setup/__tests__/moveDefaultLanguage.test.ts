import { moveDefaultLanguage } from '@/apps/admin/components/Languages/Setup/DefaultLanguageControl';

const list = [
    { locale: 'pt', slug: 'pt', active: true, default: true },
    { locale: 'ja', slug: 'ja', active: true, default: false },
    { locale: 'ar', slug: 'ar', active: false, default: false },
];

describe('moveDefaultLanguage', () =>
{
    it('flags the new default, keeps the old default in the list switched off', () =>
    {
        const next = moveDefaultLanguage(list, 'ja');
        expect(next.find(l => l.locale === 'ja')).toMatchObject({ default: true, active: true });
        expect(next.find(l => l.locale === 'pt')).toMatchObject({ default: false, active: false });
        expect(next.find(l => l.locale === 'ar')).toMatchObject({ default: false, active: false });
        expect(next.filter(l => l.default)).toHaveLength(1);
    });

    it('leaves the list alone for an unknown locale', () =>
    {
        expect(moveDefaultLanguage(list, 'zz')).toBe(list);
    });
});
