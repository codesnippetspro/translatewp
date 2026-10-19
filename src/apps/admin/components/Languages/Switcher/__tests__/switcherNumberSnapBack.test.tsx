import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const render = async (element: React.ReactElement): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(element);
    });
    return container;
};

const setInputValue = async (input: HTMLInputElement, value: string): Promise<void> =>
{
    await act(async () =>
    {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

describe('switcher size fields', () =>
{
    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0)) {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
    });

    it('an emptied size field shows the stored value again once it loses focus', async () =>
    {
        const stored = useAdminSettingsStore.getState().settings['languages.switcher'].offset;
        const container = await render(<LanguagesSwitcher />);
        const offset = Array.from(container.querySelectorAll('input[type="number"]'))
            .find(input => (input as HTMLInputElement).value === String(stored)) as HTMLInputElement;
        expect(offset).toBeDefined();

        await setInputValue(offset, '');
        expect(useAdminSettingsStore.getState().settings['languages.switcher'].offset).toBe(stored);

        await act(async () =>
        {
            offset.dispatchEvent(new FocusEvent('blur', { bubbles: false }));
            offset.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
        });

        const after = Array.from(container.querySelectorAll('input[type="number"]'))
            .map(input => (input as HTMLInputElement).value);
        expect(after).toContain(String(stored));
        expect(after).not.toContain('');
    });
});
