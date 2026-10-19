import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: { pages: [], totals: { rows: 0, segments: 0 } } }),
    useMutation: () => ({ mutate: () => {}, isPending: false, isError: false }),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
}));

jest.mock('@/shared/components/connection/ConnectionPanel', () => ({
    ConnectionPanel: () => null,
}));

import { SettingsAdvanced as Settings } from '@/apps/admin/components/Settings/Advanced';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const renderSettings = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(<Settings />);
    });
    return container;
};

const resetButton = (container: HTMLElement): HTMLButtonElement =>
{
    const button = Array.from(container.querySelectorAll('button'))
        .find(candidate => (candidate.textContent ?? '').trim() === 'Reset all plugin data');
    expect(button).toBeDefined();
    return button as HTMLButtonElement;
};

const typeConfirm = async (container: HTMLElement, value: string) =>
{
    const input = Array.from(container.querySelectorAll('input[type="text"]'))
        .find(candidate =>
        {
            const label = container.querySelector(`label[for="${candidate.id}"]`);
            return (label?.textContent ?? '').includes('RESET');
        }) as HTMLInputElement;
    expect(input).toBeDefined();
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
    await act(async () =>
    {
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

describe('Danger zone reset button gate', () =>
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

    it('is disabled while the confirmation field is empty', async () =>
    {
        const container = await renderSettings();

        expect(resetButton(container).disabled).toBe(true);
    });

    it('stays disabled for anything other than the exact phrase', async () =>
    {
        const container = await renderSettings();

        await typeConfirm(container, 'reset');
        expect(resetButton(container).disabled).toBe(true);

        await typeConfirm(container, 'RESET ');
        expect(resetButton(container).disabled).toBe(true);
    });

    it('enables once the exact phrase is typed', async () =>
    {
        const container = await renderSettings();

        await typeConfirm(container, 'RESET');
        expect(resetButton(container).disabled).toBe(false);
    });
});
