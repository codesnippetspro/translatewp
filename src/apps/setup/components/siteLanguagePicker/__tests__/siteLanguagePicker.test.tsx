import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SiteLanguagePicker } from '@/apps/setup/components/siteLanguagePicker';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root!.render(<SiteLanguagePicker />); });
    return container;
};

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    document.body.innerHTML = '';
});

describe('the site language on the connection line', () =>
{
    it('is the Languages step picker: a filter box with the current language chip, and picking a language stores it', async () =>
    {
        useSetupStore.setState({ defaultLanguage: 'lv', languages: ['de'] });
        const container = await render();
        const input = container.querySelector('input[aria-label="Filter languages"]') as HTMLInputElement;
        expect(input).not.toBeNull();
        expect(container.textContent).toContain('Latvian');

        await act(async () => { input.focus(); input.dispatchEvent(new FocusEvent('focus')); });
        const option = Array.from(container.querySelectorAll('button')).find(button => button.textContent?.includes('Lithuanian')) as HTMLButtonElement;
        expect(option).toBeDefined();
        await act(async () => { option.click(); });
        expect(useSetupStore.getState().defaultLanguage).toBe('lt');
    });
});
