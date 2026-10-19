/**
 * A language switch that could not be served leaves the page consistent.
 * The runtime restores the source text before it translates, so when nothing
 * was translated (or the switch failed outright) the content on screen is the
 * source language: the lang attribute, the title, the remembered choice and
 * the address go back to the source language. A partial result keeps the new
 * language, since most of the page did switch and the rest keeps polling.
 */
export type SwitchOutcome = 'success' | 'partial' | 'failure';

export function switchRollbackTarget(outcome: SwitchOutcome | 'error', sourceLocale: string, newLocale: string): string | null
{
    if (outcome === 'failure' || outcome === 'error')
    {
        return sourceLocale === newLocale ? null : sourceLocale;
    }

    return null;
}
