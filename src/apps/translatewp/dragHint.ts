import { useEffect, useState } from '@wordpress/element';

// The first-time line that says the switcher can be dragged. Once the owner
// closes it, or has dragged the switcher once, it never comes back in this
// browser. Storage that cannot be read or written leaves it shown for this
// visit only.
const STORAGE_KEY = 'translatewp-drag-hint-done';
const listeners = new Set<() => void>();

const read = (): boolean =>
{
    try
    {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    }
    catch
    {
        return false;
    }
};

let done = read();

export const dragHint = {
    isDone: (): boolean => done,
    finish(): void
    {
        if (done) return;
        done = true;
        try
        {
            window.localStorage.setItem(STORAGE_KEY, '1');
        }
        catch
        {
            // Kept for this visit only.
        }
        for (const listener of listeners) listener();
    },
    /** For tests: forget the dismissal. */
    reset(): void
    {
        done = false;
        try
        {
            window.localStorage.removeItem(STORAGE_KEY);
        }
        catch
        {
            // Nothing to forget.
        }
    },
};

export function useDragHintDone(): boolean
{
    const [value, setValue] = useState(done);
    useEffect(() =>
    {
        const listener = () => setValue(done);
        listeners.add(listener);
        return () => { listeners.delete(listener); };
    }, []);
    return value;
}
