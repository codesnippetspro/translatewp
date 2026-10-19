import { useEffect, useState } from '@wordpress/element';

// A signed-in administrator's edit mode for the public page. Outside it the
// switcher is only a language control; inside it the floating switcher can be
// dragged and the editor actions are offered. Visitors never enter it.
let editing = false;
const listeners = new Set<() => void>();

export const editMode = {
    get: (): boolean => editing,
    set(next: boolean): void
    {
        if (editing === next) return;
        editing = next;
        for (const listener of listeners) listener();
    },
    toggle(): void
    {
        editMode.set(!editing);
    },
    subscribe(listener: () => void): () => void
    {
        listeners.add(listener);
        return () => { listeners.delete(listener); };
    },
};

export function useEditMode(): boolean
{
    const [value, setValue] = useState(editing);
    useEffect(() => editMode.subscribe(() => setValue(editing)), []);
    return value;
}

// Settings > Switcher links to the site with this parameter so an
// administrator lands on the page already in edit mode. The parameter is
// removed from the address once read; a visitor with the link is ignored.
export const EDIT_MODE_PARAM = 'twp-edit';

export function enterEditModeFromUrl(canManage: boolean): void
{
    const url = new URL(window.location.href);
    if (!url.searchParams.has(EDIT_MODE_PARAM)) return;
    url.searchParams.delete(EDIT_MODE_PARAM);
    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    if (canManage) editMode.set(true);
}
