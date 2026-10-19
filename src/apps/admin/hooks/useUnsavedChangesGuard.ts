import { useEffect } from '@wordpress/element';

/**
 * While the settings draft differs from what is saved, leaving the page (a
 * link to another admin screen, a reload, closing the tab) asks the browser
 * to confirm, so unsaved language or setting edits are never dropped silently.
 */
export function useUnsavedChangesGuard(hasChanges: boolean): void
{
    useEffect(() =>
    {
        if (!hasChanges) return undefined;

        const warn = (event: BeforeUnloadEvent) =>
        {
            event.preventDefault();
            // Older browsers read the returnValue; the text itself is not shown.
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [hasChanges]);
}
