import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';
import type { ScanPollState } from '@/shared/hooks/useContextScan';

jest.mock('@wordpress/i18n', () => ({
    ...(jest.requireActual('@wordpress/i18n') as object),
    __: (text: string) => `T:${text}`,
    _n: (single: string, plural: string, count: number) => `T:${count === 1 ? single : plural}`,
}));

const mockSettingsState = {
    settings: {
        'translations.context': { text_context: '', last_scan_time: 0 },
    },
    setSetting: jest.fn(),
};

jest.mock('@/apps/admin/stores/settingsStore', () => ({
    useAdminSettingsStore: (selector: (state: unknown) => unknown) => selector(mockSettingsState),
}));

jest.mock('@tanstack/react-query', () => ({
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: () => {} }),
}));

const mockScan: { state: ScanPollState; startScan: () => void } = { state: { phase: 'idle' }, startScan: () => {} };
jest.mock('@/shared/hooks/useContextScan', () => ({
    useContextScan: () => mockScan,
    scanDoneMessage: () => '',
    scanProgressMessage: () => '',
}));

jest.mock('@/shared/api/setup', () => ({ applyScanLexicon: () => Promise.resolve({ added: 0 }) }));

import { TranslationsContext } from '@/apps/admin/components/Translations/Context';

describe('Context scan row', () =>
{
    let container: HTMLDivElement;
    let root: Root;

    beforeEach(() =>
    {
        (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
        mockSettingsState.setSetting.mockClear();
        mockScan.state = { phase: 'idle' };
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        act(() => root.render(<TranslationsContext />));
    });

    afterEach(() =>
    {
        act(() => root.unmount());
        container.remove();
    });

    const scanButton = () => Array.from(container.querySelectorAll('button'))
        .find(button => button.textContent?.includes('Scan website')) as HTMLButtonElement;

    test('the scan row sits under the context text, with its label and the button on one line', () =>
    {
        const box = container.querySelector('[data-translation-context]') as HTMLElement;
        const textarea = box.querySelector('textarea') as HTMLTextAreaElement;
        const row = scanButton().parentElement as HTMLElement;

        expect(textarea.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(row.className).toContain('justify-between');
        expect(scanButton().className).toContain('is-secondary');
    });

    test('the scan labels are translatable', () =>
    {
        expect(scanButton().textContent).toBe('T:Scan website');
        expect(container.textContent).toContain('T:Site context');
        expect(container.textContent).toContain('T:Scan the site for context');
        expect(container.textContent).toContain('T:No scan yet');
    });
    test('restored suggestions leave the owner context and style untouched', () =>
    {
        mockSettingsState.settings['translations.context'].text_context = 'Owner edited context';
        mockScan.state = {
            phase: 'done', restored: true, context: 'Old stored context', applied: true, pages: 0,
            result: { context: 'Old stored context', style: 'technical', tone: 'professional' },
            lexiconSuggestions: [{ term: 'Orbital', description: 'Product name', category: 'Brand' }],
        };
        act(() => root.render(<TranslationsContext />));
        expect(container.querySelector('textarea')?.value).toBe('Owner edited context');
        expect(mockSettingsState.setSetting).not.toHaveBeenCalled();
        expect(container.textContent).toContain('Orbital');
        expect(container.textContent).toContain('Nothing is added until you choose. Unchecked terms are dismissed.');
        expect(container.querySelector('[role="status"]')).toBeNull();
    });

});
