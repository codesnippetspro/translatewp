import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { LanguageSelect } from '@/shared/components/languageSelect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const DEBOUNCE_WAIT = 260;

describe('LanguageSelect no-match honesty', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows a no-match message instead of an empty dropdown when a query matches nothing', async () =>
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

        const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')!.set!;
        await act(async () =>
        {
            nativeSetter.call(input, 'zzzzzzzzzz');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, DEBOUNCE_WAIT));
        });

        const dropdown = container.querySelector('.absolute.top-14');
        expect(dropdown).not.toBeNull();
        expect(dropdown?.querySelectorAll('button')).toHaveLength(0);
        expect(container.textContent).toContain('No languages match your search.');
        expect(container.textContent).not.toContain('Could not load the language list');
    });
});
