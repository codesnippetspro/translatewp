import { hasPendingTitles, INDEX_PAGES_PENDING_REFETCH_MS } from '@/shared/lib/indexPages';
import { getIndexPagesQueryOptions } from '@/shared/query-options/admin';
import type { IndexPageLanguage, IndexPagesResponse, IndexTitleState } from '@/shared/api/admin';

const language = (title: IndexTitleState): IndexPageLanguage => ({
    id: 1,
    status: 'indexed',
    segments: 3,
    reason: '',
    updated: 1,
    title,
});

const response = (titles: IndexTitleState[]): IndexPagesResponse => ({
    pages: [
        {
            objectType: 'page',
            objectId: 1,
            url: 'https://example.com/',
            title: 'Home',
            languages: Object.fromEntries(titles.map((title, index) => [`lang-${index}`, language(title)])),
        },
    ],
    totals: { rows: titles.length, segments: titles.length * 3 },
});

const refetchIntervalFor = (data: IndexPagesResponse | undefined) =>
{
    const interval = getIndexPagesQueryOptions().refetchInterval;

    return typeof interval === 'function'
        ? interval({ state: { data } } as Parameters<typeof interval>[0])
        : interval;
};

describe('hasPendingTitles', () =>
{
    it('is true only while a page title is still pending', () =>
    {
        expect(hasPendingTitles(response(['stored', 'pending']))).toBe(true);
        expect(hasPendingTitles(response(['stored', 'missing']))).toBe(false);
        expect(hasPendingTitles(undefined)).toBe(false);
    });
});

describe('getIndexPagesQueryOptions', () =>
{
    it('refetches while a title is pending and stops once none are', () =>
    {
        expect(refetchIntervalFor(response(['pending']))).toBe(INDEX_PAGES_PENDING_REFETCH_MS);
        expect(refetchIntervalFor(response(['stored']))).toBe(false);
        expect(refetchIntervalFor(undefined)).toBe(false);
    });
});
