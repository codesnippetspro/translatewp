import { act } from 'react';
import { createRoot } from 'react-dom/client';

jest.mock('@/shared/lib/languages', () => ({
    sortedLocales: [],
    getLanguageNameFromLocale: () => undefined,
    getCountryFlagFromLocale: () => null,
}));

import { LanguageSelect } from '@/shared/components/languageSelect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('LanguageSelect empty-catalogue honesty', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows a reload message instead of an empty dropdown when the catalogue is empty', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);

        await act(async () =>
        {
            createRoot(container).render(<LanguageSelect onChange={() => {}} placeholder='Select a language' />);
        });

        const input = container.querySelector('input[type="text"]') as HTMLInputElement;
        await act(async () =>
        {
            input.dispatchEvent(new Event('focusin', { bubbles: true }));
        });

        const dropdown = container.querySelector('.absolute.top-14');
        expect(dropdown).not.toBeNull();
        expect(dropdown?.querySelectorAll('button')).toHaveLength(0);
        expect(container.textContent).toContain('Could not load the language list. Reload the page.');
    });
});
