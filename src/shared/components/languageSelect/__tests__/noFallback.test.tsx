import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { LanguageSelect } from '@/shared/components/languageSelect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('LanguageSelect no-defaultValue guard', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('renders placeholder and no chip when defaultValue is undefined (fails if en-us fallback is added)', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);

        await act(async () =>
        {
            createRoot(container).render(<LanguageSelect onChange={() => {}} placeholder='Select a language' />);
        });

        const input = container.querySelector('input[type="text"]') as HTMLInputElement;
        expect(input.placeholder).toBe('Select a language');
        expect(container.querySelector('.bg-translatewp-selection')).toBeNull();
    });
});
