import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ConfirmDialog, type ConfirmDialogContent } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const Harness = ({ content, onResult }: { content: ConfirmDialogContent; onResult: (confirmed: boolean) => void }) =>
{
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    const openDialog = async () =>
    {
        onResult(await requestConfirmation(content));
    };

    return (
        <>
            <button type="button" id="trigger" onClick={() => void openDialog()}>Open</button>
            <ConfirmDialog {...confirmDialogProps} />
        </>
    );
};

const findButtonByText = (text: string): HTMLButtonElement =>
    Array.from(document.querySelectorAll('button')).find((button) => button.textContent === text) as HTMLButtonElement;

const clickButton = async (button: HTMLButtonElement) =>
{
    await act(async () => { button.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
};

const mount = async (ui: React.ReactElement) =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    await act(async () => { root.render(ui); });

    // The @wordpress/components Modal must be closed through a state change
    // before the tree unmounts; tearing it down while still open detaches the
    // portal node twice.
    const unmount = async () =>
    {
        const cancel = findButtonByText('Cancel');
        if (cancel)
        {
            await clickButton(cancel);
        }

        await act(async () => { root.unmount(); });
        container.remove();
    };

    return { container, unmount };
};

const openDialog = async (container: HTMLElement) =>
{
    await clickButton(container.querySelector('#trigger') as HTMLButtonElement);
};

describe('ConfirmDialog', () =>
{
    it('renders a modal dialog that names the object and states the consequence', async () =>
    {
        const { container, unmount } = await mount(
            <Harness
                content={{
                    title: 'Delete the shipping zone?',
                    message: 'This removes the zone and its saved rates.',
                }}
                onResult={jest.fn()}
            />
        );

        await openDialog(container);

        const dialog = document.querySelector('[role=dialog]') as HTMLElement;
        expect(dialog).not.toBeNull();

        const labelledBy = dialog.getAttribute('aria-labelledby');
        expect(labelledBy).toBeTruthy();
        expect(document.getElementById(labelledBy as string)?.textContent).toBe('Delete the shipping zone?');
        expect(dialog.textContent).toContain('This removes the zone and its saved rates.');

        await unmount();
    });

    it('resolves true and closes when the primary action is chosen', async () =>
    {
        const onResult = jest.fn();
        const { container, unmount } = await mount(
            <Harness
                content={{
                    title: 'Publish the weekly schedule?',
                    message: 'Visitors will see the updated schedule right away.',
                    confirmLabel: 'Publish',
                }}
                onResult={onResult}
            />
        );

        await openDialog(container);
        await clickButton(findButtonByText('Publish'));

        expect(onResult).toHaveBeenCalledWith(true);
        expect(document.querySelector('[role=dialog]')).toBeNull();

        await unmount();
    });

    it('resolves false and closes when cancelled', async () =>
    {
        const onResult = jest.fn();
        const { container, unmount } = await mount(
            <Harness
                content={{
                    title: 'Discard the draft article?',
                    message: 'The unsaved article will be lost.',
                }}
                onResult={onResult}
            />
        );

        await openDialog(container);
        await clickButton(findButtonByText('Cancel'));

        expect(onResult).toHaveBeenCalledWith(false);
        expect(document.querySelector('[role=dialog]')).toBeNull();

        await unmount();
    });

    it('gives the confirm action destructive styling when requested', async () =>
    {
        const { container, unmount } = await mount(
            <Harness
                content={{
                    title: 'Remove the contact form?',
                    message: 'This deletes the form and its stored submissions.',
                    confirmLabel: 'Remove',
                    isDestructive: true,
                }}
                onResult={jest.fn()}
            />
        );

        await openDialog(container);

        expect(findButtonByText('Remove').classList.contains('is-destructive')).toBe(true);

        await unmount();
    });

    it('opens a single dialog when the trigger is double-clicked and declines the second request', async () =>
    {
        const results: boolean[] = [];
        const { container, unmount } = await mount(
            <Harness
                content={{
                    title: 'Retranslate this page in Japanese?',
                    message: 'This resends 42 segments for a fresh translation and replaces the cached version.',
                    confirmLabel: 'Retranslate',
                }}
                onResult={(confirmed) => results.push(confirmed)}
            />
        );

        const trigger = container.querySelector('#trigger') as HTMLButtonElement;
        await act(async () =>
        {
            trigger.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            trigger.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });

        expect(document.querySelectorAll('[role=dialog]')).toHaveLength(1);
        expect(results).toEqual([false]);

        await clickButton(findButtonByText('Retranslate'));

        expect(results).toEqual([false, true]);
        expect(document.querySelector('[role=dialog]')).toBeNull();

        await unmount();
    });
});
