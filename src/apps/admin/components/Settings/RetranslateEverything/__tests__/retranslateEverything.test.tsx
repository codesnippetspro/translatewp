import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

jest.mock('@tanstack/react-query', () =>
{
    const mutate = jest.fn();

    return {
        __mutate: mutate,
        queryOptions: (options: unknown) => options,
        useQuery: () => ({ data: { pages: [], totals: { rows: 3, segments: 12 } } }),
        useMutation: () => ({ mutate, isPending: false, isError: false }),
        useQueryClient: () => ({ invalidateQueries: async () => {} }),
    };
});

import * as reactQuery from '@tanstack/react-query';
import { RetranslateEverything } from '@/apps/admin/components/Settings/RetranslateEverything';

const mockMutate = (reactQuery as unknown as { __mutate: jest.Mock }).__mutate;

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const renderComponent = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(<RetranslateEverything />);
    });
    return container;
};

const retranslateButton = (container: HTMLElement): HTMLButtonElement =>
{
    const button = Array.from(container.querySelectorAll('button'))
        .find(candidate => (candidate.textContent ?? '').trim() === 'Retranslate everything');
    expect(button).toBeDefined();
    return button as HTMLButtonElement;
};

const typeConfirm = async (container: HTMLElement, value: string) =>
{
    const input = container.querySelector('input[type="text"]') as HTMLInputElement;
    expect(input).not.toBeNull();
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
    await act(async () =>
    {
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

describe('RetranslateEverything', () =>
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
        mockMutate.mockClear();
    });

    it('shows the segment cost guard from the backend totals', async () =>
    {
        const container = await renderComponent();

        expect(container.textContent).toContain('This resends 12 segments.');
    });

    it('keeps the button disabled until the exact phrase is typed', async () =>
    {
        const container = await renderComponent();
        expect(retranslateButton(container).disabled).toBe(true);

        await typeConfirm(container, 'retranslate');
        expect(retranslateButton(container).disabled).toBe(true);

        await typeConfirm(container, 'RETRANSLATE ');
        expect(retranslateButton(container).disabled).toBe(true);

        await typeConfirm(container, 'RETRANSLATE');
        expect(retranslateButton(container).disabled).toBe(false);
    });

    it('submits the confirmation phrase on confirm', async () =>
    {
        const container = await renderComponent();
        await typeConfirm(container, 'RETRANSLATE');

        await act(async () =>
        {
            retranslateButton(container).click();
        });

        expect(mockMutate).toHaveBeenCalledWith('RETRANSLATE');
    });
});
