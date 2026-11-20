import { useEffect, useRef, useState } from '@wordpress/element';
import { runBackgroundStep, type BackgroundStep } from '@/shared/api/admin';

const STEP_INTERVAL_MS = 60_000;

export const useBackgroundStep = (paused = false, requestLeave?: () => Promise<boolean>) =>
{
    const [step, setStep] = useState<BackgroundStep>();
    const [failed, setFailed] = useState(false);
    const allowLeave = useRef(false);
    const pending = !paused && !!step && step.state !== 'paused'
        && step.pending.read + step.pending.push + step.pending.refill > 0;

    useEffect(() =>
    {
        if (paused) return;
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout> | undefined;
        const advance = async () =>
        {
            let stopped = false;
            try
            {
                const result = await runBackgroundStep(controller.signal);
                stopped = result.state === 'paused';
                if (!controller.signal.aborted) { setStep(result); setFailed(false); }
            }
            catch
            {
                if (!controller.signal.aborted) setFailed(true);
            }
            finally
            {
                if (!controller.signal.aborted && !stopped) timer = setTimeout(() => void advance(), STEP_INTERVAL_MS);
            }
        };
        void advance();
        return () => { controller.abort(); if (timer) clearTimeout(timer); };
    }, [paused]);

    useEffect(() =>
    {
        if (!pending) return;
        const leave = (event: BeforeUnloadEvent) => { if (!allowLeave.current) { event.preventDefault(); event.returnValue = ''; } };
        const leaveViaLink = async (event: MouseEvent) =>
        {
            const link = (event.target as Element | null)?.closest?.('a[href]');
            if (!link || link.getAttribute('target') === '_blank' || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.getAttribute('href')!, window.location.href);
            const stays = url.origin === window.location.origin && url.pathname === window.location.pathname
                && url.search === window.location.search && url.hash.startsWith('#indexing');
            if (stays) return;
            event.preventDefault();
            const confirmed = await requestLeave?.();
            if (confirmed)
            {
                allowLeave.current = true;
                window.location.assign(url.toString());
            }
        };
        window.addEventListener('beforeunload', leave);
        document.addEventListener('click', leaveViaLink);
        return () => { window.removeEventListener('beforeunload', leave); document.removeEventListener('click', leaveViaLink); };
    }, [pending, requestLeave]);

    const visibleStep: BackgroundStep | undefined = paused
        ? { state: 'paused', pending: step?.pending ?? { read: 0, push: 0, refill: 0, visitor: 0 }, v: 1 }
        : step;
    return { step: visibleStep, pending, failed: !paused && failed };
};
