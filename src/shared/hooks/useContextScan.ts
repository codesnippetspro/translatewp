import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getScanContextStatus, scanContext, type ScanContextResponse, type ScanLexiconSuggestion } from '@/shared/api/setup';

const SCAN_POLL_INTERVAL_MS = 3000;
// A safety net only: the server's own worst case (one attempt, an immediate
// retry, and the status route running an overdue retry) stays below it, so the
// server's "failed" is the failure the owner normally sees.
const SCAN_POLL_TIMEOUT_MS = 300_000;
const ELAPSED_TICK_MS = 1000;

const secondsSince = (startedAt: number): number => Math.floor((Date.now() - startedAt) / 1000);

export type ScanResult = Partial<Pick<ScanContextResponse, 'style' | 'tone' | 'formality' | 'creativity' | 'language'>> & { context: string };

export type ScanProgress = { stage: 'collecting' | 'analysing'; sampled: number; total: number };

export type ScanPollState =
    | { phase: 'idle' }
    | { phase: 'scanning'; elapsedSeconds: number; progress: ScanProgress }
    | { phase: 'done'; context: string; result: ScanResult; applied: boolean; pages: number; lexiconSuggestions: ScanLexiconSuggestion[] }
    | { phase: 'failed'; message: string };

/**
 * The plain sentence for a scan failure code. The codes come from the scan
 * endpoint; anything unknown gets the generic line so a raw code never
 * reaches the screen.
 */
export function scanFailureMessage(reason: string | undefined): string
{
    switch (reason)
    {
        case 'no_scannable_content':
            return __('No published pages or posts were found to read. Publish some content, then scan again.', 'translate');
        case 'context_scan_unavailable':
            return __('The site could not reach the scan service. Check that the site can make outgoing requests, then scan again.', 'translate');
        case 'invalid_scan_response':
            return __('The scan service sent back an answer this site could not use. Scan again in a minute.', 'translate');
        case 'timed_out':
            return __('The scan is taking longer than usual. Scan again.', 'translate');
        case 'request_failed':
            return __('The scan could not be started. Reload the page and try again.', 'translate');
        default:
            return __('The website scan failed. Try again in a minute.', 'translate');
    }
}

/** The line a finished scan leaves behind, naming the pages it read. */
export function scanDoneMessage(pages: number, applied: boolean): string
{
    if (!applied)
    {
        return __('The scan filled in the context and style below. They are used once you confirm, which translates the site again.', 'translate');
    }
    if (pages > 0)
    {
        return sprintf(
            /* translators: %d: number of pages the scan read. */
            __('Done. The scan read %d pages and filled in the context, style and tone below.', 'translate'),
            pages,
        );
    }

    return __('Done. The scan filled in the context, style and tone below.', 'translate');
}

/** The running line: which page is being read, then the analysis with its elapsed time. */
export function scanProgressMessage(elapsedSeconds: number, progress?: ScanProgress): string
{
    if (progress && progress.stage === 'collecting' && progress.total > 0)
    {
        return sprintf(
            /* translators: 1: pages read so far, 2: pages to read. */
            __('Reading page %1$d of %2$d', 'translate'),
            Math.min(progress.sampled, progress.total),
            progress.total,
        );
    }
    if (progress && progress.stage === 'analysing')
    {
        return sprintf(
            /* translators: 1: number of pages sampled, 2: seconds elapsed. */
            __('Analysing %1$d pages, %2$d s', 'translate'),
            progress.total,
            elapsedSeconds,
        );
    }

    return sprintf(
        /* translators: %d: seconds elapsed since the scan started. */
        __('Reading your site, %d s', 'translate'),
        elapsedSeconds,
    );
}

export const useContextScan = () =>
{
    const [state, setState] = useState<ScanPollState>({ phase: 'idle' });
    const pollTimerRef = useRef<number | null>(null);
    const tickTimerRef = useRef<number | null>(null);
    const deadlineRef = useRef(0);
    const startedAtRef = useRef(0);
    // Seconds the server's scan is ahead of this page's clock (a scan that
    // started before a reload); the counter follows the server's figure.
    const serverAheadRef = useRef(0);

    const stopTimers = useCallback(() =>
    {
        if (pollTimerRef.current !== null)
        {
            window.clearTimeout(pollTimerRef.current);
            pollTimerRef.current = null;
        }
        if (tickTimerRef.current !== null)
        {
            window.clearInterval(tickTimerRef.current);
            tickTimerRef.current = null;
        }
    }, []);

    useEffect(() => stopTimers, [stopTimers]);

    const fail = useCallback((reason: string | undefined) =>
    {
        stopTimers();
        setState({ phase: 'failed', message: scanFailureMessage(reason) });
    }, [stopTimers]);

    const poll = useCallback(async () =>
    {
        if (Date.now() >= deadlineRef.current)
        {
            fail('timed_out');
            return;
        }

        try
        {
            const status = await getScanContextStatus();
            if (status.status === 'pending')
            {
                const progress: ScanProgress = {
                    stage: status.phase === 'analysing' ? 'analysing' : 'collecting',
                    sampled: status.sampled ?? 0,
                    total: status.total ?? 0,
                };
                serverAheadRef.current = Math.max(0, (status.elapsedSeconds ?? 0) - secondsSince(startedAtRef.current));
                const elapsedSeconds = secondsSince(startedAtRef.current) + serverAheadRef.current;
                setState(current => (
                    current.phase === 'scanning' ? { ...current, progress, elapsedSeconds } : current
                ));
            }
            if (status.status === 'done')
            {
                stopTimers();
                const context = status.context ?? '';
                setState(current => ({
                    phase: 'done',
                    context,
                    // A site that already holds translations gets the scan as a
                    // proposal: the options are untouched until the owner saves.
                    applied: status.applied !== false,
                    pages: current.phase === 'scanning' ? current.progress.total : 0,
                    lexiconSuggestions: status.lexiconSuggestions ?? [],
                    result: {
                        context,
                        language: status.language,
                        style: status.style,
                        tone: status.tone,
                        formality: status.formality,
                        creativity: status.creativity,
                    },
                }));
                return;
            }
            if (status.status === 'failed')
            {
                fail(status.reason);
                return;
            }
        }
        catch
        {
            fail(undefined);
            return;
        }

        pollTimerRef.current = window.setTimeout(() => { void poll(); }, SCAN_POLL_INTERVAL_MS);
    }, [fail, stopTimers]);

    const startScan = useCallback(async () =>
    {
        stopTimers();
        startedAtRef.current = Date.now();
        serverAheadRef.current = 0;
        deadlineRef.current = startedAtRef.current + SCAN_POLL_TIMEOUT_MS;
        setState({ phase: 'scanning', elapsedSeconds: 0, progress: { stage: 'collecting', sampled: 0, total: 0 } });
        tickTimerRef.current = window.setInterval(() =>
        {
            setState(current => (
                current.phase === 'scanning'
                    ? { ...current, elapsedSeconds: secondsSince(startedAtRef.current) + serverAheadRef.current }
                    : current
            ));
        }, ELAPSED_TICK_MS);

        try
        {
            await scanContext();
        }
        catch (error)
        {
            const code = (error as { code?: string } | null)?.code;
            fail(code === 'no_scannable_content' ? code : 'request_failed');
            return;
        }

        pollTimerRef.current = window.setTimeout(() => { void poll(); }, SCAN_POLL_INTERVAL_MS);
    }, [fail, poll, stopTimers]);

    return { state, startScan };
};
