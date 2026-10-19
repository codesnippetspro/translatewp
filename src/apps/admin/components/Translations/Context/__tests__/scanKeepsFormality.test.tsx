import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { jest } from '@jest/globals';

jest.mock('@tanstack/react-query', () => ({
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: () => {} }),
}));

const mockScan = {
    state: { phase: 'done', lexiconSuggestions: [], result: { context: 'A dance studio.', style: 'neutral', tone: 'formal', formality: 5, creativity: 5 } },
    startScan: () => {},
};
jest.mock('@/shared/hooks/useContextScan', () => ({
    useContextScan: () => mockScan,
    scanDoneMessage: () => '',
    scanProgressMessage: () => '',
}));

jest.mock('@/shared/api/setup', () => ({ applyScanLexicon: () => Promise.resolve({ added: 0 }) }));

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { TranslationsContext } from '@/apps/admin/components/Translations/Context';

it('a scan proposal keeps its own formality when it also changes the tone', async () =>
{
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
    const store = useAdminSettingsStore;
    const settings = JSON.parse(JSON.stringify(store.getState().settings));
    settings['translations.style'] = { ...settings['translations.style'], type: 'neutral', tone: 'friendly', formality: 5, creativity: 5 };
    store.setState({ isInitialized: false } as never);
    store.getState().initializeSettings(settings);

    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    await act(async () => root.render(<TranslationsContext />));

    expect(store.getState().settings['translations.style'].tone).toBe('formal');
    expect(store.getState().settings['translations.style'].formality).toBe(5);

    act(() => root.unmount());
    container.remove();
});
