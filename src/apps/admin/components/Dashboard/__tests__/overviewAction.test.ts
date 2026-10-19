import { resolveOverviewAction, type OverviewActionInput, type OverviewLanguageStatus } from '@/apps/admin/components/Dashboard/overviewAction';

const lang = (locale: string, overrides: Partial<OverviewLanguageStatus> = {}): OverviewLanguageStatus => ({
    locale,
    ready: 0,
    readyTotal: 0,
    readyComplete: false,
    total: 0,
    dead: 0,
    repairing: 0,
    retranslating: 0,
    ...overrides,
});

const base: OverviewActionInput = {
    connected: true,
    connectionStatus: 'connected',
    addressChanged: false,
    statusFailed: false,
    quotaPaused: false,
    scheduledTasksLate: false,
    hasTargetLanguage: true,
    readPages: 10,
    changedPages: 0,
    staleCount: 0,
    firstPassDone: true,
    languages: [lang('de', { total: 10, readyTotal: 10, ready: 10, readyComplete: true })],
};

const complete = (locale: string): OverviewLanguageStatus => lang(locale, { total: 10, readyTotal: 10, ready: 10, readyComplete: true });

describe('resolveOverviewAction, one next action per state', () =>
{
    it('defers to the connection surfaces when not paired', () =>
    {
        expect(resolveOverviewAction({ ...base, connected: false }).kind).toBe('connection');
    });

    it('defers to the connection surfaces when the pairing is invalidated', () =>
    {
        expect(resolveOverviewAction({ ...base, connectionStatus: 'invalidated' }).kind).toBe('connection');
    });

    it('defers to the connection surfaces when the address changed', () =>
    {
        expect(resolveOverviewAction({ ...base, addressChanged: true }).kind).toBe('connection');
    });

    it('offers Add a language when no target language is set', () =>
    {
        expect(resolveOverviewAction({ ...base, hasTargetLanguage: false, readPages: 0, languages: [] }).kind).toBe('addLanguage');
    });

    it('offers Read my site when languages are set but nothing was read', () =>
    {
        expect(resolveOverviewAction({ ...base, readPages: 0, languages: [lang('de')] }).kind).toBe('read');
    });

    it('shows the first pass only until it has completed once', () =>
    {
        const inFlight = lang('de', { total: 10, readyTotal: 10, ready: 3, readyComplete: false });
        expect(resolveOverviewAction({ ...base, firstPassDone: false, languages: [inFlight] }).kind).toBe('readiness');
        expect(resolveOverviewAction({ ...base, firstPassDone: true, languages: [inFlight] })).toEqual({ kind: 'translating', count: 7 });
    });

    it('names a repair while cold pages are pushed again', () =>
    {
        const action = resolveOverviewAction({ ...base, languages: [lang('de', { total: 10, readyTotal: 10, ready: 8, repairing: 2 })] });
        expect(action).toEqual({ kind: 'repairing', count: 2 });
    });

    it('names a retranslation after a change, with the languages it touches', () =>
    {
        const action = resolveOverviewAction({ ...base, languages: [
            lang('de', { total: 10, readyTotal: 10, ready: 4, retranslating: 6 }),
            lang('it', { total: 10, readyTotal: 10, ready: 10, readyComplete: true }),
        ] });
        expect(action).toEqual({ kind: 'retranslating', locales: ['de'], done: 4, total: 10 });
    });

    it('reports pages that could not be delivered, with a retry, once nothing is in flight', () =>
    {
        const action = resolveOverviewAction({ ...base, languages: [lang('de', { total: 10, readyTotal: 10, ready: 8, dead: 2, readyComplete: true })] });
        expect(action).toEqual({ kind: 'failed', count: 2 });
    });

    it('keeps a dead page from holding the first pass open', () =>
    {
        const action = resolveOverviewAction({ ...base, firstPassDone: false, languages: [lang('de', { total: 10, readyTotal: 10, ready: 9, dead: 1, readyComplete: true })] });
        expect(action.kind).toBe('failed');
    });

    it('offers Translate a new language that has pages read but no rows yet', () =>
    {
        const action = resolveOverviewAction({ ...base, languages: [complete('de'), lang('it', { total: 0 })] });
        expect(action).toEqual({ kind: 'translateLanguage', locale: 'it' });
    });

    it('offers Re-read changed pages with a count', () =>
    {
        const action = resolveOverviewAction({ ...base, changedPages: 4 });
        expect(action).toEqual({ kind: 'rereadChanged', count: 4 });
    });

    it('offers Retranslate stale pages with a count', () =>
    {
        const action = resolveOverviewAction({ ...base, staleCount: 7 });
        expect(action).toEqual({ kind: 'retranslateStale', count: 7 });
    });

    it('offers Run sync now when scheduled tasks are late and content is otherwise settled', () =>
    {
        expect(resolveOverviewAction({ ...base, scheduledTasksLate: true }).kind).toBe('sync');
    });

    it('offers Preview when everything is read and translated with nothing changed', () =>
    {
        const action = resolveOverviewAction({ ...base, languages: [complete('de'), complete('it')] });
        expect(action).toEqual({ kind: 'preview', locales: ['de', 'it'] });
    });

    it('raises the allowance and hides read or retranslate while quota is paused', () =>
    {
        expect(resolveOverviewAction({ ...base, quotaPaused: true, changedPages: 5, staleCount: 5 }).kind).toBe('quota');
    });

    it('offers Try again when the status failed to load', () =>
    {
        expect(resolveOverviewAction({ ...base, statusFailed: true, changedPages: 5 }).kind).toBe('retry');
    });

    it('reads before it worries about late scheduled tasks', () =>
    {
        expect(resolveOverviewAction({ ...base, readPages: 0, scheduledTasksLate: true, languages: [lang('de')] }).kind).toBe('read');
    });
});
