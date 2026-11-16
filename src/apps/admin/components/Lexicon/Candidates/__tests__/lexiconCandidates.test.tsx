import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { SiteScanSuggestions } from '@/shared/components/siteScanSuggestions';
import { LexiconCandidates, scanLine } from '@/apps/admin/components/Lexicon/Candidates';
import type { LexiconCandidate, LexiconScan } from '@/shared/lib/lexiconCandidates';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = {
    getLexiconScan: jest.fn(),
    getLexiconCandidates: jest.fn(),
    startLexiconScan: jest.fn(),
    actOnLexiconCandidate: jest.fn(),
    getConnectStatus: jest.fn(),
    getScanContextStatus: jest.fn(),
    applyScanLexicon: jest.fn(),
    createWordPressLexiconTerm: jest.fn(),
};

jest.mock('@/shared/api/admin', () => ({
    getLexiconScan: (...args: unknown[]) => mockApi.getLexiconScan(...args),
    getLexiconCandidates: (...args: unknown[]) => mockApi.getLexiconCandidates(...args),
    startLexiconScan: (...args: unknown[]) => mockApi.startLexiconScan(...args),
    actOnLexiconCandidate: (...args: unknown[]) => mockApi.actOnLexiconCandidate(...args),
}));
jest.mock('@/shared/api/setup', () => ({
    getScanContextStatus: (...args: unknown[]) => mockApi.getScanContextStatus(...args),
    applyScanLexicon: (...args: unknown[]) => mockApi.applyScanLexicon(...args),
}));
jest.mock('@/shared/api/wordpress', () => ({
    createWordPressLexiconTerm: (...args: unknown[]) => mockApi.createWordPressLexiconTerm(...args),
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

const mounts: { root: Root; client: QueryClient }[] = [];

const render = async (withSettings = false): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const root = createRoot(container);
    mounts.push({ root, client: queryClient });
    await act(async () =>
    {
        root.render(<QueryClientProvider client={queryClient}><LexiconCandidates />{withSettings && <div data-settings><SiteScanSuggestions /></div>}</QueryClientProvider>);
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

const buttonNamed = (container: HTMLElement, text: string) => [...container.querySelectorAll('button')].find(b => b.textContent?.trim() === text) as HTMLButtonElement | undefined;

const dismissCandidate = async (container: HTMLElement, action: string) =>
{
    await act(async () => { buttonNamed(container, 'Dismiss')?.click(); });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    const item = buttonNamed(document.body, action);
    expect(item).toBeDefined();
    await act(async () => { item!.click(); });
};

describe('lexicon candidates', () =>
{
    beforeEach(() =>
    {
        Object.values(mockApi).forEach(fn => fn.mockReset());
        mockApi.getConnectStatus.mockResolvedValue({ connected: true, pro: { entitled: true, active: false } });
        mockApi.getScanContextStatus.mockResolvedValue({ status: 'done', lexiconSuggestions: [] });
        mockApi.createWordPressLexiconTerm.mockResolvedValue({ id: 1 });
        mockApi.applyScanLexicon.mockResolvedValue({ added: 0 });
        mockApi.getLexiconScan.mockResolvedValue({ scan: null });
        mockApi.getLexiconCandidates.mockResolvedValue({ candidates: [] });
    });
    afterEach(async () =>
    {
        await act(async () => { mounts.splice(0).forEach(({ root, client }) => { root.unmount(); client.clear(); }); });
        document.body.innerHTML = '';
    });

    it('adds only checked rows once when both sources propose the same term', async () =>
    {
        let site = ['beta', 'Tidewell'].map(term => ({ term, description: 'Site instruction', category: 'Brand' }));
        let reviews = [candidate({ sourceText: 'Beta', reason: 'Rich instruction' }), candidate({ id: 'unselected', sourceText: 'Fernwick' })];
        mockApi.getScanContextStatus.mockImplementation(async () => ({ status: 'done', lexiconSuggestions: site }));
        mockApi.getLexiconCandidates.mockImplementation(async () => ({ candidates: reviews }));
        mockApi.applyScanLexicon.mockImplementation(async (terms: string[], resolution: string) => {
            site = site.filter(proposal => !terms.includes(proposal.term));
            return { added: resolution === 'add' ? terms.length : 0 };
        });
        mockApi.actOnLexiconCandidate.mockImplementation(async (id: string) => {
            reviews = reviews.filter(review => review.id !== id);
            return { candidate: candidate() };
        });
        const container = await render();
        expect(container.querySelectorAll('[data-candidate]')).toHaveLength(3);
        expect(container.querySelectorAll('[data-candidate] label')[0].textContent).toBe('Beta');
        await act(async () => { buttonNamed(container, 'Select none')!.click(); });
        for (const row of Array.from(container.querySelectorAll('[data-candidate]')))
        {
            if (['Beta', 'Tidewell'].includes(row.querySelector('label')!.textContent!))
            {
                await act(async () => { row.querySelector<HTMLInputElement>('input')!.click(); });
            }
        }
        await act(async () => { buttonNamed(container, 'Add selected (2)')!.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.createWordPressLexiconTerm.mock.calls).toEqual([['Beta', 'Rich instruction', ['de'], [], []]]);
        expect(mockApi.applyScanLexicon.mock.calls).toEqual([[['beta'], 'dismiss'], [['Tidewell'], 'add']]);
        expect(mockApi.actOnLexiconCandidate.mock.calls).toEqual([[candidate().id, 'add']]);
        expect(container.querySelectorAll('[data-candidate]')).toHaveLength(1);
        expect(container.querySelector('[data-candidate] label')?.textContent).toBe('Fernwick');
    });

    it('dismisses only named site rows and shares the remaining list with unchanged Settings controls', async () =>
    {
        let site = ['beta', 'Tidewell', 'Fernwick'].map(term => ({ term, description: 'Saved instruction', category: 'Brand' }));
        mockApi.getScanContextStatus.mockImplementation(async () => ({ status: 'done', lexiconSuggestions: site }));
        mockApi.getLexiconCandidates.mockImplementation(async () => ({ candidates: [candidate({ sourceText: 'Beta' })] }));
        mockApi.applyScanLexicon.mockImplementation(async (terms: string[], resolution?: string) => {
            site = resolution ? site.filter(proposal => !terms.includes(proposal.term)) : [];
            return { added: 0 };
        });
        const container = await render(true);
        const inbox = container.querySelector('#suggested-terms-results')!.closest('section')!;
        const settings = container.querySelector<HTMLElement>('[data-settings]')!;
        await act(async () => { buttonNamed(inbox, 'Select none')!.click(); });
        const tidewell = Array.from(inbox.querySelectorAll('[data-candidate]')).find(row => row.querySelector('label')!.textContent === 'Tidewell')!;
        await act(async () => { tidewell.querySelector<HTMLInputElement>('input')!.click(); });
        await act(async () => { buttonNamed(inbox, 'Dismiss selected')!.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        const dismiss = Array.from(document.querySelectorAll<HTMLButtonElement>('[role=menuitem]')).find(item => item.textContent?.trim() === 'Dismiss')!;
        await act(async () => { dismiss.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.applyScanLexicon.mock.calls).toEqual([[['Tidewell'], 'dismiss']]);
        expect(site.map(proposal => proposal.term)).toEqual(['beta', 'Fernwick']);
        expect(settings.querySelectorAll('input[type=checkbox]')).toHaveLength(2);
        expect(buttonNamed(settings, 'Add 2 selected terms')).toBeDefined();
        const fernwick = Array.from(inbox.querySelectorAll('[data-candidate]')).find(row => row.querySelector('label')!.textContent === 'Fernwick')!;
        const rowDismiss = buttonNamed(fernwick, 'Dismiss')!;
        expect(rowDismiss.hasAttribute('aria-haspopup')).toBe(false);
        await act(async () => { rowDismiss.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.applyScanLexicon).toHaveBeenLastCalledWith(['Fernwick'], 'dismiss');
        expect(buttonNamed(settings, 'Add 1 selected term')).toBeDefined();
        await act(async () => { buttonNamed(settings, 'Dismiss all')!.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.applyScanLexicon.mock.calls.at(-1)).toEqual([[]]);
        expect(settings.querySelector('[data-lexicon-suggestions]')).toBeNull();
        expect(inbox.querySelectorAll('[data-candidate]')).toHaveLength(1);
        expect(inbox.querySelector('[data-candidate] label')?.textContent).toBe('Beta');
        expect(mockApi.createWordPressLexiconTerm).not.toHaveBeenCalled();
        expect(mockApi.actOnLexiconCandidate).not.toHaveBeenCalled();
    });

    it('a free site sees the panel locked and the scan cannot start', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue({ connected: true, pro: { entitled: false, active: false } });
        const container = await render();

        expect(container.textContent).toContain('Suggested terms');
        expect(mockApi.getLexiconScan).not.toHaveBeenCalled();
        expect(buttonNamed(container, 'Find suggestions')).toBeUndefined();
        expect(container.querySelector('[data-twp-unlock="suggested terms"]')).not.toBeNull();
        expect(container.querySelector('.blur-\\[3px\\]')).toBeNull();
    });

    it('the scan line says what the scan is doing in plain words', () =>
    {
        expect(scanLine(null)).toBe('No scan has run yet.');
        expect(scanLine(scan('running', { progress: { stage: 'judging', records: 120, candidates: 7 } }))).toBe('Reviewing 7 terms found in 120 translations.');
        expect(scanLine(scan('done', { summary: { records: 120, proposed: 3 } }))).toBe('The scan read 120 translations and proposed 3 terms.');
        expect(scanLine(scan('done', { summary: { records: 5, proposed: 0 } }))).toBe('The scan read 5 translations and proposed 0 terms.');
        expect(scanLine(scan('failed'))).toMatch(/could not finish/);
    });

    it('pressing Find candidates starts a scan and the button waits while it runs', async () =>
    {
        mockApi.startLexiconScan.mockResolvedValue({ scan: scan('queued'), started: true });
        const container = await render();
        expect(container.textContent).toContain('0 suggestions to review.');

        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('running', { progress: { stage: 'mining', records: 40 } }) });
        await act(async () => { buttonNamed(container, 'Find suggestions')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(mockApi.startLexiconScan).toHaveBeenCalledTimes(1);
        expect(container.textContent).toContain('Reading 40 stored translations');
        expect(buttonNamed(container, 'Find suggestions')?.disabled).toBe(true);
    });

    it('a refused start explains the hourly limit', async () =>
    {
        mockApi.startLexiconScan.mockRejectedValue({ code: 'remote_error', data: { status: 429, payload: { code: 'rate_limited', retryAfterSeconds: 1287 } } });
        const container = await render();

        await act(async () => { buttonNamed(container, 'Find suggestions')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(container.querySelector('[role=alert]')?.textContent).toBe('You can scan again in about 22 minutes.');
    });

    it.each([
        [undefined, 'A scan ran for this site within the hour. Try again later.'],
        [0, 'You can scan again in about 1 minute.'],
        [1, 'You can scan again in about 1 minute.'],
        [61, 'You can scan again in about 2 minutes.'],
    ])('explains the retry window for %s seconds', async (retryAfterSeconds, expected) =>
    {
        mockApi.startLexiconScan.mockRejectedValue({ code: 'remote_error', data: {
            status: 429, payload: { code: 'rate_limited', retryAfterSeconds },
        } });
        const container = await render();
        await act(async () => { buttonNamed(container, 'Find suggestions')!.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(container.querySelector('[role=alert]')?.textContent).toBe(expected);
    });

    it('shows a plain correction reason instead of the service reason code', async () =>
    {
        mockApi.getLexiconCandidates.mockResolvedValue({ candidates: [candidate({ reason: 'wrong. sense: room' })] });
        const container = await render();
        const row = container.querySelector('[data-candidate]')!;
        expect(row.textContent).toContain('A translation may need correcting. sense: room');
        expect(row.textContent).not.toContain('wrong.');
    });

    it('reports the current eligible count instead of the original scan total', async () =>
    {
        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('done', { summary: { records: 120, proposed: 14 } }) });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({
            candidates: status === 'new' ? [candidate(), candidate({ id: 'second', sourceText: 'Quillforge' })] : [],
        }));
        const container = await render();
        expect(container.textContent).toContain('2 suggestions to review.');
        expect(container.textContent).not.toContain('proposed 14 terms');
    });

    it('names each decision for its term and associates the Details control with its own panel', async () =>
    {
        mockApi.getLexiconCandidates.mockResolvedValue({ candidates: [
            candidate(), candidate({ id: 'second', sourceText: 'Quillforge' }),
        ] });
        const container = await render();
        const panels = new Set<string>();
        for (const row of Array.from(container.querySelectorAll<HTMLElement>('[data-candidate]')))
        {
            const term = row.dataset.candidate === 'second' ? 'Quillforge' : 'Hall';
            const input = row.querySelector('input[type=checkbox]')!;
            expect(document.getElementById(input.getAttribute('aria-labelledby')!)?.textContent).toBe(term);
            expect(buttonNamed(row, 'Dismiss')?.getAttribute('aria-label')).toBe(`Dismiss ${term}`);
            const toggle = buttonNamed(row, 'Details')!;
            expect(toggle.getAttribute('aria-label')).toBe(`Details for ${term}`);
            const panel = document.getElementById(toggle.getAttribute('aria-controls')!)!;
            expect(row.contains(panel)).toBe(true);
            expect(panel.hidden).toBe(true);
            panels.add(panel.id);
            await act(async () => { toggle.click(); });
            expect(panel.hidden).toBe(false);
            expect(toggle.getAttribute('aria-expanded')).toBe('true');
            await act(async () => { toggle.click(); });
            expect(panel.hidden).toBe(true);
        }
        expect(panels.size).toBe(2);
        expect(mockApi.actOnLexiconCandidate).not.toHaveBeenCalled();
    });

    it('closes the Dismiss menu on Escape and returns focus without taking an action', async () =>
    {
        mockApi.getLexiconCandidates.mockResolvedValue({ candidates: [candidate()] });
        const container = await render();
        const toggle = buttonNamed(container, 'Dismiss')!;
        await act(async () => { toggle.focus(); });
        await act(async () => { toggle.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        const skip = buttonNamed(document.body, 'Skip this scan')!;
        expect(skip).toBeDefined();
        await act(async () => { skip.focus(); });
        await act(async () => { skip.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true, cancelable: true })); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(buttonNamed(document.body, 'Skip this scan')).toBeUndefined();
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(toggle);
        expect(mockApi.actOnLexiconCandidate).not.toHaveBeenCalled();
    });

    it('a proposal shows its term, reason, confidence, renderings and evidence, and every action reaches the service', async () =>
    {
        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('done', { summary: { records: 10, proposed: 1 } }) });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'new' ? [candidate()] : [] }));
        mockApi.actOnLexiconCandidate.mockResolvedValue({ candidate: candidate({ status: 'skipped' }) });
        const container = await render();

        const row = container.querySelector('[data-candidate]') as HTMLElement;
        expect(row.textContent).toContain('Hall');
        expect(row.textContent).toContain('Translations use different terms. sense: room');
        expect(row.textContent).toContain('82% sure');
        expect(row.textContent).toContain('German:');
        expect(row.textContent).toContain('Gras');
        expect(row.textContent).toContain('proposed Saal');
        expect(row.textContent).toContain('The hall seats forty.');
        expect(row.querySelector('a[href="https://example.test/rooms"]')).not.toBeNull();

        await act(async () => { buttonNamed(container, 'Add selected (1)')!.click(); });
        expect(mockApi.createWordPressLexiconTerm).toHaveBeenCalledWith('Hall', 'inconsistent. sense: room', ['de'], [], []);

        await dismissCandidate(row, 'Skip this scan');
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(candidate().id, 'skip');

        await dismissCandidate(container, 'Never suggest on this site');
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(mockApi.actOnLexiconCandidate).toHaveBeenLastCalledWith(candidate().id, 'never');
    });

    it('keeps review details collapsed while the term and decision actions stay available', async () =>
    {
        mockApi.getLexiconCandidates.mockResolvedValue({ candidates: [candidate()] });
        const container = await render();
        const row = container.querySelector('[data-candidate]') as HTMLElement;
        const details = row.querySelector('[data-candidate-details]') as HTMLElement;

        expect(details).not.toBeNull();
        expect(details.hidden).toBe(true);
        expect(buttonNamed(row, 'Details')?.getAttribute('aria-expanded')).toBe('false');
        expect(details.textContent).toContain('Translations use different terms. sense: room');
        expect(details.textContent).toContain('proposed Saal');
        expect(details.textContent).toContain('The hall seats forty.');
        expect(buttonNamed(container, 'Add selected (1)')?.closest('[data-candidate-details]')).toBeNull();
        expect(buttonNamed(row, 'Dismiss')?.closest('[data-candidate-details]')).toBeNull();
        expect(buttonNamed(row, 'Never suggest on this site')).toBeUndefined();

        await act(async () => { buttonNamed(row, 'Details')?.click(); });
        expect(details.hidden).toBe(false);
        expect(details.querySelector('a')?.getAttribute('href')).toBe('https://example.test/rooms');
        await act(async () => { buttonNamed(container, 'Add selected (1)')!.click(); });
        expect(mockApi.createWordPressLexiconTerm).toHaveBeenCalledWith('Hall', candidate().reason, ['de'], [], []);
        expect(mockApi.actOnLexiconCandidate).toHaveBeenCalledWith(candidate().id, 'add');
        expect(mockApi.startLexiconScan).not.toHaveBeenCalled();
    });

    it('paginates ten suggestions and clamps after removing the last item on the last page', async () =>
    {
        let candidates = Array.from({ length: 11 }, (_, index) => candidate({
            id: `candidate-${index}`, sourceText: `Term ${index + 1}`,
        }));
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({
            candidates: status === 'new' ? candidates : [],
        }));
        mockApi.actOnLexiconCandidate.mockImplementation(async (id: string) =>
        {
            candidates = candidates.filter(item => item.id !== id);
            return { candidate: candidate({ status: 'skipped' }) };
        });
        const container = await render();

        expect(container.querySelectorAll('[data-candidate]')).toHaveLength(10);
        expect(container.textContent).toContain('Page 1 of 2 (11 suggestions)');
        expect(buttonNamed(container, 'Previous')?.disabled).toBe(true);
        await act(async () => { buttonNamed(container, 'Next')?.click(); });
        expect(container.querySelectorAll('[data-candidate]')).toHaveLength(1);
        expect(container.querySelector('[data-candidate]')?.textContent).toContain('Term 11');
        expect(buttonNamed(container, 'Next')?.disabled).toBe(true);

        await dismissCandidate(container, 'Skip this scan');
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(container.querySelectorAll('[data-candidate]')).toHaveLength(10);
        expect(container.querySelector('[data-candidate="candidate-10"]')).toBeNull();
        expect(mockApi.startLexiconScan).not.toHaveBeenCalled();
    });

    it('pages dismissed suggestions independently of active suggestions', async () =>
    {
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({
            candidates: Array.from({ length: 11 }, (_, index) => candidate({
                id: `${status}-${index}`, sourceText: `${status} term ${index + 1}`,
                status: status === 'never' ? 'never' : 'new',
            })),
        }));
        const container = await render();
        await act(async () => { buttonNamed(container, 'Next')?.click(); });
        await act(async () => { buttonNamed(container, 'Dismissed suggestions')?.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(container.querySelectorAll('[data-candidate^="new-"]')).toHaveLength(1);
        expect(container.querySelector('[data-candidate="new-10"]')).not.toBeNull();
        expect(container.querySelectorAll('[data-candidate^="never-"]')).toHaveLength(10);
        const nextButtons = [...container.querySelectorAll('button')].filter(b => b.textContent === 'Next');
        await act(async () => { nextButtons[1].click(); });
        expect(container.querySelectorAll('[data-candidate^="never-"]')).toHaveLength(1);
        expect(container.querySelector('[data-candidate="never-10"]')).not.toBeNull();
        expect(mockApi.actOnLexiconCandidate).not.toHaveBeenCalled();
        expect(mockApi.startLexiconScan).not.toHaveBeenCalled();
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
        const container = await render();

        const row = container.querySelector('[data-candidate]') as HTMLElement;
        const lines = [...row.querySelectorAll('[data-language]')].map(line => [line.getAttribute('data-language'), line.textContent]);
        expect(lines).toEqual([['de', expect.not.stringContaining('Dismissed')], ['fr', expect.stringContaining('Dismissed')]]);

        await act(async () => { buttonNamed(container, 'Add selected (1)')!.click(); });
        expect(mockApi.createWordPressLexiconTerm).toHaveBeenCalledWith('Hall', partly.reason, ['de'], [], []);
    });

    it('says once that a term was not seen in any translation instead of one line per language', async () =>
    {
        const unseen = candidate({ languages: { de: { rendering: null, proposed: null, status: 'new' }, fr: { rendering: null, proposed: null, status: 'new' }, it: { rendering: null, proposed: null, status: 'new' } } });
        mockApi.getLexiconScan.mockResolvedValue({ scan: scan('done', { summary: { records: 10, proposed: 1 } }) });
        mockApi.getLexiconCandidates.mockImplementation(async (status?: string) => ({ candidates: status === 'new' ? [unseen] : [] }));
        const container = await render();

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
