import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SAVE_DELAY_MS, useSaveOnChange } from '@/apps/admin/hooks/useSaveOnChange';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;

const Saver = ({ save }: { save: () => Promise<void> }) =>
{
    useSaveOnChange(save);
    return null;
};

const render = (save: () => Promise<void>) =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root.render(<Saver save={save} />));
};

const change = () => act(() =>
{
    const { settings } = useAdminSettingsStore.getState();
    useAdminSettingsStore.setState({ settings: { ...settings }, hasChanges: true });
});

const flush = async (ms = 0) =>
{
    await act(async () =>
    {
        jest.advanceTimersByTime(ms);
        for (let i = 0; i < 10; i++) {
            await Promise.resolve();
        }
    });
};

describe('useSaveOnChange', () =>
{
    beforeEach(() =>
    {
        jest.useFakeTimers();
        useAdminSettingsStore.setState({ hasChanges: false, staleSettings: false, saveFailed: false });
    });

    afterEach(() =>
    {
        act(() => root.unmount());
        document.body.innerHTML = '';
        jest.useRealTimers();
    });

    it('sends a change made while a save is in flight once that save finishes', async () =>
    {
        let finish: () => void = () => undefined;
        const save = jest.fn(() => new Promise<void>(resolve =>
        {
            finish = resolve;
        }));
        render(save);

        change();
        await flush(SAVE_DELAY_MS);
        expect(save).toHaveBeenCalledTimes(1);

        change();
        await flush(SAVE_DELAY_MS * 2);
        expect(save).toHaveBeenCalledTimes(1);

        finish();
        await flush();
        await flush(SAVE_DELAY_MS);
        expect(save).toHaveBeenCalledTimes(2);
    });

    it('saves a text field edit when the field is removed from the page while focused', async () =>
    {
        const save = jest.fn(() => Promise.resolve());
        render(save);
        const field = document.createElement('input');
        field.type = 'text';
        document.body.appendChild(field);

        field.focus();
        change();
        await flush(SAVE_DELAY_MS * 2);
        expect(save).not.toHaveBeenCalled();

        act(() => field.remove());
        await flush(SAVE_DELAY_MS * 3);

        expect(save).toHaveBeenCalledTimes(1);
    });

    it('saves a removed text field edit as soon as focus lands elsewhere', async () =>
    {
        const save = jest.fn(() => Promise.resolve());
        render(save);
        const field = document.createElement('input');
        field.type = 'text';
        const button = document.createElement('button');
        document.body.append(field, button);

        field.focus();
        change();
        act(() =>
        {
            field.remove();
            button.focus();
        });
        await flush(SAVE_DELAY_MS);

        expect(save).toHaveBeenCalledTimes(1);
    });
});
