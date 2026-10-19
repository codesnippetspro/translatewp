/**
 * The wizard's browser draft is stored per admin path: on a subdirectory
 * network every subsite shares one origin, and one subsite's draft must
 * never pre-fill another subsite's wizard. Every reader and every reset of
 * the draft goes through these two helpers so they can never disagree on
 * the key.
 */
export const setupDraftKey = (): string => `translatewp-setup:${window.location.pathname}`;

export const clearSetupDraft = (): void =>
{
    try
    {
        localStorage.removeItem(setupDraftKey());
    }
    catch
    {
        // Storage can be blocked; a draft that cannot be read cannot leak.
    }
};
