import { useEffect, useRef } from '@wordpress/element';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

export const SAVE_DELAY_MS = 600;

const TEXT_INPUT_TYPES = ['text', 'number', 'email', 'url', 'search', 'tel', 'password'];

const isTextField = (element: Element | null): boolean =>
{
    if (element instanceof HTMLTextAreaElement) return true;
    if (element instanceof HTMLInputElement) return TEXT_INPUT_TYPES.includes(element.type);
    return element instanceof HTMLElement && element.isContentEditable;
};

const canSave = (): boolean =>
{
    const { hasChanges, staleSettings } = useAdminSettingsStore.getState();
    return hasChanges && !staleSettings;
};

/**
 * Runs `save` shortly after a setting changes. A change typed into a text
 * field waits until that field loses focus, or leaves the page while focused
 * (a closed drawer), so a half-typed value is never sent. Nothing saves once another tab has saved first, and a failed save is
 * not retried until the next change or an explicit retry.
 */
export function useSaveOnChange(save: () => Promise<void>): void
{
    const saveRef = useRef(save);
    saveRef.current = save;

    useEffect(() =>
    {
        let timer: ReturnType<typeof setTimeout> | undefined;
        let editedField: Element | null = null;
        let running = false;
        let watch: ReturnType<typeof setInterval> | undefined;

        const releaseField = () =>
        {
            editedField = null;
            clearInterval(watch);
            watch = undefined;
            schedule();
        };

        const releaseIfRemoved = () =>
        {
            if (editedField && !editedField.isConnected) releaseField();
        };

        const schedule = () =>
        {
            clearTimeout(timer);
            timer = setTimeout(() => void run(), SAVE_DELAY_MS);
        };

        const run = async () =>
        {
            timer = undefined;
            if (running || !canSave()) return;
            running = true;
            try {
                await saveRef.current();
            } finally {
                running = false;
            }
            if (canSave() && !useAdminSettingsStore.getState().saveFailed && !editedField) {
                schedule();
            }
        };

        const unsubscribe = useAdminSettingsStore.subscribe((state, previous) =>
        {
            if (state.settings === previous.settings || !state.hasChanges) return;

            const active = document.activeElement;
            if (isTextField(active) && active?.isConnected) {
                clearTimeout(timer);
                editedField = active;
                watch ??= setInterval(releaseIfRemoved, SAVE_DELAY_MS);
                return;
            }
            clearInterval(watch);
            watch = undefined;
            editedField = null;
            schedule();
        });

        const onFocusOut = (event: FocusEvent) =>
        {
            if (editedField && event.target === editedField) releaseField();
        };
        document.addEventListener('focusout', onFocusOut, true);
        document.addEventListener('focusin', releaseIfRemoved, true);

        return () =>
        {
            clearTimeout(timer);
            clearInterval(watch);
            unsubscribe();
            document.removeEventListener('focusout', onFocusOut, true);
            document.removeEventListener('focusin', releaseIfRemoved, true);
        };
    }, []);
}
