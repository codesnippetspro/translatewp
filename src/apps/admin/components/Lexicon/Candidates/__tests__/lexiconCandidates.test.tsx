import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { LexiconCandidates, scanLine, type CandidatePrefill } from '@/apps/admin/components/Lexicon/Candidates';
import type { LexiconCandidate, LexiconScan } from '@/shared/lib/lexiconCandidates';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = {
    getLexiconScan: jest.fn(),
    getLexiconCandidates: jest.fn(),
    startLexiconScan: jest.fn(),
    actOnLexiconCandidate: jest.fn(),
    getConnectStatus: jest.fn(),
};

jest.mock('@/shared/api/admin', () => ({
    getLexiconScan: (...args: unknown[]) => mockApi.getLexiconScan(...args),
    getLexiconCandidates: (...args: unknown[]) => mockApi.getLexiconCandidates(...args),
    startLexiconScan: (...args: unknown[]) => mockApi.startLexiconScan(...args),
    actOnLexiconCandidate: (...args: unknown[]) => mockApi.actOnLexiconCandidate(...args),
}));
jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
}));

const scan = (state: LexiconScan['state'], extra: Partial<LexiconScan> = {}): LexiconScan => ({ id: 's1', state, startedAt: '2026-09-14T12:00:00Z', finishedAt: null, progress: {}, summary: {}, ...extra });
const candidate = (overrides: Partial<LexiconCandidate> = {}): LexiconCandidate => ({
    id: '00000000-0000-4000-8000-00000000cccc', sourceLocale: 'en', normalizedSource: 'hall', sourceText: 'Hall',
    languages: { de: { rendering: 'Gras', proposed: 'Saal', status: 'new' } }, confidence: 0.82,
    evidence: [{ url: 'https://example.test/rooms', sentence: 'The hall seats forty.' }], reason: 'inconsistent. sense: room', status: 'new', scanId: 's1', updatedAt: '2026-09-14T12:00:00Z', ...overrides,
});

const render = async (onAdd: (prefill: CandidatePrefill) => void = () => undefined): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(<QueryClientProvider client={queryClient}><LexiconCandidates onAdd={onAdd} /></QueryClientProvider>);
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

const buttonNamed = (container: HTMLElement, text: string) => [...container.querySelectorAll('button')].find(b => b.textContent?.trim() === text) as HTMLButtonElement | undefined;

describe('lexicon candidates', () =>
{
    beforeEach(() =>
    {
        Object.values(mockApi).forEach(fn => fn.mockReset());
        mockApi.getConnectStatus.mockResolvedValue({ connected: true, pro: { entitled: true, active: false } });
        mockApi.getLexiconScan.mockResolvedValue({ scan: null });
        mockApi.getLexiconCandidates.mockResolvedValue({ candidates: [] });
    });
    afterEach(() => { document.body.innerHTML = ''; });

    it('a free site sees the panel locked and the scan cannot start', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue({ connected: true, pro: { entitled: false, active: false } });
        const container = await render();

        expect(container.textContent).toContain('Suggested terms');
        expect(mockApi.getLexiconScan).not.toHaveBeenCalled();
        expect(buttonNamed(container, 'Find candidates')).toBeUndefined();
        expect(container.querySelector('[data-twp-unlock="suggested terms"]')).not.toBeNull();
        expect(container.querySelector('.blur-\\[3px\\]')).toBeNull();
    });

    it('the scan line says what the scan is doing in plain words', () =>
    {
        expect(scanLine(null)).toBe('No scan has run yet.');
        expect(scanLine(scan('running', { progress: { stage: 'judging', records: 120, candidates: 7 } }))).toBe('Reviewing 7 terms found in 120 translations.');
        expect(scanLine(scan('done', { summary: { records: 120, proposed: 3 } }))).toBe('The scan read 120 translations and proposed 3 terms.');
        expect(scanLine(scan('done', { summary: { records: 5, proposed: 0 } }))).toMatch(/found nothing to propose/);
        expect(scanLine(scan('failed'))).toMatch(/could not finish/);
    });

    it('pressing Find candidates starts a scan and the button waits while it runs', async () =>
    {
        mockApi.startLexiconScan.mockResolvedValue({ scan: scan('queued'), started: true });
        const container = await render();
        expect(container.textContent).toContain('No scan has run yet.');

        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('running', { progress: { stage: 'mining', records: 40 } }) });
        await act(async () => { buttonNamed(container, 'Find candidates')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(mockApi.startLexiconScan).toHaveBeenCalledTimes(1);
        expect(container.textContent).toContain('Reading 40 stored translations');
        expect(buttonNamed(container, 'Scanning…')?.disabled).toBe(true);
    });

    it('a refused start explains the hourly limit', async () =>
    {
        mockApi.startLexiconScan.mockRejectedValue({ code: 'rate_limited', message: 'A scan ran for this site within the hour' });
        const container = await render();

        await act(async () => { buttonNamed(container, 'Find candidates')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(container.querySelector('[role=alert]')?.textContent).toContain('within the hour');
    });

    it('a proposal shows its term, reason, confidence, renderings and evidence, and every action reaches the service', async () =>
    {
        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('done', { summary: { records: 10, proposed: 1 } }) });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'new' ? [candidate()] : [] }));
        mockApi.actOnLexiconCandidate.mockResolvedValue({ candidate: candidate({ status: 'skipped' }) });
        const added: CandidatePrefill[] = [];
        const container = await render(prefill => added.push(prefill));

        const row = container.querySelector('[data-candidate]') as HTMLElement;
        expect(row.textContent).toContain('Hall');
        expect(row.textContent).toContain('inconsistent. sense: room');
        expect(row.textContent).toContain('82% sure');
        expect(row.textContent).toContain('German:');
        expect(row.textContent).toContain('Gras');
        expect(row.textContent).toContain('proposed Saal');
        expect(row.textContent).toContain('The hall seats forty.');
        expect(row.querySelector('a[href="https://example.test/rooms"]')).not.toBeNull();

        await act(async () => { buttonNamed(row, 'Add to Lexicon')?.click(); });
        expect(added).toEqual([{ candidateId: candidate().id, term: 'Hall', description: 'inconsistent. sense: room', languages: ['de'] }]);

        await act(async () => { buttonNamed(row, 'Skip this scan')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(candidate().id, 'skip');

        await act(async () => { buttonNamed(container, 'Never suggest on this site')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(candidate().id, 'never');
    });

    it('dismissed suggestions can be shown and restored', async () =>
    {
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'never' ? [candidate({ status: 'never' })] : [] }));
        mockApi.actOnLexiconCandidate.mockResolvedValue({ candidate: candidate() });
        const container = await render();

        await act(async () => { buttonNamed(container, 'Dismissed suggestions')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        const restore = buttonNamed(container, 'Restore suggestion');
        expect(restore).toBeDefined();
        await act(async () => { restore?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(candidate().id, 'restore');
        expect(buttonNamed(container, 'Restore German')).toBeUndefined();
    });

    it('a dismissed suggestion offers a restore per target language', async () =>
    {
        const dismissed = candidate({
            status: 'never',
            languages: { de: { rendering: 'Gras', proposed: 'Saal', status: 'new' }, fr: { rendering: 'salle', proposed: 'salle', status: 'new' } },
        });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'never' ? [dismissed] : [] }));
        mockApi.actOnLexiconCandidate.mockResolvedValue({ candidate: candidate() });
        const container = await render();

        await act(async () => { buttonNamed(container, 'Dismissed suggestions')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(buttonNamed(container, 'Restore German')).toBeDefined();
        const restoreFrench = buttonNamed(container, 'Restore French');
        expect(restoreFrench).toBeDefined();
        await act(async () => { restoreFrench?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(dismissed.id, 'restore', 'fr');

        await act(async () => { buttonNamed(container, 'Restore suggestion')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(dismissed.id, 'restore');
    });

    it('a language dismissed on its own is marked in the list and left out of the Add to Lexicon prefill', async () =>
    {
        const partly = candidate({ languages: { de: { rendering: 'Gras', proposed: 'Saal', status: 'new' }, fr: { rendering: 'salle', proposed: 'salle', status: 'never' } } });
        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('done', { summary: { records: 10, proposed: 1 } }) });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'new' ? [partly] : [] }));
        const added: CandidatePrefill[] = [];
        const container = await render(prefill => added.push(prefill));

        const row = container.querySelector('[data-candidate]') as HTMLElement;
        const lines = [...row.querySelectorAll('[data-language]')].map(line => [line.getAttribute('data-language'), line.textContent]);
        expect(lines).toEqual([['de', expect.not.stringContaining('Dismissed')], ['fr', expect.stringContaining('Dismissed')]]);

        await act(async () => { buttonNamed(row, 'Add to Lexicon')?.click(); });
        expect(added).toEqual([{ candidateId: partly.id, term: 'Hall', description: 'inconsistent. sense: room', languages: ['de'] }]);
    });

    it('says once that a term was not seen in any translation instead of one line per language', async () =>
    {
        const unseen = candidate({ languages: { de: { rendering: null, proposed: null, status: 'new' }, fr: { rendering: null, proposed: null, status: 'new' }, it: { rendering: null, proposed: null, status: 'new' } } });
        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('done', { summary: { records: 10, proposed: 1 } }) });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'new' ? [unseen] : [] }));
        const container = await render(() => undefined);

        const row = container.querySelector('[data-candidate]') as HTMLElement;
        expect(row.querySelectorAll('[data-language]')).toHaveLength(0);
        expect(row.querySelector('[data-not-seen]')?.textContent).toBe('Not seen in any translation yet.');
    });

    it('a suggestion with one dismissed language offers that language and the whole restore in the dismissed view', async () =>
    {
        const partly = candidate({ languages: { de: { rendering: 'Gras', proposed: 'Saal', status: 'new' }, fr: { rendering: 'salle', proposed: 'salle', status: 'never' } } });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'never' ? [partly] : [] }));
        mockApi.actOnLexiconCandidate.mockResolvedValue({ candidate: candidate() });
        const container = await render();

        await act(async () => { buttonNamed(container, 'Dismissed suggestions')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(buttonNamed(container, 'Restore suggestion')).toBeDefined();
        expect(buttonNamed(container, 'Restore German')).toBeUndefined();
        await act(async () => { buttonNamed(container, 'Restore French')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(partly.id, 'restore', 'fr');
    });
});
