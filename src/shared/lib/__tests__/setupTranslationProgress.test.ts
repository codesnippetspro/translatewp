import type { IndexPageLanguage, IndexPagesResponse } from '@/shared/api/admin';
import type { SetupTranslationProgress } from '@/shared/api/setupTranslationProgress';
import { hasOpenTranslationWork, languagePercent, readinessSummary, translationCellState } from '@/shared/lib/setupTranslationProgress';

const language = (
    status: IndexPageLanguage['status'],
    queueStatus: IndexPageLanguage['queueStatus'],
    reason = ''
): IndexPageLanguage => ({
    id: 1,
    status,
    queueStatus,
    segments: status === 'indexed' ? 4 : 0,
    reason,
    updated: 1,
    title: status === 'indexed' ? 'stored' : 'pending',
});

const pages = (fr: IndexPageLanguage, de: IndexPageLanguage): IndexPagesResponse => ({
    pages: [
        {
            objectType: 'page',
            objectId: 1,
            url: 'https://example.com/',
            title: 'Home',
            languages: { fr, de },
        },
        {
            objectType: 'page',
            objectId: 2,
            url: 'https://example.com/pricing',
            title: 'Pricing',
            languages: {
                fr: language('indexed', 'done'),
                de: language('failed', 'dead', 'provider_unavailable'),
            },
        },
    ],
    totals: { rows: 4, segments: 12 },
});

const progress = (pageResponse: IndexPagesResponse): SetupTranslationProgress => ({
    status: { languages: { fr: { complete: true }, de: { complete: true } } },
    pages: pageResponse,
});

describe('setup translation progress policy', () => {
    it.each<[string, IndexPageLanguage | undefined, string]>([
        ['missing', undefined, 'queued'],
        ['pending', language('pending', 'pending'), 'queued'],
        ['claimed', language('pending', 'claimed'), 'translating'],
        ['accepted but cold', language('pending', 'done'), 'translating'],
        ['retranslating', language('retranslating', 'pending'), 'translating'],
        ['indexed', language('indexed', 'done'), 'translated'],
        ['failed', language('failed', 'dead'), 'failed'],
    ])('maps %s to its display state', (_label, row, expected) => {
        expect(translationCellState(row)).toBe(expected);
    });

    it('stops only when every selected page-language cell is terminal', () => {
        const terminal = progress(pages(language('indexed', 'done'), language('failed', 'dead')));
        const pending = progress(pages(language('indexed', 'done'), language('pending', 'claimed')));

        expect(hasOpenTranslationWork(terminal, ['fr', 'de'])).toBe(false);
        expect(hasOpenTranslationWork(pending, ['fr', 'de'])).toBe(true);
        expect(hasOpenTranslationWork(undefined, ['fr'])).toBe(true);
        expect(hasOpenTranslationWork(terminal, [])).toBe(false);
    });

    it('keeps polling when a selected language has not materialized yet', () => {
        const missing = progress(pages(language('indexed', 'done'), language('indexed', 'done')));
        delete missing.pages.pages[0].languages.fr;

        expect(hasOpenTranslationWork(missing, ['fr', 'de'])).toBe(true);
    });
});

describe('the readiness figure counts translated over every page translation', () => {
    it('keeps a failed page in the total so the percent is honest, and reads the server figure as is', () => {
        const summary = readinessSummary({
            languages: {
                de: { ready: 8, readyTotal: 10, total: 10 },
                fr: { ready: 10, readyTotal: 10, total: 10 },
            },
            read: { pages: 10 },
        }, [], ['de', 'fr']);

        expect(summary.ready).toBe(18);
        expect(summary.total).toBe(20);
        expect(summary.percent).toBe(90);
        expect(languagePercent(summary, 'de')).toBe(80);
        expect(languagePercent(summary, 'fr')).toBe(100);
    });

    it('never counts a language above its total', () => {
        const summary = readinessSummary({ languages: { de: { ready: 12, readyTotal: 10, total: 10 } } }, [], ['de']);
        expect(languagePercent(summary, 'de')).toBe(100);
    });
});
